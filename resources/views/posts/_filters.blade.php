{{-- Search and filter bar for the user's own list (contract K4). GET only, so results can be bookmarked. --}}
<form method="GET" action="{{ route('posts.index') }}" class="card filter-bar section" role="search">
    <div class="filter-grid">
        <div class="filter-q">
            <label for="q" class="mt-0">{{ __('posts.filters.search') }}</label>
            <input id="q" type="text" name="q" value="{{ $filters['q'] }}" maxlength="100" enterkeyhint="search"
                   placeholder="{{ __('posts.filters.search_placeholder') }}" autocomplete="off">
        </div>
        <div>
            <label for="status" class="mt-0">{{ __('posts.filters.status') }}</label>
            <select id="status" name="status" class="select">
                <option value="">{{ __('posts.filters.all') }}</option>
                @foreach (\App\Http\Controllers\TrackedPostController::STATUS_FILTERS as $st)
                    <option value="{{ $st }}" @selected($filters['status'] === $st)>{{ \App\Models\TrackedPost::statusLabelFor($st) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="tag-add" class="mt-0">{{ __('posts.filters.add_tag') }}</label>
            @foreach ($filters['tags'] as $t)
                <input type="hidden" name="tag[]" value="{{ $t }}">
            @endforeach
            @php($appliedKeys = array_map(fn ($t) => \App\Models\Tag::key($t), $filters['tags']))
            <select id="tag-add" name="tag[]" class="select">
                <option value="">{{ __('posts.filters.no_tag') }}</option>
                @foreach ($userTags as $tag)
                    @unless (in_array(\App\Models\Tag::key($tag->name), $appliedKeys, true))
                        <option value="{{ $tag->name }}">{{ $tag->name }}</option>
                    @endunless
                @endforeach
            </select>
        </div>
        <div>
            <label for="group-filter" class="mt-0">{{ __('posts.filters.group') }}</label>
            <select id="group-filter" name="group" class="select">
                <option value="">{{ __('posts.filters.all_groups') }}</option>
                @foreach ($groups as $g)
                    <option value="{{ $g->id }}" @selected($filters['group'] === $g->id)>{{ $g->name }}</option>
                @endforeach
                <option value="none" @selected($filters['group'] === 'none')>{{ __('posts.groups.ungrouped') }}</option>
            </select>
        </div>
        <div class="filter-submit">
            <button type="submit">{{ __('posts.filters.apply') }}</button>
        </div>
    </div>

    @if ($filtered)
        <div class="filter-applied" aria-live="polite">
            <span class="muted text-sm">{{ __('posts.filters.applied') }}</span>
            @if ($filters['q'] !== null)
                <span class="tag">{{ __('posts.filters.keyword', ['q' => $filters['q']]) }}</span>
            @endif
            @if ($filters['status'] !== null)
                <span class="tag">{{ __('posts.filters.status_chip', ['status' => \App\Models\TrackedPost::statusLabelFor($filters['status'])]) }}</span>
            @endif
            @if ($filters['group'] !== null)
                @php($groupName = $filters['group'] === 'none' ? __('posts.groups.ungrouped') : ($groups->firstWhere('id', $filters['group'])?->name ?? '#'.$filters['group']))
                <span class="tag">{{ __('posts.filters.group_chip', ['group' => $groupName]) }}</span>
            @endif
            @foreach ($filters['tags'] as $t)
                @php($rest = array_values(array_filter($filters['tags'], fn ($x) => $x !== $t)))
                <a class="tag-chip" href="{{ route('posts.index', array_filter(['q' => $filters['q'], 'status' => $filters['status'], 'group' => $filters['group'], 'tag' => $rest])) }}"
                   title="{{ __('posts.filters.remove_tag') }}">#{{ $t }} <span aria-hidden="true">×</span><span class="sr-only">{{ __('posts.filters.remove') }}</span></a>
            @endforeach
            @if (count($filters['tags']) > 1)
                <span class="muted text-xs">{{ __('posts.filters.all_tags') }}</span>
            @endif
            <a href="{{ route('posts.index') }}" class="text-sm">{{ \Illuminate\Support\Str::ucfirst(__('posts.filters.clear')) }}</a>
        </div>
    @endif
</form>
