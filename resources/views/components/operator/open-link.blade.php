{{--
    One "Aç ↗" to the same thing in the provider's own screen (App\Services\Operator\ExternalLinks): a new tab with the
    target in the tooltip. A WordPress link signs in with the connector's one-click login (POST, admin only); a user who
    is not admin gets the plain /wp-admin link. `variant`: link (quiet text in a row), button (bordered), menu (menu row).
--}}
@props(['link' => null, 'variant' => 'link', 'label' => null])
@php
    $isLogin = is_array($link) && $link['login'] !== null && (auth()->user()?->hasRole(\App\Support\Roles::ADMIN) ?? false);
    $text = $label ?? ($link['label'] ?? '');
    $class = match ($variant) {
        'button' => 'btn',
        'menu' => 'flex w-full min-w-0 items-center gap-2 rounded-lg px-2.5 py-2 text-left text-sm text-gray-700 hover:bg-gray-50 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-white/[0.04] dark:hover:text-white',
        default => 'link-neutral inline-flex items-center gap-1 whitespace-nowrap text-xs',
    };
@endphp
@if (is_array($link))
    @if ($isLogin)
        <form method="POST" action="{{ route('operator.integrations.wordpress-login', ['site' => $link['login']['site']]) }}" target="_blank" {{ $attributes->merge(['class' => $variant === 'menu' ? 'block' : 'inline-flex']) }} data-open-link="{{ $link['key'] }}">
            @csrf
            <input type="hidden" name="to" value="{{ $link['login']['to'] }}">
            <button type="submit" class="{{ $class }}" title="{{ $link['hint'] }}"><span class="min-w-0 truncate">{{ $text }}</span><span aria-hidden="true" class="shrink-0 text-gray-400">↗</span></button>
        </form>
    @else
        <a href="{{ $link['url'] }}" target="_blank" rel="noopener noreferrer" title="{{ $link['hint'] }}" {{ $attributes->merge(['class' => $class]) }} data-open-link="{{ $link['key'] }}"><span class="min-w-0 truncate">{{ $text }}</span><span aria-hidden="true" class="shrink-0 text-gray-400">↗</span></a>
    @endif
@endif
