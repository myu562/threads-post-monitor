{{-- The current user's own tags on one post; each links to the list filtered by that tag. --}}
@if ($tags->isNotEmpty())
    <ul class="tag-list" aria-label="{{ __('posts.tags.list_label') }}">
        @foreach ($tags as $tag)
            <li><a class="tag-chip" href="{{ route('posts.index', ['tag' => [$tag->name]]) }}">#{{ $tag->name }}</a></li>
        @endforeach
    </ul>
@endif
