<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureAdmin::class,
            'active' => \App\Http\Middleware\EnsureActiveUser::class,
        ]);
        // Interface language for every request, error pages included (contract L1). The cookie only
        // holds "zh_TW" or "en", so it is not encrypted.
        $middleware->append(\App\Http\Middleware\SetLocale::class);
        // X-Frame-Options, nosniff, Referrer-Policy, CSP frame-ancestors on every response (RA-F15).
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        $middleware->encryptCookies(except: [\App\Http\Middleware\SetLocale::COOKIE]);
        // Check admin rights before route model binding, so non-admins get 403 for /users/{any id}
        // and cannot probe which user ids exist (404 vs 403).
        $middleware->prependToPriorityList(
            before: \Illuminate\Routing\Middleware\SubstituteBindings::class,
            prepend: \App\Http\Middleware\EnsureAdmin::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
