@extends('layouts.app')

@section('title', __('ui.auth.title').' · '.config('app.name'))

@section('content')
<div class="card auth">
    <h1 class="mt-0 title-xl">{{ config('app.name') }}</h1>
    <form method="POST" action="{{ route('login.attempt') }}">
        @csrf
        <label for="email">{{ __('ui.auth.email') }}</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
        @error('email') <div class="error">{{ $message }}</div> @enderror

        <label for="password">{{ __('ui.auth.password') }}</label>
        <input id="password" type="password" name="password" required autocomplete="current-password">
        @error('password') <div class="error">{{ $message }}</div> @enderror

        <label class="check">
            <input type="checkbox" name="remember" value="1"> {{ __('ui.auth.remember') }}
        </label>

        <div class="mt-4"><button type="submit">{{ __('ui.auth.submit') }}</button></div>
    </form>
</div>
@endsection
