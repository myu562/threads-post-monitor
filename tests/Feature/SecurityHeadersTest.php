<?php

namespace Tests\Feature;

use App\Http\Middleware\SecurityHeaders;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** RA-F15: security headers on every response, whatever web server is in front. */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    private const EXPECTED = [
        'X-Frame-Options' => 'SAMEORIGIN',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Content-Security-Policy' => "frame-ancestors 'self'",
    ];

    private function assertHeaders(TestResponse $response, string $label): void
    {
        foreach (self::EXPECTED as $name => $value) {
            $this->assertSame($value, $response->headers->get($name), "{$label}: {$name}");
        }
    }

    public function test_middleware_sets_exactly_the_expected_values(): void
    {
        $this->assertSame(self::EXPECTED, SecurityHeaders::HEADERS);
    }

    public function test_ok_redirect_not_found_and_too_many_requests_all_carry_the_headers(): void
    {
        $this->assertHeaders($this->get('/login')->assertOk(), '200 login');
        $this->assertHeaders($this->get('/')->assertRedirect('/login'), '302 guest');
        $this->assertHeaders($this->get('/no-such-page')->assertNotFound(), '404 unknown route');
        $this->assertHeaders($this->post('/locale', ['locale' => 'en'])->assertRedirect(), '302 locale');

        $user = User::create(['name' => 'u', 'email' => 'a@example.test', 'password' => 'correct-horse-battery']);
        $this->assertHeaders($this->actingAs($user)->get('/')->assertOk(), '200 list');
        $this->assertHeaders($this->actingAs($user)->get('/posts/999999')->assertNotFound(), '404 post');
        $this->assertHeaders($this->actingAs($user)->post('/groups', ['name' => 'x'])->assertRedirect(), '302 action');
        $this->app['auth']->forgetGuards();

        for ($i = 0; $i < 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])->post('/login', ['email' => "n{$i}@example.test", 'password' => 'not-my-password']);
        }
        $this->assertHeaders(
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])->post('/login', ['email' => 'n9@example.test', 'password' => 'not-my-password'])->assertStatus(429),
            '429 login'
        );
    }

    public function test_health_check_and_validation_errors_too(): void
    {
        $this->assertHeaders($this->get('/up')->assertOk(), '200 /up');
        $this->assertHeaders($this->post('/locale', ['locale' => 'xx'])->assertStatus(422), '422');
    }
}
