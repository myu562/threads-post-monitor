@auth
<header class="top">
    <h1 class="brand"><a href="{{ route('posts.index') }}">{{ config('app.name') }}</a></h1>
    <div class="top-tools">
        <nav class="links" aria-label="{{ __('ui.nav.main') }}">
            <a href="{{ route('posts.index') }}" class="{{ request()->routeIs('posts.*') ? 'on' : '' }}">{{ __('ui.nav.posts') }}</a>
            <a href="{{ route('account.password') }}" class="{{ request()->routeIs('account.*') ? 'on' : '' }}">{{ __('ui.nav.password') }}</a>
            @if (auth()->user()->is_admin)
                <a href="{{ route('users.index') }}" class="{{ request()->routeIs('users.*') ? 'on' : '' }}">{{ __('ui.nav.users') }}</a>
            @endif
            <span class="muted text-sm">{{ auth()->user()->email }}</span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="ghost">{{ __('ui.nav.logout') }}</button>
            </form>
        </nav>
        @include('partials.top-toggles')
    </div>
</header>
@else
<header class="top guest">
    @include('partials.top-toggles')
</header>
@endauth
