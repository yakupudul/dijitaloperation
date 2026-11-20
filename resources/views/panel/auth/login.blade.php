<!DOCTYPE html>
<html lang="tr" class="h-full">

<head>
    @include('panel.layouts.head', ['title' => 'Giriş'])
</head>

<body class="min-h-full bg-page font-outfit text-gray-900 antialiased">
    <main class="flex min-h-screen flex-col items-center justify-center px-4 py-12">
        <div class="card w-full max-w-[400px] p-7 shadow-lift! sm:p-9">
            <p class="text-[15px] font-semibold tracking-tight text-gray-900">{{ config('panel.name') }}</p>
            <h1 class="mt-7 text-[26px] leading-[34px] font-semibold tracking-[-0.03em] text-gray-900">Giriş yapın</h1>
            <p class="mt-1 text-sm text-gray-500">Adaylarınızı, notlarınızı ve hatırlatmalarınızı tek yerden takip edin.</p>

            <form method="POST" action="{{ route('panel.login.store') }}" class="mt-7 space-y-4">
                @csrf
                <label class="block">
                    <span class="text-[13px] font-medium text-gray-700">E-posta</span>
                    <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                        class="mt-1.5 h-11 w-full rounded-lg border border-gray-200 bg-white px-3 text-base text-gray-900 outline-none focus:border-gray-900 focus:ring-2 focus:ring-gray-900/10 sm:text-sm" />
                    @error('email')
                        <span class="mt-1 block text-[13px] text-error-600" role="alert">{{ $message }}</span>
                    @enderror
                </label>
                <label class="block">
                    <span class="text-[13px] font-medium text-gray-700">Şifre</span>
                    <input type="password" name="password" required autocomplete="current-password"
                        class="mt-1.5 h-11 w-full rounded-lg border border-gray-200 bg-white px-3 text-base text-gray-900 outline-none focus:border-gray-900 focus:ring-2 focus:ring-gray-900/10 sm:text-sm" />
                    @error('password')
                        <span class="mt-1 block text-[13px] text-error-600" role="alert">{{ $message }}</span>
                    @enderror
                </label>
                <label class="flex items-center gap-2 text-[13px] text-gray-600">
                    <input type="checkbox" name="remember" value="1" @checked(old('remember')) class="size-4 rounded border-gray-300 text-gray-900 focus:ring-gray-900/20" />
                    <span>Bu cihazda beni hatırla</span>
                </label>
                <button type="submit" class="btn-primary h-11! w-full text-sm!">Giriş yap</button>
            </form>
        </div>
        <p class="mt-5 w-full max-w-[400px] px-1 text-xs text-gray-500">Şifrenizi unuttuysanız markanızın yöneticisi yenisini verir.</p>
    </main>
</body>

</html>
