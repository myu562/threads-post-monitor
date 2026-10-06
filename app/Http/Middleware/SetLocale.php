<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Picks the interface language for every request (contract L1), in this order:
 *  1. the "locale" cookie set by the header switch (only zh_TW or en are accepted),
 *  2. the browser's Accept-Language, by q value: the first entry starting with "zh" gives zh_TW,
 *     any other language listed before it gives en,
 *  3. config('app.locale') (APP_LOCALE) as it was when the app booted, with en for anything that
 *     is not zh_*. App::setLocale() overwrites config('app.locale') on every request, so the
 *     configured value is kept as config('app.default_locale') by AppServiceProvider.
 *
 * Registered as global middleware so error pages (404 for unknown paths) get it too. The cookie is
 * excluded from cookie encryption (bootstrap/app.php): it only ever holds one of two fixed words.
 */
class SetLocale
{
    public const COOKIE = 'locale';

    public const SUPPORTED = ['zh_TW', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale(self::resolve($request));

        return $next($request);
    }

    public static function resolve(Request $request): string
    {
        $cookie = $request->cookies->get(self::COOKIE);
        if (is_string($cookie) && in_array($cookie, self::SUPPORTED, true)) {
            return $cookie;
        }

        $header = $request->headers->get('Accept-Language');
        if (is_string($header) && ($fromHeader = self::fromAcceptLanguage($header)) !== null) {
            return $fromHeader;
        }

        return self::normalize((string) config('app.default_locale', config('app.locale')));
    }

    /**
     * zh_TW or en from an Accept-Language header, or null when it names no language at all.
     * Entries are ordered by q (highest first, header order on ties); q=0 entries and "*" are skipped.
     */
    public static function fromAcceptLanguage(string $header): ?string
    {
        $entries = [];
        foreach (explode(',', $header) as $i => $part) {
            $pieces = explode(';', trim($part));
            $tag = strtolower(trim($pieces[0]));
            if ($tag === '' || $tag === '*' || ! preg_match('/^[a-z]{1,8}(-[a-z0-9]{1,8})*$/', $tag)) {
                continue;
            }
            $q = 1.0;
            foreach (array_slice($pieces, 1) as $param) {
                if (preg_match('/^\s*q\s*=\s*([0-9.]+)\s*$/i', $param, $m)) {
                    $q = (float) $m[1];
                }
            }
            if ($q <= 0) {
                continue;
            }
            $entries[] = ['tag' => $tag, 'q' => $q, 'i' => $i];
        }
        if ($entries === []) {
            return null;
        }

        usort($entries, fn ($a, $b) => [$b['q'], $a['i']] <=> [$a['q'], $b['i']]);

        return str_starts_with($entries[0]['tag'], 'zh') ? 'zh_TW' : 'en';
    }

    /** Any zh / zh_* / zh-* value becomes zh_TW; everything else en. */
    public static function normalize(string $locale): string
    {
        return str_starts_with(strtolower($locale), 'zh') ? 'zh_TW' : 'en';
    }

    /** Value for <html lang>. */
    public static function htmlLang(?string $locale = null): string
    {
        return ($locale ?? App::getLocale()) === 'zh_TW' ? 'zh-Hant-TW' : 'en';
    }
}
