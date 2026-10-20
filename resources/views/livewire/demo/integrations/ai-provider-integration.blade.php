@php
    // Panel concept 2026-10-20: "Bu yapay zekâ sağlayıcısının anahtarı kayıtlı ve çalışıyor mu?" State first, then the form.
    $status = \App\Support\Integrations\Presentation\OperatorLabel::turkish($statusLabel);
    $note = \App\Support\Integrations\Presentation\OperatorLabel::turkish($supportNote);
@endphp
<div class="space-y-10">
    @include('livewire.demo.partials.flash')

    <header class="flex flex-wrap items-start justify-between gap-3">
        <div class="flex min-w-0 items-start gap-3">
            @include('livewire.demo.integrations.partials.provider-mark', ['provider' => $provider, 'size' => 'lg'])
            <div class="min-w-0">
                <h1 class="page-title">{{ $providerLabel }}</h1>
                <p class="page-sub flex flex-wrap items-center gap-x-3 gap-y-1">
                    <x-ta.pill :tone="$configured ? 'ok' : 'info'">{{ $status }}</x-ta.pill>
                    <span class="min-w-0">{{ $note }}</span>
                </p>
            </div>
        </div>
        <a href="{{ route('operator.integrations') }}" wire:navigate class="btn-quiet">Tüm entegrasyonlar</a>
    </header>

    <section>
        <div class="section-head"><h2 class="section-title">Bağlantı durumu</h2></div>
        <dl class="card">
            <div class="card-row flex-wrap">
                <dt class="w-40 shrink-0 text-[13px] text-gray-500">API anahtarı</dt>
                <dd class="min-w-0 flex-1 text-sm text-gray-900 dark:text-white">{{ $status }}</dd>
            </div>
            <div class="card-row flex-wrap">
                <dt class="w-40 shrink-0 text-[13px] text-gray-500">Son doğrulama</dt>
                <dd class="min-w-0 flex-1 text-sm text-gray-900 dark:text-white">{{ $lastTestedAt ?? 'Test edilmedi' }}</dd>
            </div>
        </dl>
    </section>

    @if ($canManageCredentials)
        <section>
            <div class="section-head"><h2 class="section-title">API anahtarı</h2></div>
            <form wire:submit.prevent="saveConfiguration" class="card p-5">
                <x-ta.form.field label="API Key" :helper="$keyConfigured ? 'Kayıtlı; değiştirmeyecekseniz boş bırakın.' : 'Yalnız yazılır; kaydedildikten sonra gösterilmez.'" :error="$errors->first('api_key')">
                    <input wire:model="apiKey" type="password" autocomplete="new-password" placeholder="{{ $keyConfigured ? 'Bilgiyi değiştir' : '' }}"
                        class="h-9 w-full max-w-xl rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 outline-none focus:border-gray-400 dark:border-gray-700 dark:bg-gray-900 dark:text-white" />
                </x-ta.form.field>
                @if ($keyConfigured)
                    <label class="mt-3 flex items-center gap-2 text-[13px] text-gray-600 dark:text-gray-300">
                        <input type="checkbox" wire:model="clearApiKey" class="rounded border-gray-300" />
                        Kayıtlı API anahtarını sil
                    </label>
                @endif
                <div class="mt-5 flex flex-wrap gap-2">
                    <button type="submit" class="btn-primary w-full sm:w-auto">Kaydet</button>
                    <button type="button" wire:click="testConfiguration" class="btn w-full sm:w-auto">Ayarları test et</button>
                    @if ($configured || $keyConfigured)
                        <button type="button" wire:click="askRemove" class="btn-quiet w-full hover:text-error-600! sm:w-auto dark:hover:text-error-400!">Bilgileri sil</button>
                    @endif
                </div>
            </form>
            @if ($confirmRemove)
                <div class="card mt-4">
                    <div class="card-row flex-wrap sm:flex-nowrap">
                        <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-warning-500" aria-hidden="true"></span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $providerLabel }} API anahtarı silinsin mi?</p>
                            <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">Kayıtlı anahtar silinir. Geçmiş yapay zekâ çalıştırmaları korunur.</p>
                        </div>
                        <button type="button" wire:click="cancelRemove" class="btn w-full sm:w-auto">Vazgeç</button>
                        <button type="button" wire:click="removeConfiguration" class="btn-primary w-full sm:w-auto">Evet, sil</button>
                    </div>
                </div>
            @endif
        </section>
    @else
        <p class="card px-4 py-3 text-[13px] text-gray-500">{{ $providerLabel }} bilgilerini yalnız yöneticiler görebilir ve değiştirebilir.</p>
    @endif
</div>
