<section wire:poll.10s.visible>
    <div class="flex flex-col gap-3 pb-2 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 class="section-title" title="İlk kullanımda geçmiş veri otomatik alınır; sonraki kullanımlarda yalnızca eksik ve değişebilecek dönemler yenilenir.">Hesap bazlı güncelleme <span class="text-sm font-normal text-gray-500">{{ count($accounts) }} bağlı hesap</span></h2>
        </div>
    </div>

    @if ($feedback)
        <div @class([
            'mt-2 rounded-lg px-4 py-3 text-sm',
            'tone-ok' => $feedbackTone === 'success',
            'tone-look' => $feedbackTone !== 'success',
        ])>{{ $feedback }}</div>
    @endif

    @if (empty($accounts))
        <div class="py-6 text-sm text-gray-500">Henüz markaya bağlanmış Meta Ads reklam hesabı yok.</div>
    @else
        <div class="divide-y divide-gray-200 dark:divide-gray-800">
            @foreach ($accounts as $account)
                <div class="flex flex-col gap-3 py-3 lg:flex-row lg:items-center lg:justify-between" wire:key="meta-account-sync-{{ $account['binding_id'] }}">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $account['name'] }}</p>
                            <x-ta.pill :tone="$account['active'] ? 'live' : match ($account['status']) { 'completed' => 'ok', 'partial', 'failed' => 'look', default => 'info' }">{{ $account['status_label'] }}</x-ta.pill>
                        </div>
                        <p class="mt-1 text-xs text-gray-500">ID {{ $account['external_id'] }}@if ($account['currency']), {{ $account['currency'] }}@endif</p>
                        <p class="mt-1 text-xs text-gray-500">{{ $account['asset'] }}@if ($account['brand']), {{ $account['brand'] }}@endif @if ($account['last_activity']) · Son işlem {{ $account['last_activity'] }} @endif</p>
                    </div>

                    <button
                        type="button"
                        wire:click="updateAccount('{{ $account['binding_id'] }}')"
                        wire:loading.attr="disabled"
                        wire:target="updateAccount('{{ $account['binding_id'] }}')"
                        @disabled($account['active'])
                        class="inline-flex shrink-0 items-center justify-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 dark:text-gray-200 dark:ring-gray-700"
                    >
                        <svg wire:loading.class="animate-spin" wire:target="updateAccount('{{ $account['binding_id'] }}')" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M20 12a8 8 0 10-2.34 5.66M20 12V6m0 6h-6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <span wire:loading.remove wire:target="updateAccount('{{ $account['binding_id'] }}')">{{ $account['has_collection'] ? 'Şimdi Güncelle' : 'İlk Verileri Al' }}</span>
                        <span wire:loading wire:target="updateAccount('{{ $account['binding_id'] }}')">Başlatılıyor…</span>
                    </button>
                </div>
            @endforeach
        </div>
    @endif
</section>
