<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Contract G5: config('session.secure') follows SESSION_SECURE_COOKIE, else an https:// APP_URL. */
class SessionSecureCookieTest extends TestCase
{
    private const VARS = ['SESSION_SECURE_COOKIE', 'APP_URL'];

    /** @var array<string, array{0: string|false, 1: mixed, 2: mixed}> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::VARS as $name) {
            $this->saved[$name] = [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null];
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => [$env, $envArr, $server]) {
            $this->setVar($name, $env === false ? null : $env);
            if ($envArr === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $envArr;
            }
            if ($server === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $server;
            }
        }
        parent::tearDown();
    }

    private function setVar(string $name, ?string $value): void
    {
        if ($value === null) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        } else {
            putenv($name.'='.$value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }

    private function secure(?string $flag, ?string $appUrl): mixed
    {
        $this->setVar('SESSION_SECURE_COOKIE', $flag);
        $this->setVar('APP_URL', $appUrl);

        return (require config_path('session.php'))['secure'];
    }

    public function test_explicit_setting_wins(): void
    {
        $this->assertTrue($this->secure('true', 'http://example.test'));
        $this->assertFalse($this->secure('false', 'https://example.test'));
        $this->assertTrue($this->secure('true', 'https://example.test'));
    }

    public function test_unset_follows_the_app_url_scheme(): void
    {
        $this->assertTrue($this->secure(null, 'https://monitor.example.test'));
        $this->assertTrue($this->secure(null, 'HTTPS://monitor.example.test'));
        $this->assertFalse($this->secure(null, 'http://monitor.example.test'));
        $this->assertFalse($this->secure(null, 'http://https.example.test'));
    }

    public function test_unset_without_app_url_is_not_secure(): void
    {
        $this->assertFalse($this->secure(null, null));
        $this->assertFalse($this->secure(null, ''));
    }
}
