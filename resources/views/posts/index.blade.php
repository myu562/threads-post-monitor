@extends('layouts.app')

@section('title', __('posts.title').' · '.config('app.name'))

@section('content')
@php($trackHours = (int) config('threads.track_hours', 72))
@if (session('status'))
    <div class="flash" role="status">{{ session('status') }}</div>
@endif

<div class="card">
    <form method="POST" action="{{ route('posts.store') }}">
        @csrf
        <label for="url" class="mt-0">{{ __('posts.add.label') }}</label>
        <div class="row">
            <div class="grow">
                <input id="url" type="url" name="url" value="{{ old('url') }}" required inputmode="url"
                       placeholder="{{ __('posts.add.placeholder') }}" autocomplete="off">
                <div class="muted text-xs mt-1">{{ __('posts.add.hint') }}</div>
                @error('url') <div class="error">{{ $message }}</div> @enderror
            </div>
            <button type="submit">{{ __('posts.add.submit') }}</button>
        </div>
    </form>
</div>

@if ($filtered || $posts->isNotEmpty())
    @include('posts._filters')
@endif

@include('posts._groups-manage')

<section class="list" data-group-list{!! $filtered ? ' data-filtered' : '' !!} aria-label="{{ __('posts.groups.sections_label') }}">
    @if ($filtered && $posts->isNotEmpty())
        <p class="muted text-sm m-0">{{ trans_choice('posts.list.found', $posts->count()) }}</p>
    @endif
    @foreach ($sections as $section)
        @php($open = $section['open'] || ((int) session('meta_post') > 0 && $section['posts']->contains('id', (int) session('meta_post'))))
        <details class="group-section" data-group-key="{{ $section['key'] }}"{!! $open ? ' open' : '' !!}>
            <summary>
                <span class="group-name">{{ $section['name'] }}</span>
                <span class="group-count">{{ trans_choice('posts.groups.count', $section['posts']->count()) }}</span>
            </summary>
            <div class="group-body">
                @forelse ($section['posts'] as $post)
                    @include('posts._card', ['post' => $post])
                @empty
                    <p class="muted text-sm m-0">{{ __('posts.groups.empty_section') }}</p>
                @endforelse
            </div>
        </details>
    @endforeach
    @if ($posts->isEmpty())
        @if ($filtered)
            <div class="card"><p class="muted m-0">{!! __('posts.list.empty_filtered', ['link' => '<a href="'.e(route('posts.index')).'">'.e(__('posts.filters.clear')).'</a>']) !!}</p></div>
        @else
            <div class="card"><p class="muted m-0">{{ __('posts.list.empty') }}</p></div>
        @endif
    @endif
</section>

@include('posts._list-script')
@endsection
