{{-- Too many requests. On the login form this is the login throttle: say how long to wait. --}}
@php($retryAfter = (int) (($exception ?? null)?->getHeaders()['Retry-After'] ?? 0))
@include('errors.page', ['code' => '429', 'message' => $retryAfter > 0 ? __('auth.throttle', ['seconds' => $retryAfter]) : __('ui.errors.429.message')])
