<?php

namespace Tests\Feature;

use App\Http\LoginThrottle;
use App\Models\User;
use App\Services\AdminAccounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Hashing\HashManager;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** FX-A: V3-F3 (timing), V3-F8 (login rate limits), V3 notes (one email normalizer). */
class LoginHardeningTest extends TestCase
{
    use RefreshDatabase;

    private const PW = 'correct-horse-battery';

    private function makeUser(string $email, bool $active = true): User
    {
        $u = User::create(['name' => 'u', 'email' => $email, 'password' => self::PW]);
        $u->forceFill(['is_active' => $active])->save();

        return $u;
    }

    private function attempt(string $email, string $password, string $ip = '203.0.113.10')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->from('/login')
            ->post('/login', ['email' => $email, 'password' => $password]);
    }

    /** Swap in a hash manager that counts check() calls (and otherwise behaves normally). */
    private function countingHasher(): object
    {
        $hasher = new class($this->app) extends HashManager
        {
            public int $checks = 0;

            public function check($value, $hashedValue, array $options = [])
            {
                $this->checks++;

                return parent::check($value, $hashedValue, $options);
            }
        };
        Hash::swap($hasher);

        return $hasher;
    }

    // ---- V3-F3: same work and same reply for every failure ----

    public function test_unknown_email_runs_exactly_one_hash_check(): void
    {
        $hasher = $this->countingHasher();
        $this->attempt('nobody@example.test', self::PW)
            ->assertRedirect('/login')->assertSessionHasErrors(['email' => __('auth.failed')]);
        $this->assertGuest();
        $this->assertSame(1, $hasher->checks);
    }

    public function test_wrong_password_runs_exactly_one_hash_check(): void
    {
        $this->makeUser('a@example.test');
        $hasher = $this->countingHasher();
        $this->attempt('a@example.test', 'wrong-password-123')
            ->assertRedirect('/login')->assertSessionHasErrors(['email' => __('auth.failed')]);
        $this->assertGuest();
        $this->assertSame(1, $hasher->checks);
    }

    public function test_inactive_account_with_correct_password_runs_exactly_one_hash_check(): void
    {
        $this->makeUser('off@example.test', active: false);
        $hasher = $this->countingHasher();
        $this->attempt('off@example.test', self::PW)
            ->assertRedirect('/login')->assertSessionHasErrors(['email' => __('auth.failed')]);
        $this->assertGuest();
        $this->assertSame(1, $hasher->checks);
    }

    public function test_all_three_failures_return_the_same_error(): void
    {
        $this->makeUser('a@example.test');
        $this->makeUser('off@example.test', active: false);

        $errors = [];
        foreach ([['nobody@example.test', self::PW], ['a@example.test', 'wrong-password-123'], ['off@example.test', self::PW]] as $i => [$e, $p]) {
            $this->attempt($e, $p, '203.0.113.'.(20 + $i));
            $errors[] = session('errors')->get('email');
            $this->flushSession();
        }

        $this->assertSame([[__('auth.failed')], [__('auth.failed')], [__('auth.failed')]], $errors);
    }

    // ---- V3-F8: per-email and per-IP limits ----

    public function test_email_is_limited_to_ten_attempts_per_ten_minutes_across_ips(): void
    {
        $this->makeUser('a@example.test');
        for ($i = 0; $i < 10; $i++) {
            // Case and whitespace variants count against the same email.
            $email = $i % 2 ? ' A@Example.TEST ' : 'a@example.test';
            $this->attempt($email, 'wrong-password-123', '198.51.100.'.($i + 1))->assertRedirect('/login');
        }

        $this->attempt('a@example.test', self::PW, '198.51.100.99')->assertStatus(429);
        $this->assertGuest();

        $this->travel(11)->minutes();
        $this->attempt('a@example.test', self::PW, '198.51.100.99')->assertRedirect('/');
    }

    public function test_ip_is_limited_to_five_attempts_per_minute_across_emails(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->attempt("x{$i}@example.test", 'wrong-password-123', '203.0.113.50')->assertRedirect('/login');
        }
        $this->attempt('x9@example.test', 'wrong-password-123', '203.0.113.50')->assertStatus(429);
        $this->attempt('x9@example.test', 'wrong-password-123', '203.0.113.51')->assertRedirect('/login');
    }

    public function test_ipv6_clients_are_limited_per_64_prefix(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->attempt("y{$i}@example.test", 'wrong-password-123', '2001:db8:aa:bb::'.dechex($i + 1))->assertRedirect('/login');
        }
        // Same /64, different interface id: still limited.
        $this->attempt('y9@example.test', 'wrong-password-123', '2001:db8:aa:bb:ffff:1:2:3')->assertStatus(429);
        // Neighbouring /64: its own bucket.
        $this->attempt('y9@example.test', 'wrong-password-123', '2001:db8:aa:bc::1')->assertRedirect('/login');
    }

    public function test_ip_key_normalization(): void
    {
        $this->assertSame('203.0.113.7', LoginThrottle::ipKey('203.0.113.7'));
        $this->assertSame('2001:db8:aa:bb::/64', LoginThrottle::ipKey('2001:db8:aa:bb:1:2:3:4'));
        $this->assertSame('2001:db8:aa:bb::/64', LoginThrottle::ipKey('2001:0db8:00aa:00bb::9'));
        // IPv4-mapped addresses stay per IPv4 address instead of collapsing into one /64.
        $this->assertSame('203.0.113.7', LoginThrottle::ipKey('::ffff:203.0.113.7'));
        $this->assertSame('unknown', LoginThrottle::ipKey(null));
        $this->assertSame('a@example.test', LoginThrottle::emailKey(" A@Example.TEST \t"));
    }

    // ---- V3 notes: one email normalizer everywhere ----

    public function test_normalize_email_is_multibyte_aware(): void
    {
        $this->assertSame('äbc@example.test', app(AdminAccounts::class)->normalizeEmail('  ÄBC@Example.TEST '));
    }

    public function test_login_create_and_edit_share_the_same_normalization(): void
    {
        $admin = $this->makeUser('admin@example.test');
        $admin->forceFill(['is_admin' => true])->save();
        $raw = '  Ünï@Example.TEST ';
        $normalized = app(AdminAccounts::class)->normalizeEmail($raw);

        $this->actingAs($admin)->post('/users', [
            'name' => 'U', 'email' => $raw, 'password' => 'a-long-password-1', 'password_confirmation' => 'a-long-password-1',
            'is_admin' => '0', 'is_active' => '1',
        ])->assertRedirect('/users');
        $user = User::where('email', $normalized)->sole();

        $this->put("/users/{$user->id}", [
            'name' => 'U2', 'email' => mb_strtoupper($raw), 'password' => '', 'password_confirmation' => '',
            'is_admin' => '0', 'is_active' => '1',
        ])->assertRedirect('/users');
        $this->assertSame($normalized, $user->fresh()->email);

        $this->post('/logout');
        $this->attempt(mb_strtoupper($raw), 'a-long-password-1')->assertRedirect('/');
        $this->assertAuthenticatedAs($user->fresh());
    }
}
