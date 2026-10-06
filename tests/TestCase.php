<?php

namespace Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Accept-Language sent with every request of the test class. Tests that check the Chinese
     * interface text set it to 'zh-TW'; null keeps the test client's default ("en-us,en;q=0.5").
     */
    protected ?string $acceptLanguage = null;

    /** Locale environment variables cleared during each test, so the CLI language never depends on the shell. */
    private const LOCALE_ENV = ['LC_ALL', 'LC_MESSAGES', 'LANG'];

    /** @var array<string, string|false> */
    private array $savedLocaleEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::LOCALE_ENV as $name) {
            $this->savedLocaleEnv[$name] = getenv($name);
            putenv($name);
        }

        if ($this->acceptLanguage !== null) {
            $this->withHeader('Accept-Language', $this->acceptLanguage);
            // Code called directly (models, views outside a request) uses the same language.
            $this->app->setLocale(\App\Http\Middleware\SetLocale::fromAcceptLanguage($this->acceptLanguage) ?? 'en');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedLocaleEnv as $name => $value) {
            $value === false ? putenv($name) : putenv($name.'='.$value);
        }

        parent::tearDown();
    }

    /**
     * Switching to a different user inside one test starts a fresh session, as a separate browser
     * would. Otherwise AuthenticateSession (auth.session) sees the previous user's password hash
     * in the shared test session and logs the new user out.
     */
    public function be(Authenticatable $user, $guard = null)
    {
        $current = $this->app['auth']->guard($guard)->user();
        if ($current !== null && $current->getAuthIdentifier() !== $user->getAuthIdentifier()) {
            $this->flushSession();
        }

        return parent::be($user, $guard);
    }
}
