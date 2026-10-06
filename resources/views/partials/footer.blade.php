{{-- Credit line. Configured in config/threads.php ('footer'); $footer is prepared by the view
     composer in AppServiceProvider (null = footer turned off; url is null unless it is http/https). --}}
@if ($footer)
<footer class="site-footer">
    @if ($footer['url'])
        <a href="{{ $footer['url'] }}" target="_blank" rel="noopener noreferrer">{{ $footer['text'] }}</a>
    @else
        <span>{{ $footer['text'] }}</span>
    @endif
</footer>
@endif
