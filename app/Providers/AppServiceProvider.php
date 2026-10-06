<?php

namespace App\Providers;

use App\Auth\ThreadsUserProvider;
use App\Database\UnicodeSQLiteConnector;
use App\Http\LoginThrottle;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The configured interface language (APP_LOCALE), before any request switches it
        // (see App\Http\Middleware\SetLocale).
        config(['app.default_locale' => config('app.locale')]);

        // Unicode lower() on SQLite connections, so searches fold É/é and Α/α (MySQL is unaffected).
        $this->app->bind('db.connector.sqlite', UnicodeSQLiteConnector::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        LoginThrottle::register();

        Auth::provider('threads', fn ($app, array $config) => new ThreadsUserProvider($app['hash'], $config['model']));

        View::composer('partials.footer', function ($view): void {
            $view->with('footer', self::footer((array) config('threads.footer', [])));
        });
    }

    /**
     * Footer credit line from config('threads.footer').
     *
     * Returns null when the footer is turned off or has no text. The url is kept only when it is
     * an absolute http(s) URL with a host; any other value (javascript:, data:, relative paths…)
     * becomes null so the text is shown without a link.
     *
     * @param  array<string, mixed>  $config
     * @return array{text: string, url: string|null}|null
     */
    public static function footer(array $config): ?array
    {
        $enabled = $config['enabled'] ?? true;
        if (! is_bool($enabled)) {
            $enabled = filter_var($enabled, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
        }

        $text = trim((string) ($config['text'] ?? ''));
        if (! $enabled || $text === '') {
            return null;
        }

        return ['text' => $text, 'url' => self::safeHttpUrl($config['url'] ?? null)];
    }

    private static function safeHttpUrl(mixed $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        $url = trim($url);
        // Control characters or whitespace inside the value are never part of a link we want.
        if ($url === '' || preg_match('/[\x00-\x20\x7F]/', $url)) {
            return null;
        }

        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            return null;
        }

        return in_array(strtolower($parts['scheme']), ['http', 'https'], true) ? $url : null;
    }
}
