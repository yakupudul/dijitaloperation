<section class="space-y-4 rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200">
    <h2 class="text-lg font-semibold">WhatsApp hesabını Meta ile bağla</h2>
    <p class="text-sm text-gray-500">WhatsApp uygulamanızın App ID ve Configuration ID bilgilerini kaydedin. Hesap ve telefon bilgileri Meta bağlantısından alınacak.</p>
    <form autocomplete="off" class="space-y-4" x-data="{ saving: false, dirty: false, message: '' }"
        @input="dirty = true; message = ''" @change="dirty = true; message = ''"
        @submit.prevent="
            if (saving) return;
            saving = true; message = '';
            try {
                const saved = await $wire.saveSignupSetup({ app_secret: $refs.signupSecret.value, verify_token: $refs.signupVerify.value });
                if (saved) { $refs.signupSecret.value = ''; $refs.signupVerify.value = ''; dirty = false; message = 'Kaydedildi. Hesabı bağlayabilirsiniz.'; }
                else message = 'Kaydedilemedi. İşaretli alanları düzeltin.';
            } catch (_) { message = 'Kaydedilemedi. Girdiğiniz bilgiler formda duruyor; tekrar deneyin.'; }
            finally { saving = false; }
        ">
        <fieldset :disabled="saving" class="grid gap-4 md:grid-cols-2">
            <label class="text-sm">Meta App ID
                <input wire:model="app_id" inputmode="numeric" required class="mt-1 w-full rounded-lg border border-gray-300 bg-transparent p-2" />
                <span class="mt-1 block text-xs text-gray-500">Meta uygulamasının Settings → Basic bölümündeki App ID.</span>
            </label>
            <label class="text-sm">Configuration ID
                <input wire:model="signup_config_id" inputmode="numeric" required class="mt-1 w-full rounded-lg border border-gray-300 bg-transparent p-2" />
            </label>
            <label class="text-sm">Meta App Secret
                <span wire:ignore class="block"><input x-ref="signupSecret" type="password" autocomplete="new-password" placeholder="Kayıtlı değeri korumak için boş bırakın" class="mt-1 w-full rounded-lg border border-gray-300 bg-transparent p-2" /></span>
                <span class="mt-1 block text-xs text-gray-500">{{ $credentialStatus['app_secret'] ? 'Kayıtlı. Aynı WhatsApp uygulamasıysa tekrar girmeniz gerekmez.' : 'Henüz kayıtlı değil.' }}</span>
            </label>
            <label class="text-sm">Webhook Verify Token
                <span wire:ignore class="block"><input x-ref="signupVerify" type="password" autocomplete="new-password" minlength="16" placeholder="Kayıtlı değeri korumak için boş bırakın" class="mt-1 w-full rounded-lg border border-gray-300 bg-transparent p-2" /></span>
                <span class="mt-1 block text-xs text-gray-500">{{ $credentialStatus['verify_token'] ? 'Kayıtlı. Meta webhook ekranındaki değerle aynı olmalı.' : 'En az 16 karakter belirleyin; Meta webhook ekranında da aynı değeri kullanın.' }}</span>
            </label>
            <label class="text-sm md:col-span-2">Bağlantı türü
                <select wire:model="signup_mode" class="mt-1 w-full rounded-lg border border-gray-300 bg-transparent p-2">
                    <option value="coexistence">Telefonumdaki WhatsApp Business ile birlikte kullan (Coexistence)</option>
                    <option value="cloud_api">WhatsApp Cloud API hesabını bağla</option>
                </select>
            </label>
        </fieldset>
        <p x-show="message" x-text="message" role="status" class="text-sm"></p>
        <div class="flex flex-wrap gap-3">
            <button type="submit" :disabled="saving" class="rounded-lg border border-gray-300 px-4 py-2 text-sm disabled:opacity-50" x-text="saving ? 'Kaydediliyor…' : 'Meta ayarlarını kaydet'">Meta ayarlarını kaydet</button>
            <button type="button" wire:click="beginSignup" wire:loading.attr="disabled" :disabled="saving || dirty" @click.capture="if (saving || dirty) $event.stopImmediatePropagation()" class="rounded-lg bg-brand-500 px-4 py-2 text-sm text-white disabled:opacity-50">WhatsApp hesabını bağla</button>
        </div>
        <p x-cloak x-show="dirty" class="text-xs text-amber-700">Bağlantıyı başlatmadan önce Meta ayarlarını kaydedin.</p>
    </form>
    <p class="text-xs text-gray-500">Meta uygulamasında app.moximu.com alan adına izin verilmeli. Coexistence seçeneğinin kullanılabilirliği Meta hesabınıza bağlıdır.</p>
</section>
