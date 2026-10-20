<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('operator.auth.reset_title') }} | {{ $operatorBranding['portal_name'] ?? 'MoxDOP' }}</title>
    @if (! empty($operatorBranding['favicon_url']))
        <link rel="icon" href="{{ $operatorBranding['favicon_url'] }}" />
    @endif
    @vite(['resources/css/operator.css', 'resources/js/operator.js'])
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
            <h1 class="text-2xl leading-8 font-semibold tracking-[-0.02em] text-gray-900 dark:text-white">{{ __('operator.auth.reset_heading') }}</h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('operator.auth.reset_subtitle') }}</p>

            <form method="POST" action="{{ route('app.password.update') }}" class="mt-7 space-y-4">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}" />

                <label class="block">
                    <span class="text-[13px] font-medium text-gray-700 dark:text-gray-300">{{ __('operator.auth.email') }}</span>
                    <input type="email" name="email" value="{{ old('email', $email) }}" required autocomplete="username"
                        class="mt-1.5 h-10 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 placeholder:text-gray-400 outline-none focus:border-gray-900 focus:ring-2 focus:ring-gray-900/10 dark:border-gray-700 dark:bg-transparent dark:text-white dark:focus:border-white dark:focus:ring-white/10" />
                    @error('email')
                        <span class="mt-1 block text-[13px] text-error-600 dark:text-error-400">{{ $message }}</span>
                    @enderror
                </label>

                <label class="block">
                    <span class="text-[13px] font-medium text-gray-700 dark:text-gray-300">{{ __('operator.auth.new_password') }}</span>
                    <input type="password" name="password" required autocomplete="new-password"
                        class="mt-1.5 h-10 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 placeholder:text-gray-400 outline-none focus:border-gray-900 focus:ring-2 focus:ring-gray-900/10 dark:border-gray-700 dark:bg-transparent dark:text-white dark:focus:border-white dark:focus:ring-white/10" />
                    @error('password')
                        <span class="mt-1 block text-[13px] text-error-600 dark:text-error-400">{{ $message }}</span>
                    @enderror
                </label>

                <label class="block">
                    <span class="text-[13px] font-medium text-gray-700 dark:text-gray-300">{{ __('operator.auth.new_password_confirmation') }}</span>
                    <input type="password" name="password_confirmation" required autocomplete="new-password"
                        class="mt-1.5 h-10 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 placeholder:text-gray-400 outline-none focus:border-gray-900 focus:ring-2 focus:ring-gray-900/10 dark:border-gray-700 dark:bg-transparent dark:text-white dark:focus:border-white dark:focus:ring-white/10" />
                </label>

                <button type="submit" class="btn-primary h-10! w-full text-sm!">
                    {{ __('operator.auth.reset_submit') }}
                </button>
            </form>
        </div>
    </main>
</body>

</html>
