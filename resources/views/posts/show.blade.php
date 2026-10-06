@extends('layouts.app')

@section('title', ($post->username ? '@'.$post->username : $post->shortcode).' · '.config('app.name'))

@section('content')
@php($m = $post->displayMetrics())
@php($trackHours = (int) config('threads.track_hours', 72))
<p class="m-0 mb-3"><a href="{{ route('posts.index') }}">{{ __('posts.back') }}</a></p>
@if (session('status'))
    <div class="flash" role="status">{{ session('status') }}</div>
@endif

<div class="card">
    <div class="post-head">
        <div>
            <strong class="title-lg">{{ $post->username ? '@'.$post->username : __('posts.unknown_account') }}</strong>
            @if ($post->username && ! $post->isUsernameVerified()) <span class="tag" title="{{ __('posts.unverified_account_title') }}">{{ __('posts.unverified_account') }}</span> @endif
            <a href="{{ \App\Threads\ThreadsUrl::canonical($post->shortcode) }}" target="_blank" rel="noopener noreferrer" class="muted text-sm">{{ __('posts.open_post') }}</a>
        </div>
        <span class="status status-{{ $post->status }}">{{ $post->statusLabel() }}</span>
    </div>

    <div class="meta">
        <span>{{ __('posts.meta.published', ['time' => \App\Models\TrackedPost::localTime($post->published_at)]) }}
            @if ($post->isPublishedAtEstimated()) <span class="tag" title="{{ __('posts.meta.estimated_title') }}">{{ __('posts.meta.estimated') }}</span> @endif
        </span>
        <span class="muted">{{ __('posts.meta.exact', ['time' => \App\Models\TrackedPost::localTime($post->published_at, 'Y-m-d H:i:s')]) }}</span>
        <span>{{ __('posts.meta.age', ['value' => ($h = $post->hoursSincePublish()) === null ? '—' : trans_choice('posts.hours', $h)]) }}</span>
        <span>{{ __('posts.meta.track_until', ['time' => \App\Models\TrackedPost::localTime($post->track_until, 'Y-m-d H:i')]) }}</span>
        <span>{{ __('posts.meta.next_poll', ['time' => in_array($post->status, ['pending', 'tracking'], true) ? \App\Models\TrackedPost::localTime($post->next_poll_at, 'Y-m-d H:i') : '—']) }}</span>
        <span>{{ __('posts.meta.last_poll', ['time' => \App\Models\TrackedPost::localTime($post->last_captured_at, 'Y-m-d H:i:s')]) }}</span>
    </div>

    @if ($post->added_after_window)
        <div class="meta"><span class="tag warn">{{ __('posts.warn.added_late', ['hours' => $trackHours]) }}</span></div>
    @endif
    @if ($post->status === 'done' && $post->finalCapturedOffWindow())
        <div class="meta"><span class="tag warn">{{ __('posts.warn.final_off', ['time' => \App\Models\TrackedPost::localTime($post->final_captured_at, 'Y-m-d H:i')]) }}</span></div>
    @endif

    <div class="meta"><a class="tag-chip" href="{{ route('posts.index', ['group' => $entry->group_id ?? 'none']) }}">{{ __('posts.filters.group_chip', ['group' => $entry->group?->name ?? __('posts.groups.ungrouped')]) }}</a></div>
    @include('posts._tags', ['tags' => $entry->tags])

    @if ($post->caption !== null)
        @php($captionLimit = 500)
        @if (mb_strlen($post->caption) > $captionLimit)
            <details class="caption-fold">
                <summary>
                    <span class="caption caption-preview">{{ mb_substr($post->caption, 0, $captionLimit) }}…</span>
                    <span class="caption-toggle when-closed">{{ __('posts.show.caption_expand', ['count' => mb_strlen($post->caption)]) }}</span>
                    <span class="caption-toggle when-open">{{ __('posts.show.caption_collapse') }}</span>
                </summary>
                <div class="caption">{{ $post->caption }}</div>
            </details>
        @else
            <div class="caption">{{ $post->caption }}</div>
        @endif
    @endif

    <div class="meta"><span class="tag">{{ $m['label'] }}</span>
        @if ($m['captured_at']) <span class="muted text-xs">{{ __('posts.meta.captured', ['time' => \App\Models\TrackedPost::localTime($m['captured_at'], 'Y-m-d H:i:s')]) }}</span> @endif
    </div>
    <div class="metrics">
        @foreach (\App\Models\TrackedPost::METRICS as $k)
            <div class="metric">
                <div class="k">{{ \App\Models\TrackedPost::metricLabel($k) }}</div>
                <div class="v {{ $m['values'][$k] === null ? 'na' : '' }}">{{ $post->formatMetric($m['values'][$k], $k) }}</div>
            </div>
        @endforeach
    </div>

    @if ($post->last_error)
        <div class="err-box">{{ __('posts.error.line_with_count', ['label' => $post->status === 'failed' ? __('posts.error.failure_reason') : __('posts.error.last_error'), 'count' => $post->consecutive_failures, 'error' => $post->lastErrorText()]) }}</div>
    @endif
