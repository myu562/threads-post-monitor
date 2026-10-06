<?php

namespace Tests\Feature;

use App\Auth\ThreadsUserProvider;
use App\Http\LoginThrottle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Hashing\HashManager;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * RA-F1: the login answer time must not depend on whether the email exists or the account is
 * active. RA-F8: email variants share one rate-limit budget.
 */
class LoginTimingTest extends TestCase
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

    public function test_users_provider_is_the_constant_work_provider(): void
    {
        $this->assertInstanceOf(ThreadsUserProvider::class, Auth::guard('web')->getProvider());
    }

    public function test_each_failure_runs_exactly_one_hash_check_inside_the_provider(): void
    {
        $this->makeUser('active@example.test');
        $this->makeUser('off@example.test', false);

        $cases = [
            'unknown email' => ['nobody@example.test', self::PW],
            'deactivated, right password' => ['off@example.test', self::PW],
            'deactivated, wrong password' => ['off@example.test', 'wrong-password-123'],
            'active, wrong password' => ['active@example.test', 'wrong-password-123'],
        ];
        // One counting hasher for all cases: the guard keeps the hasher it was created with.
        $hasher = $this->countingHasher();
        $i = 0;
        foreach ($cases as $label => [$email, $password]) {
            $hasher->checks = 0;
            $this->attempt($email, $password, '203.0.113.'.(++$i))
                ->assertRedirect('/login')->assertSessionHasErrors(['email' => __('auth.failed')]);
            $this->assertSame(1, $hasher->checks, $label);
            $this->assertGuest();
        }
    }

    public function test_the_controller_no_longer_hashes_outside_the_timebox(): void
    {
        $source = (string) file_get_contents(app_path('Http/Controllers/Auth/LoginController.php'));
        $this->assertStringNotContainsString('Hash::check', $source);
        $this->assertStringNotContainsString('dummyHash', $source);
    }

    public function test_success_still_signs_in_and_inactive_never_does(): void
    {
        $this->makeUser('active@example.test');
        $this->makeUser('off@example.test', false);

        $this->attempt('off@example.test', self::PW, '203.0.113.20')->assertRedirect('/login');
        $this->assertGuest();

        $this->attempt('Active@Example.test', self::PW, '203.0.113.21')->assertRedirect('/');
        $this->assertAuthenticated();
    }

    /**
     * Real bcrypt (cost 10), 5 requests per case: the medians may differ by less than 50 ms.
     * Before the fix an unknown email took about 450 ms against 250 ms for a known one.
     */
    #[Group('slow')]
    public function test_failure_timing_does_not_depend_on_the_account(): void
    {
        config(['hashing.bcrypt.rounds' => 10]);
        $this->app->forgetInstance('hash');
        Hash::clearResolvedInstance('hash');
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->makeUser('active@example.test');
        $this->makeUser('off@example.test', false);
        $this->assertStringStartsWith('$2y$10$', User::where('email', 'active@example.test')->value('password'));

        $cases = [
            'unknown' => ['nobody@example.test', self::PW],
            'inactive' => ['off@example.test', self::PW],
            'wrong' => ['active@example.test', 'wrong-password-123'],
        ];
        // Warm-up (autoloading, view compilation) is not part of the measurement.
        foreach ($cases as [$email, $password]) {
            $this->attempt($email, $password);
        }

        $medians = [];
        foreach ($cases as $label => [$email, $password]) {
            $times = [];
            for ($i = 0; $i < 5; $i++) {
                $t = hrtime(true);
                $this->attempt($email, $password)->assertSessionHasErrors('email');
                $times[] = (hrtime(true) - $t) / 1e6;
            }
            sort($times);
            $medians[$label] = $times[2];
        }

        $spread = max($medians) - min($medians);
        $this->assertLessThan(50, $spread, 'median ms per case: '.json_encode(array_map(fn ($m) => round($m, 1), $medians)));
    }

    // ---- RA-F8: one rate-limit budget per email, whatever the spelling ----

    public function test_email_key_folds_accents_case_width_and_spaces(): void
    {
        $base = LoginThrottle::emailKey('admin@example.test');
        foreach (['admín@example.test', 'ADMIN@example.test', ' Ádmin @example.test ', "\u{FF41}dmin@example.test", "a\u{0301}dmin@example.test"] as $variant) {
            $this->assertSame($base, LoginThrottle::emailKey($variant), $variant);
        }
    }

    public function test_accented_variant_shares_the_budget_of_the_real_account(): void
    {
        $this->makeUser('admin@example.test');

        // 5 attempts with the real spelling, 5 with the accented one, each from a different IP.
        for ($i = 0; $i < 10; $i++) {
            $email = $i % 2 === 0 ? 'admin@example.test' : 'admín@example.test';
            $this->attempt($email, 'wrong-password-123', '198.51.100.'.($i + 1))->assertRedirect('/login');
        }

        $this->attempt('admín@example.test', self::PW, '198.51.100.50')->assertStatus(429);
        $this->attempt('admin@example.test', self::PW, '198.51.100.51')->assertStatus(429);
        $this->assertGuest();
    }

    public function test_known_account_also_gets_a_budget_by_account_id(): void
    {
        $user = $this->makeUser('admin@example.test');
        $this->assertSame($user->id, LoginThrottle::accountId(' Admin@Example.test '));
        $this->assertNull(LoginThrottle::accountId('nobody@example.test'));
        $this->assertNull(LoginThrottle::accountId(''));

        $this->attempt('admin@example.test', 'wrong-password-123');
        $this->assertSame(1, RateLimiter::attempts(md5('login'.'login-account|'.$user->id)));
    }
}
