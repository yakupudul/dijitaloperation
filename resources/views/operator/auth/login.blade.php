<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('operator.auth.login_title') }} | {{ $operatorBranding['portal_name'] ?? 'MoxDOP' }}</title>
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

<body class="min-h-full bg-gray-50 text-gray-800 antialiased dark:bg-gray-950 dark:text-gray-100">
    <div class="flex min-h-screen flex-col items-center justify-center px-4 py-12">
        <div class="mb-8 text-center">
            @if (! empty($operatorBranding['logo_url']))
                <img src="{{ $operatorBranding['logo_url'] }}" alt="" class="mx-auto mb-4 h-14 w-auto object-contain" />
            @endif
            <p class="text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">{{ $operatorBranding['portal_name'] ?? 'MoxDOP' }}</p>
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('operator.product.tagline') }}</p>
            <div class="mt-4 flex justify-center gap-1 rounded-lg border border-gray-200 p-1 dark:border-gray-800" role="group" aria-label="{{ __('operator.profile.locale') }}">
                <a href="{{ route('app.login', ['locale' => 'en']) }}"
                    @class(['rounded-md px-2.5 py-1.5 text-xs font-semibold transition', 'bg-brand-500 text-white' => app()->getLocale() === 'en', 'text-gray-600 hover:bg-gray-50 dark:text-gray-300' => app()->getLocale() !== 'en'])>EN</a>
                <a href="{{ route('app.login', ['locale' => 'tr']) }}"
                    @class(['rounded-md px-2.5 py-1.5 text-xs font-semibold transition', 'bg-brand-500 text-white' => app()->getLocale() === 'tr', 'text-gray-600 hover:bg-gray-50 dark:text-gray-300' => app()->getLocale() !== 'tr'])>TR</a>
            </div>
        </div>

        <div class="w-full max-w-md rounded-2xl bg-white p-8 shadow-sm ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <h1 class="text-xl font-semibold text-gray-900 dark:text-white">{{ __('operator.auth.login_heading') }}</h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('operator.auth.login_subtitle') }}</p>

            @if (session('status'))
                <p class="mt-4 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200" role="status">{{ session('status') }}</p>
            @endif

            <form method="POST" action="{{ route('app.login.store') }}" class="mt-6 space-y-4">
                @csrf

                <label class="block text-sm">
                    <span class="text-gray-600 dark:text-gray-300">{{ __('operator.auth.email') }}</span>
                    <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                        class="mt-1 w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2.5 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:text-white" />
                    @error('email')
                        <span class="mt-1 block text-sm text-red-600 dark:text-red-400">{{ $message }}</span>
                    @enderror
                </label>

                <label class="block text-sm">
                    <span class="text-gray-600 dark:text-gray-300">{{ __('operator.auth.password') }}</span>
                    <input type="password" name="password" required autocomplete="current-password"
                        class="mt-1 w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2.5 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:text-white" />
                    @error('password')
                        <span class="mt-1 block text-sm text-red-600 dark:text-red-400">{{ $message }}</span>
                    @enderror
                </label>

                <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                    <input type="checkbox" name="remember" value="1" @checked(old('remember'))
                        class="rounded border-gray-300 text-brand-600 focus:ring-brand-500" />
                    <span>{{ __('operator.auth.remember') }}</span>
                </label>

                <button type="submit"
                    class="inline-flex w-full items-center justify-center rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600">
                    {{ __('operator.auth.sign_in') }}
                </button>
            </form>
            <p class="mt-4 text-center text-sm text-gray-500 dark:text-gray-400">
                <a href="{{ route('app.password.request') }}" class="font-medium text-brand-600 hover:underline">{{ __('operator.auth.forgot_password') }}</a>
            </p>
        </div>
    </div>
</body>

</html>
