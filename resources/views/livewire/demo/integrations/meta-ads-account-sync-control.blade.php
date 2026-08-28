<section class="overflow-hidden rounded-2xl bg-white shadow-theme-xs ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
    <div class="flex flex-col gap-3 border-b border-gray-100 px-5 py-5 sm:flex-row sm:items-start sm:justify-between dark:border-gray-800 md:px-6">
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.14em] text-gray-400">Hesap Bazlı Güncelleme</p>
            <h2 class="mt-1 text-lg font-semibold text-gray-900 dark:text-white">Bağlı Meta Ads hesaplarını ayrı ayrı güncelleyin</h2>
            <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">Her buton yalnızca seçtiğiniz reklam hesabını günceller. İlk kullanımda geçmiş veri otomatik alınır; sonraki kullanımlarda yalnızca eksik ve değişebilecek dönemler yenilenir.</p>
        </div>
        <span class="inline-flex shrink-0 items-center rounded-full bg-brand-50 px-2.5 py-1 text-xs font-semibold text-brand-700 ring-1 ring-inset ring-brand-200 dark:bg-brand-500/10 dark:text-brand-300 dark:ring-brand-500/20">{{ count($accounts) }} bağlı hesap</span>
    </div>

    @if ($feedback)
        <div @class([
            'mx-5 mt-4 rounded-xl px-4 py-3 text-sm ring-1 ring-inset md:mx-6',
            'bg-success-50 text-success-700 ring-success-200 dark:bg-success-500/10 dark:text-success-300 dark:ring-success-500/20' => $feedbackTone === 'success',
            'bg-warning-50 text-warning-800 ring-warning-200 dark:bg-warning-500/10 dark:text-warning-300 dark:ring-warning-500/20' => $feedbackTone !== 'success',
        ])>{{ $feedback }}</div>
    @endif

    @if (empty($accounts))
        <div class="px-5 py-8 text-center text-sm text-gray-500 md:px-6">Henüz markaya bağlanmış Meta Ads reklam hesabı yok.</div>
    @else
        <div class="divide-y divide-gray-100 dark:divide-gray-800">
            @foreach ($accounts as $account)
                <div class="flex flex-col gap-4 px-5 py-4 lg:flex-row lg:items-center lg:justify-between md:px-6" wire:key="meta-account-sync-{{ $account['binding_id'] }}">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $account['name'] }}</p>
                            <span @class([
                                'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset',
                                'bg-brand-50 text-brand-700 ring-brand-200 dark:bg-brand-500/10 dark:text-brand-300 dark:ring-brand-500/20' => $account['active'],
                                'bg-success-50 text-success-700 ring-success-200 dark:bg-success-500/10 dark:text-success-300 dark:ring-success-500/20' => ! $account['active'] && $account['status'] === 'completed',
                                'bg-warning-50 text-warning-700 ring-warning-200 dark:bg-warning-500/10 dark:text-warning-300 dark:ring-warning-500/20' => ! $account['active'] && in_array($account['status'], ['partial', 'failed'], true),
                                'bg-gray-100 text-gray-600 ring-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700' => ! $account['active'] && ! in_array($account['status'], ['completed', 'partial', 'failed'], true),
                            ])>
                                @if ($account['active'])<span class="h-1.5 w-1.5 animate-pulse rounded-full bg-brand-500"></span>@endif
                                {{ $account['status_label'] }}
                            </span>
                        </div>
                        <p class="mt-1 text-xs text-gray-500">ID {{ $account['external_id'] }} @if ($account['currency']) · {{ $account['currency'] }} @endif</p>
                        <p class="mt-1 text-xs text-gray-500">{{ $account['asset'] }} @if ($account['brand']) · {{ $account['brand'] }} @endif @if ($account['last_activity']) · Son işlem {{ $account['last_activity'] }} @endif</p>
                    </div>

                    <button
                        type="button"
                        wire:click="updateAccount('{{ $account['binding_id'] }}')"
                        wire:loading.attr="disabled"
                        wire:target="updateAccount('{{ $account['binding_id'] }}')"
                        @disabled($account['active'])
                        class="inline-flex min-h-10 shrink-0 items-center justify-center gap-2 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-semibold text-white shadow-theme-xs transition hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-50"
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
