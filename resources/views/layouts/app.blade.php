<!DOCTYPE html>
<html lang="{{ \App\Http\Middleware\SetLocale::htmlLang() }}">
<head>
    <meta charset="utf-8">
    @include('partials.theme-init')
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', config('app.name'))</title>
    @php($assetVersion = fn (string $path) => is_file(public_path($path)) ? '?v='.filemtime(public_path($path)) : '')
    <link rel="stylesheet" href="{{ asset('css/app.css') }}{{ $assetVersion('css/app.css') }}">
    <script src="{{ asset('js/theme.js') }}{{ $assetVersion('js/theme.js') }}" defer></script>
    @includeIf('partials.head-extra')
</head>
<body>
    <div class="wrap">
        @include('partials.header')
        @yield('content')
    </div>
    @include('partials.footer')
    @includeIf('partials.body-extra')
</body>
</html>
