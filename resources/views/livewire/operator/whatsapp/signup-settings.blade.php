@php($input = 'mt-1 h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm font-normal text-gray-900 placeholder:text-gray-400 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-white')
<form autocomplete="off" class="space-y-4" data-wa-meta-form x-data="{ saving: false, dirty: false, message: '' }"
    @input="dirty = true; message = ''" @change="dirty = true; message = ''"
    @submit.prevent="
        if (saving) return;
        saving = true; message = '';
        try {
            const saved = await $wire.saveSignupSetup({ app_secret: $refs.signupSecret.value, verify_token: $refs.signupVerify.value });
            if (saved) { $refs.signupSecret.value = ''; $refs.signupVerify.value = ''; dirty = false; message = 'Kaydedildi.'; }
            else message = 'Kaydedilemedi. İşaretli alanları düzeltin.';
        } catch (_) { message = 'Kaydedilemedi. Girdiğiniz bilgiler formda duruyor; tekrar deneyin.'; }
        finally { saving = false; }
    ">
    <fieldset :disabled="saving" class="grid gap-4 text-[13px] text-gray-600 md:grid-cols-2 dark:text-gray-300">
        <label>Meta App ID
            <input wire:model="app_id" inputmode="numeric" required class="{{ $input }}" />
            <span class="mt-1 block text-xs text-gray-500">Uygulama ayarları › Temel bölümünde.</span>
            @error('app_id')<span class="mt-1 block text-xs text-error-600">{{ $message }}</span>@enderror
        </label>
        <label>Embedded Signup yapılandırma ID'si
            <input wire:model="signup_config_id" inputmode="numeric" required class="{{ $input }}" />
            <span class="mt-1 block text-xs text-gray-500">Facebook Login for Business › Yapılandırmalar; "WhatsApp Embedded Signup" şablonuyla oluşturulmuş güncel yapılandırma.</span>
            @error('signup_config_id')<span class="mt-1 block text-xs text-error-600">{{ $message }}</span>@enderror
        </label>
        <label>Meta App Secret
            <span wire:ignore class="block"><input x-ref="signupSecret" type="password" autocomplete="new-password" placeholder="{{ $credentialStatus['app_secret'] ? 'Kayıtlı · değiştirmek için yazın' : 'Gerekli' }}" class="{{ $input }}" /></span>
            @error('app_secret')<span class="mt-1 block text-xs text-error-600">{{ $message }}</span>@enderror
        </label>
        <label>Webhook Verify Token
            <span wire:ignore class="block"><input x-ref="signupVerify" type="password" autocomplete="new-password" minlength="16" placeholder="{{ $credentialStatus['verify_token'] ? 'Kayıtlı · değiştirmek için yazın' : 'En az 16 karakter; Meta webhook ekranına da aynısı' }}" class="{{ $input }}" /></span>
            @error('verify_token')<span class="mt-1 block text-xs text-error-600">{{ $message }}</span>@enderror
        </label>
        <label class="md:col-span-2">Bağlantı türü
            <select wire:model="signup_mode" class="{{ $input }}">
                <option value="coexistence">Telefondaki WhatsApp Business uygulamasıyla birlikte (önerilen)</option>
                <option value="cloud_api">Yalnız Cloud API (numara telefondaki uygulamadan çıkar)</option>
            </select>
        </label>
    </fieldset>
    <div class="flex flex-wrap items-center gap-3">
        <button type="submit" :disabled="saving" class="btn-primary" x-text="saving ? 'Kaydediliyor…' : 'Kaydet'">Kaydet</button>
        <p x-show="message" x-text="message" role="status" class="text-sm text-gray-600 dark:text-gray-300"></p>
    </div>
    <p class="text-xs text-gray-500">Meta uygulamasında app.moximu.com alan adına izin verilmiş olmalı. WhatsApp Business uygulamasıyla birlikte kullanım yalnız Tech Provider olarak doğrulanmış uygulamalarda ve Business uygulamasının 2.24.17 ve üstü sürümünde görünür.</p>
</form>
