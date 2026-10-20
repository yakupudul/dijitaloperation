@php($field = 'mt-1 h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm font-normal text-gray-900 placeholder:text-gray-400 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-white')
<div class="space-y-4 text-sm">
    <p class="text-[13px] text-gray-500 dark:text-gray-400">Meta uygulamasının sahibi olan portföydeki numara Facebook penceresinde seçilemez. O numarayı Meta'daki WhatsApp Yöneticisi'nden alınan bilgilerle burada bağlayın: WABA ID ve Phone Number ID WhatsApp › API Kurulumu ekranında; kalıcı erişim anahtarı İşletme Ayarları › Sistem kullanıcıları › Belirteç oluştur (whatsapp_business_management ve whatsapp_business_messaging izinleriyle).</p>
    <form class="space-y-4" autocomplete="off" x-data="{ saving: false, saveError: '', saveSuccess: '', dirty: false, entered: {} }" @input="dirty = true; saveSuccess = ''" @change="dirty = true; saveSuccess = ''"
        @submit.prevent="
            if (saving) return;
            saving = true; saveError = ''; saveSuccess = '';
            try {
                const saved = await $wire.saveSettings({ access_token: $refs.accessToken.value, app_secret: $refs.appSecret.value, verify_token: $refs.verifyToken.value });
                if (saved === true) {
                    for (const input of [$refs.accessToken, $refs.appSecret, $refs.verifyToken]) { input.value = ''; }
                    entered = {}; dirty = false;
                    saveSuccess = 'Kaydedildi. Şimdi Bağlantı bölümünden erişimi kontrol edin.';
                } else {
                    saveError = 'Kaydedilemedi. İşaretli alanları düzeltin; girdiğiniz bilgiler korunuyor.';
                }
            } catch (error) {
                saveError = 'Kayıt tamamlanamadı. Girdiğiniz değerler formda duruyor; tekrar deneyin.';
            } finally { saving = false; }
        ">
        <fieldset :disabled="saving" class="grid gap-4 text-[13px] text-gray-600 md:grid-cols-3 dark:text-gray-300">
            <label>WABA ID
                <input wire:model="waba_id" required inputmode="numeric" class="{{ $field }}" />
                @error('waba_id')<span class="mt-1 block text-xs text-error-600">{{ $message }}</span>@enderror
            </label>
            <label>Phone Number ID
                <input wire:model="phone_number_id" required inputmode="numeric" class="{{ $field }}" />
                @error('phone_number_id')<span class="mt-1 block text-xs text-error-600">{{ $message }}</span>@enderror
            </label>
            <label>İşletme numarası (ülke koduyla, yalnız rakam)
                <input wire:model="business_phone" required inputmode="numeric" placeholder="905xxxxxxxxx" class="{{ $field }}" />
                @error('business_phone')<span class="mt-1 block text-xs text-error-600">{{ $message }}</span>@enderror
            </label>
            @foreach(['access_token' => ['accessToken', 'Kalıcı erişim anahtarı'], 'app_secret' => ['appSecret', 'Meta App Secret'], 'verify_token' => ['verifyToken', 'Webhook Verify Token']] as $key => [$ref, $label])
                <label>{{ $label }}
                    <span wire:ignore class="block"><input type="password" x-ref="{{ $ref }}" @input="entered.{{ $key }} = $el.value.trim().length > 0" autocomplete="new-password" placeholder="{{ $credentialStatus[$key] ? 'Kayıtlı · değiştirmek için yazın' : 'Gerekli' }}" class="{{ $field }}" /></span>
                    <span x-cloak x-show="entered.{{ $key }}" class="mt-1 block text-xs text-warning-700">Yeni değer girildi, henüz kaydedilmedi.</span>
                    @error($key)<span class="mt-1 block text-xs text-error-600">{{ $message }}</span>@enderror
                </label>
            @endforeach
        </fieldset>
        <label class="flex items-center gap-2 text-gray-900 dark:text-white"><input type="checkbox" wire:model="enabled" class="size-4 rounded border-gray-300" /> Mesaj alımı açık</label>
        <p x-cloak x-show="saveError" x-text="saveError" role="alert" class="text-error-600"></p>
        <p x-cloak x-show="saveSuccess" x-text="saveSuccess" role="status" class="text-success-700"></p>
        <button type="submit" :disabled="saving" class="btn-primary" x-text="saving ? 'Kaydediliyor…' : 'Kaydet'">Kaydet</button>
    </form>
</div>