</div>

<div class="card section" id="edit">
    <h2 class="m-0 mb-2 title-md">{{ __('posts.edit.section') }}</h2>
    <p class="muted text-xs m-0 mb-3">{{ __('posts.tags.private') }}</p>
    @include('posts._meta-form', ['post' => $post, 'entry' => $entry, 'tags' => $entry->tags, 'groups' => $groups,
        'editing' => true, 'idPrefix' => '', 'redirect' => route('posts.show', $post, false)])
</div>

<div class="card section">
    <h2 class="m-0 mb-2 title-md">{{ __('posts.show.chart_title') }}</h2>
    @if ($chart)
        <svg class="chart" viewBox="0 0 {{ $chart['w'] }} {{ $chart['h'] }}" role="img" aria-label="{{ __('posts.show.chart_label') }}">
            @foreach ($chart['yTicks'] as $t)
                <line class="grid" x1="{{ $chart['pad']['l'] }}" x2="{{ $chart['w'] - $chart['pad']['r'] }}" y1="{{ $t['y'] }}" y2="{{ $t['y'] }}"/>
                <text x="{{ $chart['pad']['l'] - 6 }}" y="{{ $t['y'] + 4 }}" text-anchor="end">{{ $t['label'] }}</text>
            @endforeach
            <line class="axis" x1="{{ $chart['pad']['l'] }}" x2="{{ $chart['w'] - $chart['pad']['r'] }}" y1="{{ $chart['h'] - $chart['pad']['b'] }}" y2="{{ $chart['h'] - $chart['pad']['b'] }}"/>
            @foreach ($chart['xTicks'] as $t)
                <text x="{{ $t['x'] }}" y="{{ $chart['h'] - $chart['pad']['b'] + 16 }}" text-anchor="middle">{{ $t['label'] }}</text>
            @endforeach
            <polyline class="line" points="{{ $chart['points'] }}"/>
            @foreach ($chart['dots'] as $d)
                <circle class="dot" cx="{{ $d['x'] }}" cy="{{ $d['y'] }}" r="3"><title>{{ __('posts.show.chart_dot', ['hour' => $d['hour'], 'views' => number_format($d['views'])]) }}</title></circle>
            @endforeach
        </svg>
        <p class="muted text-xs m-0 mt-1">{{ __('posts.show.chart_note') }}</p>
    @else
        <p class="muted m-0">{{ __('posts.show.chart_empty') }}</p>
    @endif
</div>

<div class="card section">
    <h2 class="m-0 mb-2 title-md">{{ trans_choice('posts.show.log_title', $snapshots->count()) }}</h2>
    @if ($snapshots->isEmpty())
        <p class="muted m-0">{{ __('posts.show.log_empty') }}</p>
    @else
        <div class="scroll-x">
            <table>
                <thead>
                    <tr>
                        <th>{{ __('posts.history.captured_at') }}</th>
                        <th>{{ __('posts.show.hours_col') }}</th>
                        @foreach (\App\Models\TrackedPost::METRICS as $k)
                            <th>{{ \App\Models\TrackedPost::metricLabel($k) }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach (\App\Threads\SnapshotDeltas::for($snapshots) as $row)
                        @php($s = $row['snapshot'])
                        <tr>
                            <td>{{ \App\Models\TrackedPost::localTime($s->captured_at, 'Y-m-d H:i:s') }}</td>
                            <td>{{ $s->hours_since_publish === null ? '—' : $s->hours_since_publish }}
                                @if ($row['interval_hours'] !== null)
                                    <div class="delta delta-flat">{{ __('posts.show.interval', ['hours' => rtrim(rtrim(number_format($row['interval_hours'], 2, '.', ''), '0'), '.')]) }}</div>
                                @endif
                            </td>
                            @foreach (\App\Models\TrackedPost::METRICS as $k)
                                <td class="{{ $s->{$k} === null ? 'na' : '' }}">{{ $post->formatMetric($s->{$k}, $k) }}
                                    @include('posts._delta', ['delta' => $row['delta'][$k], 'perHour' => $row['per_hour'][$k]])
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="muted text-xs m-0 mt-1">{{ __('posts.show.log_note') }}</p>
    @endif
</div>

<div class="section text-right">
    @include('posts._untrack', ['post' => $post, 'small' => false])
</div>
@include('posts._list-script')
@endsection
