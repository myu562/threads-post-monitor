@extends('layouts.app')

@section('title', __('users.create').' · '.config('app.name'))

@section('content')
<div class="card narrow">
    <h2 class="mt-0 title-lg">{{ __('users.create') }}</h2>
    <form method="POST" action="{{ route('users.store') }}">
        @csrf
        @include('users._form')
        <div class="actions mt-4">
            <button type="submit">{{ __('users.form.create') }}</button>
            <a href="{{ route('users.index') }}">{{ __('users.form.cancel') }}</a>
        </div>
    </form>
</div>
@endsection
