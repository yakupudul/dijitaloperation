<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('two_factor.challenge_title') }} | {{ $operatorBranding['portal_name'] ?? 'MoxDOP' }}</title>
    @if (! empty($operatorBranding['favicon_url']))
        <link rel="icon" href="{{ $operatorBranding['favicon_url'] }}" />
    @endif
    @vite(['resources/css/operator.css', 'resources/js/operator.js'])
    @include('operator.partials.theme-colors')
    <script>
        (function() {
            const saved = localStorage.getItem('theme');
            const system = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            const theme = saved || system;
            if (theme === 'dark') {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>
</head>

<body class="min-h-full bg-page font-outfit text-gray-900 antialiased dark:bg-gray-950 dark:text-gray-100">
    <main class="flex min-h-screen flex-col items-center justify-center px-4 py-12">
        <div class="card w-full max-w-[400px] p-7 sm:p-9">
            <div class="mb-8 flex items-center gap-2.5">
                @if (! empty($operatorBranding['logo_url']))
                    <img src="{{ $operatorBranding['logo_url'] }}" alt="" class="h-8 w-8 shrink-0 rounded-lg object-contain" />
                @else
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-gray-900 text-sm font-bold text-white dark:bg-white dark:text-gray-900" aria-hidden="true">{{ $operatorBranding['display_initial'] ?? 'M' }}</span>
                @endif
                <span class="text-[15px] font-semibold tracking-tight text-gray-900 dark:text-white">{{ $operatorBranding['portal_name'] ?? 'MoxDOP' }}</span>
            </div>
            <h1 class="text-2xl leading-8 font-semibold tracking-[-0.02em] text-gray-900 dark:text-white">{{ __('two_factor.challenge_title') }}</h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('two_factor.challenge_hint') }}</p>

            <form method="POST" action="{{ route('app.login.two-factor.store') }}" class="mt-7 space-y-4">
                @csrf

                <label class="block">
                    <span class="text-[13px] font-medium text-gray-700 dark:text-gray-300">{{ __('two_factor.code') }}</span>
                    <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" autofocus
                        class="mt-1.5 h-12 w-full rounded-lg border border-gray-200 bg-white px-3 text-center text-lg tracking-[0.4em] text-gray-900 placeholder:text-gray-400 outline-none focus:border-gray-900 focus:ring-2 focus:ring-gray-900/10 dark:border-gray-700 dark:bg-transparent dark:text-white dark:focus:border-white dark:focus:ring-white/10" />
                </label>

                <details class="group text-[13px]">
                    <summary class="cursor-pointer list-none font-medium text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white [&::-webkit-details-marker]:hidden">{{ __('two_factor.use_recovery') }}</summary>
                    <p class="mt-2 text-gray-500 dark:text-gray-400">{{ __('two_factor.recovery_hint') }}</p>
                    <input type="text" name="recovery_code" autocomplete="off" aria-label="{{ __('two_factor.recovery_code') }}"
                        class="mt-2 h-10 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 placeholder:text-gray-400 outline-none focus:border-gray-900 focus:ring-2 focus:ring-gray-900/10 dark:border-gray-700 dark:bg-transparent dark:text-white dark:focus:border-white dark:focus:ring-white/10" />
                </details>

                @error('code')
                    <span class="block text-[13px] text-error-600 dark:text-error-400">{{ $message }}</span>
                @enderror

                <button type="submit" class="btn-primary h-10! w-full text-sm!">
                    {{ __('two_factor.verify') }}
                </button>
            </form>
            <p class="mt-6 text-center text-[13px]">
                <a href="{{ route('app.login') }}" class="link-neutral">{{ __('two_factor.back_to_login') }}</a>
            </p>
        </div>
    </main>
</body>

</html>
