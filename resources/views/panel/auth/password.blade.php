<!DOCTYPE html>
<html lang="tr" class="h-full">

<head>
    @include('panel.layouts.head', ['title' => 'Şifre'])
</head>

<body class="min-h-full bg-page font-outfit text-gray-900 antialiased">
    <main class="flex min-h-screen flex-col items-center justify-center px-4 py-12">
        <div class="card w-full max-w-[400px] p-7 shadow-lift! sm:p-9">
            <h1 class="text-[26px] leading-[34px] font-semibold tracking-[-0.03em] text-gray-900">Şifrenizi belirleyin</h1>
            <p class="mt-1 text-sm text-gray-500">@if (auth('client')->user()?->must_change_password) Size verilen şifre geçicidir. Devam etmek için yalnız sizin bildiğiniz bir şifre yazın. @else En az 8 karakter. @endif</p>
            <form method="POST" action="{{ route('panel.password.store') }}" class="mt-7 space-y-4">
                @csrf
                @foreach ([['current', 'Mevcut şifre', 'current-password'], ['password', 'Yeni şifre', 'new-password'], ['password_confirmation', 'Yeni şifre, tekrar', 'new-password']] as [$field, $label, $auto])
                    <label class="block">
                        <span class="text-[13px] font-medium text-gray-700">{{ $label }}</span>
                        <input type="password" name="{{ $field }}" required autocomplete="{{ $auto }}"
                            class="mt-1.5 h-11 w-full rounded-lg border border-gray-200 bg-white px-3 text-base text-gray-900 outline-none focus:border-gray-900 focus:ring-2 focus:ring-gray-900/10 sm:text-sm" />
                        @error($field)
                            <span class="mt-1 block text-[13px] text-error-600" role="alert">{{ $message }}</span>
                        @enderror
                    </label>
                @endforeach
                <button type="submit" class="btn-primary h-11! w-full text-sm!">Şifreyi kaydet</button>
            </form>
            <form method="POST" action="{{ route('panel.logout') }}" class="mt-4 text-center">@csrf
                <button type="submit" class="link-neutral text-[13px]">Çıkış yap</button>
            </form>
        </div>
    </main>
</body>

</html>
