<div class="space-y-10">
    @include('livewire.demo.partials.flash')

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="text-xs text-gray-500">Entegrasyon</p>
            <h1 class="text-[28px] leading-9 font-semibold tracking-tight text-gray-900 dark:text-white">{{ $providerLabel }}</h1>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <x-ta.pill :tone="$configured ? 'ok' : 'info'">{{ $statusLabel }}</x-ta.pill>
            </div>
            <p class="mt-1 text-sm text-gray-500">{{ $supportNote }}</p>
        </div>
        <x-ta.button href="{{ route('operator.integrations') }}" size="sm" variant="outline">Tüm entegrasyonlar</x-ta.button>
    </div>

    <div>
        <h2 class="section-title">Ayarlar</h2>
        <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
            <div>
                <dt class="text-xs text-gray-500">API anahtarı</dt>
                <dd class="font-medium text-gray-800 dark:text-white/90">{{ $statusLabel }}</dd>
            </div>
            <div>
                <dt class="text-xs text-gray-500">Son doğrulama</dt>
                <dd class="font-medium text-gray-800 dark:text-white/90">{{ $lastTestedAt ?? 'Test edilmedi' }}</dd>
            </div>
        </dl>
    </div>

    @if ($canManageCredentials)
        <form wire:submit.prevent="saveConfiguration">
            <h2 class="section-title">API anahtarı</h2>
            <div class="mt-4">
                <x-ta.form.field label="API Key" :helper="$keyConfigured ? 'Configured — leave blank to keep the stored value.' : 'Write-only. Never shown after save.'" :error="$errors->first('api_key')">
                    <input wire:model="apiKey" type="password" autocomplete="new-password" placeholder="{{ $keyConfigured ? 'Bilgiyi değiştir' : '' }}"
                        class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-800 shadow-theme-xs outline-none focus:border-brand-300 focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                </x-ta.form.field>
                @if ($keyConfigured)
                    <label class="mt-3 flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                        <input type="checkbox" wire:model="clearApiKey" class="rounded border-gray-300" />
                        Clear stored API key
                    </label>
                @endif
            </div>
            <div class="mt-4 flex flex-wrap gap-2">
                <x-ta.button type="submit" size="sm">Kaydet</x-ta.button>
                <x-ta.button type="button" wire:click="testConfiguration" size="sm" variant="outline">Ayarları test et</x-ta.button>
                @if ($configured || $keyConfigured)
                    <x-ta.button type="button" wire:click="askRemove" size="sm" variant="outline">Bilgileri sil</x-ta.button>
                @endif
            </div>
        </form>
        @if ($confirmRemove)
            <div class="rounded-lg p-4 tone-look">
                <p class="text-sm font-medium text-gray-800 dark:text-white/90">Remove {{ $providerLabel }} API key?</p>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Kayıtlı anahtar silinir. Geçmiş yapay zekâ çalıştırmaları korunur.</p>
                <div class="mt-3 flex gap-2">
                    <x-ta.button wire:click="removeConfiguration" size="sm">Evet, sil</x-ta.button>
                    <x-ta.button wire:click="cancelRemove" size="sm" variant="outline">Vazgeç</x-ta.button>
                </div>
            </div>
        @endif
    @else
        <p class="text-sm text-gray-500">Only administrators can view or change {{ $providerLabel }} credentials.</p>
    @endif
</div>
