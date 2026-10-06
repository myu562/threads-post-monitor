@extends('layouts.app')

@section('title', __('ui.password.title').' · '.config('app.name'))

@section('content')
@if (session('status'))
    <div class="flash" role="status">{{ session('status') }}</div>
@endif

<div class="card narrow">
    <h2 class="mt-0 title-lg">{{ __('ui.password.title') }}</h2>
    <form method="POST" action="{{ route('account.password.update') }}">
        @csrf
        @method('PUT')
        <label for="current_password">{{ __('ui.password.current') }}</label>
        <input id="current_password" type="password" name="current_password" required autocomplete="current-password">
        @error('current_password') <div class="error">{{ $message }}</div> @enderror

        <label for="password">{{ __('ui.password.new') }}</label>
        <input id="password" type="password" name="password" required minlength="12" autocomplete="new-password">
        <div class="hint">{{ __('ui.password.hint', ['min' => 12]) }}</div>
        @error('password') <div class="error">{{ $message }}</div> @enderror

        <label for="password_confirmation">{{ __('ui.password.confirm') }}</label>
        <input id="password_confirmation" type="password" name="password_confirmation" required minlength="12" autocomplete="new-password">

        <div class="mt-4"><button type="submit">{{ __('ui.password.submit') }}</button></div>
    </form>
</div>
@endsection
