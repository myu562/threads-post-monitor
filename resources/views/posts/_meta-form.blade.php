{{-- Group, tags and note of one post, saved together through PUT /posts/{id}/meta (contract G4).
     Used on each card in the list and at the top of the post page.
     $editing: this form is the one the last failed save came from, so it shows the old input and errors.
     Without JavaScript the new-group name field is always visible; the script in
     posts._list-script hides it unless "New group…" is selected. --}}
@php($metaErrors = $editing ? $errors->getBag('meta') : new \Illuminate\Support\MessageBag)
@php($legacyTags = $editing ? $errors->getBag('tags')->first('tags') : null)
@php($legacyNote = $editing ? $errors->getBag('note')->first('note') : null)
@php($current = $entry->group_id === null ? 'none' : (string) $entry->group_id)
@php($selected = $editing ? (string) old('group', $current) : $current)
@php($tagValue = $tags->pluck('name')->implode(', '))
<form method="POST" action="{{ route('posts.meta.update', $post) }}" class="meta-form" data-meta-form>
    @csrf
    @method('PUT')
    <input type="hidden" name="redirect" value="{{ $redirect }}">

    <div class="meta-form-grid">
        <div>
            <label for="{{ $idPrefix }}group" class="mt-0">{{ __('posts.edit.group') }}</label>
            <select id="{{ $idPrefix }}group" name="group" class="select" data-group-select>
                <option value="none" @selected($selected === 'none' || $selected === '')>{{ __('posts.edit.ungrouped_option') }}</option>
                @foreach ($groups as $g)
                    <option value="{{ $g->id }}" @selected($selected === (string) $g->id)>{{ $g->name }}</option>
                @endforeach
                <option value="new" @selected($selected === 'new')>{{ __('posts.edit.new_option') }}</option>
            </select>
        </div>
        <div class="group-new" data-group-new>
            <label for="{{ $idPrefix }}new-group" class="mt-0">{{ __('posts.edit.new_label') }}</label>
            <input id="{{ $idPrefix }}new-group" type="text" name="new_group" maxlength="{{ \App\Models\Group::NAME_MAX }}" autocomplete="off"
                   value="{{ $editing ? old('new_group') : '' }}" placeholder="{{ __('posts.groups.new_placeholder') }}"
                   aria-describedby="{{ $idPrefix }}new-group-hint">
            <div class="hint" id="{{ $idPrefix }}new-group-hint">{{ __('posts.edit.new_hint') }}</div>
            @if ($metaErrors->has('new_group')) <div class="error" role="alert">{{ $metaErrors->first('new_group') }}</div> @endif
        </div>
    </div>

    <label for="{{ $idPrefix }}tags">{{ __('posts.edit.tags') }}</label>
    <input id="{{ $idPrefix }}tags" type="text" name="tags" autocomplete="off"
           value="{{ $editing ? old('tags', $tagValue) : $tagValue }}"
           placeholder="{{ __('posts.tags.placeholder') }}" aria-describedby="{{ $idPrefix }}tags-hint">
    <div class="hint" id="{{ $idPrefix }}tags-hint">{{ __('posts.tags.hint', ['max' => \App\Models\Tag::MAX_LENGTH, 'per_post' => \App\Models\Tag::MAX_PER_POST]) }}</div>
    @if ($metaErrors->has('tags') || $legacyTags) <div class="error" role="alert">{{ $metaErrors->first('tags') ?: $legacyTags }}</div> @endif

    <label for="{{ $idPrefix }}note">{{ __('posts.edit.note') }}</label>
    <textarea id="{{ $idPrefix }}note" name="note" rows="3" maxlength="{{ \App\Models\TrackedPostUser::NOTE_MAX }}" class="textarea"
              aria-describedby="{{ $idPrefix }}note-hint">{{ $editing ? old('note', $entry->note) : $entry->note }}</textarea>
    <div class="hint" id="{{ $idPrefix }}note-hint">{{ __('posts.note.hint', ['max' => \App\Models\TrackedPostUser::NOTE_MAX]) }}</div>
    @if ($metaErrors->has('note') || $legacyNote) <div class="error" role="alert">{{ $metaErrors->first('note') ?: $legacyNote }}</div> @endif

    <div class="actions end mt-3"><button type="submit">{{ __('posts.edit.save') }}</button></div>
</form>
