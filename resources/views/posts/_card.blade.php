{{-- One post in the list (inside its group section). --}}
@php($m = $post->displayMetrics())
<article class="card post-card" id="post-{{ $post->id }}" data-post="{{ $post->id }}">
    <div class="post-head">
        <div>
            <a href="{{ route('posts.show', $post) }}"><strong>{{ $post->username ? '@'.$post->username : __('posts.unknown_account') }}</strong></a>
            @if ($post->username && ! $post->isUsernameVerified()) <span class="tag" title="{{ __('posts.unverified_account_title') }}">{{ __('posts.unverified_account') }}</span> @endif
            <a href="{{ \App\Threads\ThreadsUrl::canonical($post->shortcode) }}" target="_blank" rel="noopener noreferrer" class="muted text-sm">{{ __('posts.open_post') }}</a>
        </div>
        <span class="status status-{{ $post->status }}">{{ $post->statusLabel() }}</span>
    </div>

    @include('posts._tags', ['tags' => $post->pivot->tags])

    @if ($post->caption !== null)
        <p class="caption-snippet">{{ \Illuminate\Support\Str::limit(preg_replace('/\s+/u', ' ', $post->caption), 140) }}</p>
    @endif

    <div class="meta">
        <span>{{ __('posts.meta.published', ['time' => \App\Models\TrackedPost::localTime($post->published_at)]) }}
            @if ($post->isPublishedAtEstimated()) <span class="tag" title="{{ __('posts.meta.estimated_title') }}">{{ __('posts.meta.estimated') }}</span> @endif
        </span>
        <span>{{ __('posts.meta.age', ['value' => ($h = $post->hoursSincePublish()) === null ? '—' : trans_choice('posts.hours', $h)]) }}</span>
        <span>{{ __('posts.meta.next_poll', ['time' => in_array($post->status, ['pending', 'tracking'], true) ? \App\Models\TrackedPost::localTime($post->next_poll_at, 'Y-m-d H:i') : '—']) }}</span>
    </div>

    @if ($post->added_after_window)
        <div class="meta"><span class="tag warn">{{ __('posts.warn.added_late', ['hours' => $trackHours]) }}</span></div>
    @endif
    @if ($post->status === 'done' && $post->finalCapturedOffWindow())
        <div class="meta"><span class="tag warn">{{ __('posts.warn.final_off', ['time' => \App\Models\TrackedPost::localTime($post->final_captured_at, 'Y-m-d H:i')]) }}</span></div>
    @endif

    <div class="meta"><span class="tag">{{ $m['label'] }}</span>
        @if ($m['captured_at']) <span class="muted text-xs">{{ __('posts.meta.captured', ['time' => \App\Models\TrackedPost::localTime($m['captured_at'], 'Y-m-d H:i')]) }}</span> @endif
    </div>
    <div class="metrics">
        @foreach (['views', 'replies', 'reposts', 'shares'] as $k)
            <div class="metric">
                <div class="k">{{ \App\Models\TrackedPost::metricLabel($k) }}</div>
                <div class="v {{ $m['values'][$k] === null ? 'na' : '' }}">{{ $post->formatMetric($m['values'][$k], $k) }}</div>
            </div>
        @endforeach
    </div>

    @php($snapCount = $post->snapshots->count())
    @if ($snapCount > 0)
        <details class="history">
            <summary>{{ trans_choice('posts.history.summary', $snapCount) }}</summary>
            <div class="scroll-x">
                <table class="history-table">
                    <thead>
                        <tr>
                            <th>{{ __('posts.history.captured_at') }}</th>
                            <th>{{ __('posts.history.since_post') }}</th>
                            @foreach (['views', 'replies', 'reposts', 'shares'] as $k)
                                <th>{{ \App\Models\TrackedPost::metricLabel($k) }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach (array_reverse(\App\Threads\SnapshotDeltas::for($post->snapshots)) as $row)
                            @php($s = $row['snapshot'])
                            <tr>
                                <td>{{ \App\Models\TrackedPost::localTime($s->captured_at, 'Y-m-d H:i') }}</td>
                                @php($sinceHours = $s->hours_since_publish === null ? null : rtrim(rtrim((string) $s->hours_since_publish, '0'), '.'))
                                <td>{{ $sinceHours === null ? '—' : trans_choice('posts.hours', (float) $sinceHours, ['count' => $sinceHours]) }}</td>
                                @foreach (['views', 'replies', 'reposts', 'shares'] as $k)
                                    <td>
                                        <div class="{{ $s->{$k} === null ? 'na' : '' }}">{{ $post->formatMetric($s->{$k}, $k) }}</div>
                                        @include('posts._delta', ['delta' => $row['delta'][$k], 'perHour' => $row['per_hour'][$k]])
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="muted text-xs m-0 mt-1">{{ __('posts.history.note') }}</p>
        </details>
    @endif

    @if ($post->last_error && in_array($post->status, ['failed', 'gone', 'tracking', 'pending'], true))
        <div class="err-box">{{ __('posts.error.line', ['label' => $post->status === 'failed' ? __('posts.error.failure_reason') : __('posts.error.last_error'), 'error' => $post->lastErrorText()]) }}</div>
    @endif

    @php($editing = (int) session('meta_post') === (int) $post->id)
    <details class="post-edit"{!! $editing ? ' open' : '' !!}>
        <summary>{{ __('posts.edit.summary') }}</summary>
        @include('posts._meta-form', ['post' => $post, 'entry' => $post->pivot, 'tags' => $post->pivot->tags, 'groups' => $groups,
            'editing' => $editing, 'idPrefix' => 'p'.$post->id.'-', 'redirect' => request()->getRequestUri().'#post-'.$post->id])
    </details>

    <div class="actions end mt-3">
        @include('posts._untrack', ['post' => $post, 'small' => true])
    </div>
</article>
