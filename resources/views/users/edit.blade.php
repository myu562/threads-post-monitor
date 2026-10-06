@extends('layouts.app')

@section('title', __('users.edit').' · '.config('app.name'))

@section('content')
<div class="card narrow">
    <h2 class="mt-0 title-lg">{{ __('users.edit') }}</h2>
    <form method="POST" action="{{ route('users.update', $user) }}">
        @csrf
        @method('PUT')
        @include('users._form')
        <div class="actions mt-4">
            <button type="submit">{{ __('users.form.save') }}</button>
            <a href="{{ route('users.index') }}">{{ __('users.form.cancel') }}</a>
        </div>
    </form>
</div>
@endsection
