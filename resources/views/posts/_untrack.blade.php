{{-- Remove a post from the current user's own list (contract C10). The confirmation text sits in an
     HTML-escaped data attribute; the inline handler itself is a constant (no Blade echo in JavaScript). --}}
<form method="POST" action="{{ route('posts.destroy', $post) }}"
      data-confirm="{{ __('posts.untrack.confirm') }}" onsubmit="return confirm(this.dataset.confirm);">
    @csrf
    @method('DELETE')
    <button type="submit" class="danger{{ ($small ?? false) ? ' small' : '' }}">{{ __('posts.untrack.button') }}</button>
</form>
