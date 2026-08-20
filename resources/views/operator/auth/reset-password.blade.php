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

<body class="min-h-full bg-gray-50 text-gray-800 antialiased dark:bg-gray-950 dark:text-gray-100">
    <div class="flex min-h-screen flex-col items-center justify-center px-4 py-12">
        <div class="mb-8 text-center">
            @if (! empty($operatorBranding['logo_url']))
                <img src="{{ $operatorBranding['logo_url'] }}" alt="" class="mx-auto mb-4 h-14 w-auto object-contain" />
            @endif
            <p class="text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">{{ $operatorBranding['portal_name'] ?? 'MoxDOP' }}</p>
        </div>

        <div class="w-full max-w-md rounded-2xl bg-white p-8 shadow-sm ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <h1 class="text-xl font-semibold text-gray-900 dark:text-white">{{ __('operator.auth.reset_heading') }}</h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('operator.auth.reset_subtitle') }}</p>

            <form method="POST" action="{{ route('app.password.update') }}" class="mt-6 space-y-4">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}" />

                <label class="block text-sm">
                    <span class="text-gray-600 dark:text-gray-300">{{ __('operator.auth.email') }}</span>
                    <input type="email" name="email" value="{{ old('email', $email) }}" required autocomplete="username"
                        class="mt-1 w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2.5 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:text-white" />
                    @error('email')
                        <span class="mt-1 block text-sm text-red-600 dark:text-red-400">{{ $message }}</span>
                    @enderror
                </label>

                <label class="block text-sm">
                    <span class="text-gray-600 dark:text-gray-300">{{ __('operator.auth.new_password') }}</span>
                    <input type="password" name="password" required autocomplete="new-password"
                        class="mt-1 w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2.5 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:text-white" />
                    @error('password')
                        <span class="mt-1 block text-sm text-red-600 dark:text-red-400">{{ $message }}</span>
                    @enderror
                </label>

                <label class="block text-sm">
                    <span class="text-gray-600 dark:text-gray-300">{{ __('operator.auth.new_password_confirmation') }}</span>
                    <input type="password" name="password_confirmation" required autocomplete="new-password"
                        class="mt-1 w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2.5 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:text-white" />
                </label>

                <button type="submit"
                    class="inline-flex w-full items-center justify-center rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600">
                    {{ __('operator.auth.reset_submit') }}
                </button>
            </form>
        </div>
    </div>
</body>

</html>
