@extends('layouts.app')

@section('title', __('users.title').' · '.config('app.name'))

@section('content')
@if (session('status'))
    <div class="flash" role="status">{{ session('status') }}</div>
@endif
@error('guard') <div class="err-box mt-0 mb-3" role="alert">{{ $message }}</div> @enderror

<div class="card">
    <div class="post-head">
        <h2 class="m-0 title-lg">{{ __('users.title') }}</h2>
        <a class="btn-link" href="{{ route('users.create') }}">{{ __('users.create') }}</a>
    </div>
    <p class="muted text-sm">{{ __('users.intro') }}</p>

    <div class="scroll-x">
        <table>
            <thead>
                <tr>
                    <th>{{ __('users.cols.email') }}</th>
                    <th>{{ __('users.cols.name') }}</th>
                    <th>{{ __('users.cols.admin') }}</th>
                    <th>{{ __('users.cols.status') }}</th>
                    <th>{{ __('users.cols.tracked') }}</th>
                    <th>{{ __('users.cols.created') }}</th>
                    <th>{{ __('users.cols.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($users as $u)
                    <tr data-user="{{ $u->id }}">
                        <td>{{ $u->email }}@if ($u->is(auth()->user())) <span class="tag">{{ __('users.you') }}</span>@endif</td>
                        <td>{{ $u->name }}</td>
                        <td>{{ $u->is_admin ? __('users.yes') : '—' }}</td>
                        <td>@if ($u->is_active)<span class="status status-done">{{ __('users.active') }}</span>@else<span class="status status-failed">{{ __('users.inactive') }}</span>@endif</td>
                        <td>{{ number_format($u->tracked_posts_count) }}</td>
                        <td>{{ \App\Models\TrackedPost::localTime($u->created_at, 'Y-m-d H:i') }}</td>
                        <td>
                            <div class="actions end">
                                <a href="{{ route('users.edit', $u) }}">{{ __('users.edit_link') }}</a>
                                @unless ($u->is(auth()->user()))
                                    {{-- The email goes into an HTML-escaped data attribute, never into JavaScript source. --}}
                                    <form method="POST" action="{{ route('users.destroy', $u) }}"
                                          data-confirm="{{ __('users.delete_confirm', ['email' => $u->email]) }}" onsubmit="return confirm(this.dataset.confirm);">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="danger small">{{ __('users.delete') }}</button>
                                    </form>
                                @endunless
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
