{{-- Language switch (contract L2). A plain form, so it works without JavaScript and from the keyboard.
     The button names the language it switches to; LocaleController sends the user back to this page. --}}
@php($localeTarget = app()->getLocale() === 'zh_TW' ? 'en' : 'zh_TW')
<form method="POST" action="{{ route('locale.update') }}" class="locale-form">
    @csrf
    <input type="hidden" name="locale" value="{{ $localeTarget }}">
    <input type="hidden" name="redirect" value="{{ request()->getRequestUri() }}">
    <button type="submit" class="locale-toggle" data-locale-toggle aria-label="{{ __('ui.locale.toggle_label') }}" title="{{ __('ui.locale.toggle_label') }}">
        <span lang="{{ \App\Http\Middleware\SetLocale::htmlLang($localeTarget) }}">{{ __('ui.locale.toggle') }}</span>
    </button>
</form>
