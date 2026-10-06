{{-- One metric's change since the previous snapshot (contract K1): sign + color, never color alone. --}}
<div class="delta {{ \App\Threads\SnapshotDeltas::cssClass($delta) }}" title="{{ \App\Threads\SnapshotDeltas::label($delta) }}">{{ \App\Threads\SnapshotDeltas::format($delta) }}</div>
@if ($perHour !== null)
    <div class="delta {{ \App\Threads\SnapshotDeltas::cssClass($perHour) }}">{{ __('posts.delta.per_hour', ['value' => \App\Threads\SnapshotDeltas::format($perHour)]) }}</div>
@endif
