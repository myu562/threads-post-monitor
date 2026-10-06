{{-- Error pages in the visitor's language. Deliberately standalone (no header, no forms): a 404 for an
     unknown path is rendered without a session, so nothing here may need one. --}}
<!DOCTYPE html>
<html lang="{{ \App\Http\Middleware\SetLocale::htmlLang() }}">
<head>
    <meta charset="utf-8">
    @include('partials.theme-init')
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('ui.errors.'.$code.'.title') }} · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
    <div class="wrap">
        <div class="card narrow">
            <h1 class="mt-0 title-lg">{{ __('ui.errors.'.$code.'.title') }}</h1>
            <p>{{ $message }}</p>
            <p class="m-0"><a href="{{ url('/') }}">{{ __('ui.errors.home') }}</a></p>
        </div>
    </div>
</body>
</html>
