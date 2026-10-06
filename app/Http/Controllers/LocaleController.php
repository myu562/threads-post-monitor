<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/** The header language switch (contract L2): POST /locale, then back to the page it was pressed on. */
class LocaleController extends Controller
{
    /** One year, in minutes. */
    private const COOKIE_MINUTES = 525600;

    public function update(Request $request): RedirectResponse
    {
        $locale = $request->input('locale');
        if (! is_string($locale) || ! in_array($locale, SetLocale::SUPPORTED, true)) {
            abort(422);
        }

        $cookie = Cookie::create(SetLocale::COOKIE, $locale)
            ->withExpires(now()->addMinutes(self::COOKIE_MINUTES))
            ->withPath('/')
            ->withSecure($request->isSecure())
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_LAX);

        return redirect()->to(self::safeRedirect($request->input('redirect')))->withCookie($cookie);
    }

    /**
     * Only a same-site relative path is followed ("/", "/posts/3?x=1"). Anything that could leave the
     * site — "//host", "/\host", "https://…", backslashes, control characters — falls back to "/".
     */
    public static function safeRedirect(mixed $target): string
    {
        if (! is_string($target) || $target === '' || strlen($target) > 2048) {
            return '/';
        }
        if ($target[0] !== '/' || str_starts_with($target, '//') || str_contains($target, '\\')
            || preg_match('/[\x00-\x20\x7F]/', $target)) {
            return '/';
        }
        $parts = parse_url($target);
        if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user'])) {
            return '/';
        }

        return $target;
    }
}
