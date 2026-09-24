<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="text-xs uppercase tracking-wide text-gray-400">Entegrasyon</p>
            <h1 class="text-2xl font-bold text-gray-800 dark:text-white/90">DataForSEO</h1>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <x-ta.badge :color="$configured ? 'info' : 'light'" size="sm">{{ $statusLabel }}</x-ta.badge>
            </div>
            <p class="mt-1 text-sm text-gray-500">Ajansın SEO / pazar verisi ve Niyet Radarı sağlayıcısı. Sayfayı açmak hiçbir ücretli çağrı yapmaz; her ücretli iş marka tavanına tabidir.</p>
        </div>
        <x-ta.button href="{{ route('operator.integrations') }}" size="sm" variant="outline">Tüm entegrasyonlar</x-ta.button>
    </div>

    <div class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">Ayarlar</h2>
        <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
            <div>
                <dt class="text-gray-400">Bilgiler</dt>
                <dd class="font-medium text-gray-800 dark:text-white/90">{{ $statusLabel }}</dd>
            </div>
            <div>
                <dt class="text-gray-400">Son doğrulama</dt>
                <dd class="font-medium text-gray-800 dark:text-white/90">{{ $lastTestedAt ?? 'Test edilmedi' }}</dd>
            </div>
            @if ($accountLogin)
                <div>
                    <dt class="text-gray-400">Hesap kullanıcı adı</dt>
                    <dd class="font-medium text-gray-800 dark:text-white/90">{{ $accountLogin }}</dd>
                </div>
            @endif
            <div>
                <dt class="text-gray-400">Niyet Radarı modu</dt>
                <dd class="font-medium {{ $fixturesEnabled ? 'text-amber-600' : ($salesIntentPaidCalls ? 'text-emerald-600' : 'text-gray-600') }}">
                    {{ $fixturesEnabled ? 'Örnek veri' : ($salesIntentPaidCalls ? 'Canlı ücretli çağrılar açık' : 'Canlı ücretli çağrılar kapalı') }}
                </dd>
            </div>
        </dl>
    </div>

    @if ($canManageCredentials)
        <form wire:submit.prevent="saveConfiguration" class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">API bilgileri</h3>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <x-ta.form.field label="API Login" helper="Not a secret. Visible after save." :error="$errors->first('login')">
                    <input wire:model="login" type="text" autocomplete="off"
                        class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-800 shadow-theme-xs outline-none focus:border-brand-300 focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                </x-ta.form.field>
                <x-ta.form.field label="API Password" :helper="$passwordConfigured ? 'Configured — leave blank to keep the stored value.' : 'Write-only. Never shown after save.'" :error="$errors->first('password')">
                    <input wire:model="password" type="password" autocomplete="new-password" placeholder="{{ $passwordConfigured ? 'Bilgiyi değiştir' : '' }}"
                        class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-800 shadow-theme-xs outline-none focus:border-brand-300 focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                </x-ta.form.field>
                @if ($passwordConfigured)
                    <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300 md:col-span-2">
                        <input type="checkbox" wire:model="clearPassword" class="rounded border-gray-300" />
                        Clear stored API Password
                    </label>
                @endif
            </div>
            <div class="mt-4 flex flex-wrap gap-2">
                <x-ta.button type="submit" size="sm">Kaydet</x-ta.button>
                <x-ta.button type="button" wire:click="testConfiguration" size="sm" variant="outline">Ayarları test et</x-ta.button>
                @if ($configured || $passwordConfigured)
                    <x-ta.button type="button" wire:click="askRemove" size="sm" variant="outline">Bilgileri sil</x-ta.button>
                @endif
            </div>
        </form>

        <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div class="max-w-3xl">
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">Niyet Radarı canlı keşif</h3>
                    <p class="mt-2 text-sm text-gray-500">Niyet Radarı'nın DataForSEO canlı Google arama sonucunu çağırıp çağıramayacağını belirler. Bu ücretlidir.</p>
                    <p class="mt-2 text-xs text-gray-400">Açmak hiçbir şeyi kendiliğinden çalıştırmaz; her arama profili çalıştırması yine ücretli çağrı onayı ister.</p>
                    @if ($fixturesEnabled)
                        <p class="mt-3 text-sm font-medium text-amber-700">Sunucu ayarında örnek veri modu açık. Niyet Radarı sonuçlarını gerçek pazar verisi saymadan önce kapatın.</p>
                    @endif
                </div>
                <form wire:submit.prevent="saveSalesIntentRuntime" class="shrink-0 rounded-xl bg-gray-50 p-4 dark:bg-white/[0.03]">
                    <label class="flex items-center gap-3 text-sm font-medium text-gray-800 dark:text-gray-200">
                        <input type="checkbox" wire:model="salesIntentPaidCalls" class="rounded border-gray-300" />
                        Enable paid live calls
                    </label>
                    <button type="submit" class="mt-3 w-full rounded-lg bg-brand-500 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-600">Ayarı kaydet</button>
                </form>
            </div>
        </section>

        @if ($confirmRemove)
            <div class="rounded-xl bg-warning-50 p-4 ring-1 ring-inset ring-warning-200 dark:bg-warning-500/10 dark:ring-warning-500/30">
                <p class="text-sm font-medium text-gray-800 dark:text-white/90">DataForSEO bilgileri silinsin mi?</p>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Kayıtlı API kullanıcı adı ve şifresi silinir. Geçmiş kayıtlar korunur.</p>
                <div class="mt-3 flex gap-2">
                    <x-ta.button wire:click="removeConfiguration" size="sm">Evet, sil</x-ta.button>
                    <x-ta.button wire:click="cancelRemove" size="sm" variant="outline">Vazgeç</x-ta.button>
                </div>
            </div>
        @endif
    @else
        <p class="text-sm text-gray-500">DataForSEO bilgilerini yalnız yöneticiler görebilir ve değiştirebilir.</p>
    @endif
</div>
