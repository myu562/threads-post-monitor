{{-- The user's own groups (contract G3): add, rename, delete, reorder. Opens by itself after a failed
     action so the error is visible next to the form that caused it. Every form returns to this list
     with the current search and filters ("redirect"). --}}
@php($groupErrors = $errors->getBag('groups'))
@php($groupForm = session('group_form'))
@php($backTo = request()->getRequestUri())
<details class="card group-manage section"{!! $groupForm !== null || $groupErrors->any() ? ' open' : '' !!}>
    <summary>
        <span class="group-name">{{ __('posts.groups.manage') }}</span>
        <span class="group-count">{{ $groups->count() }}</span>
    </summary>
    <div class="group-manage-body">
        <p class="muted text-xs m-0 mt-3">{{ __('posts.groups.manage_hint') }}</p>

        <form method="POST" action="{{ route('groups.store') }}" class="mt-3">
            @csrf
            <input type="hidden" name="redirect" value="{{ $backTo }}">
            <label for="group-add-name" class="mt-0">{{ __('posts.groups.new_label') }}</label>
            <div class="row">
                <div class="grow">
                    <input id="group-add-name" type="text" name="name" maxlength="{{ \App\Models\Group::NAME_MAX }}" required autocomplete="off"
                           value="{{ $groupForm === 'new' ? old('name') : '' }}" placeholder="{{ __('posts.groups.new_placeholder') }}">
                    @if ($groupForm === 'new' && $groupErrors->has('name')) <div class="error" role="alert">{{ $groupErrors->first('name') }}</div> @endif
                </div>
                <button type="submit">{{ __('posts.groups.add') }}</button>
            </div>
        </form>

        @if ($groups->isEmpty())
            <p class="muted text-sm m-0 mt-3">{{ __('posts.groups.none_yet') }}</p>
        @else
            <ul class="group-rows">
                @foreach ($groups as $g)
                    <li class="group-row">
                        <form method="POST" action="{{ route('groups.update', $g) }}" class="group-rename">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="redirect" value="{{ $backTo }}">
                            <label for="group-name-{{ $g->id }}" class="sr-only">{{ __('posts.groups.rename_label', ['name' => $g->name]) }}</label>
                            <input id="group-name-{{ $g->id }}" type="text" name="name" maxlength="{{ \App\Models\Group::NAME_MAX }}" required autocomplete="off"
                                   value="{{ $groupForm === (string) $g->id ? old('name', $g->name) : $g->name }}">
                            <button type="submit" class="ghost">{{ __('posts.groups.rename') }}</button>
                        </form>
                        <div class="group-row-actions">
                            <form method="POST" action="{{ route('groups.move', $g) }}">
                                @csrf
                                <input type="hidden" name="redirect" value="{{ $backTo }}">
                                <input type="hidden" name="direction" value="up">
                                <button type="submit" class="ghost" @disabled($loop->first)
                                        aria-label="{{ __('posts.groups.move_up_label', ['name' => $g->name]) }}"><span aria-hidden="true">↑</span> {{ __('posts.groups.move_up') }}</button>
                            </form>
                            <form method="POST" action="{{ route('groups.move', $g) }}">
                                @csrf
                                <input type="hidden" name="redirect" value="{{ $backTo }}">
                                <input type="hidden" name="direction" value="down">
                                <button type="submit" class="ghost" @disabled($loop->last)
                                        aria-label="{{ __('posts.groups.move_down_label', ['name' => $g->name]) }}"><span aria-hidden="true">↓</span> {{ __('posts.groups.move_down') }}</button>
                            </form>
                            {{-- The confirmation text sits in an HTML-escaped data attribute; the handler is a constant. --}}
                            <form method="POST" action="{{ route('groups.destroy', $g) }}"
                                  data-confirm="{{ __('posts.groups.delete_confirm', ['name' => $g->name, 'count' => $g->entries_count]) }}" onsubmit="return confirm(this.dataset.confirm);">
                                @csrf
                                @method('DELETE')
                                <input type="hidden" name="redirect" value="{{ $backTo }}">
                                <button type="submit" class="danger"
                                        aria-label="{{ __('posts.groups.delete_label', ['name' => $g->name]) }}">{{ __('posts.groups.delete') }}</button>
                            </form>
                        </div>
                        @if ($groupForm === (string) $g->id && $groupErrors->has('name')) <div class="error group-row-error" role="alert">{{ $groupErrors->first('name') }}</div> @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</details>
