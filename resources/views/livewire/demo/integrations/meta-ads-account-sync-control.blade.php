<section wire:poll.10s.visible>
    <div class="section-head">
        <h2 class="section-title" title="İlk kullanımda geçmiş veri otomatik alınır; sonraki kullanımlarda yalnızca eksik ve değişebilecek dönemler yenilenir.">Hesap bazlı güncelleme <span class="section-count">{{ count($accounts) }} bağlı hesap</span></h2>
    </div>

    @if ($feedback)
        <p @class([
            'card mb-3 px-4 py-3 text-sm',
            'text-success-700 dark:text-success-400' => $feedbackTone === 'success',
            'text-warning-800 dark:text-warning-400' => $feedbackTone !== 'success',
        ])>{{ $feedback }}</p>
    @endif

    <ul class="card">
        @forelse ($accounts as $account)
            <li class="card-row flex-wrap sm:flex-nowrap" wire:key="meta-account-sync-{{ $account['binding_id'] }}">
                @include('livewire.demo.integrations.partials.provider-mark', ['provider' => 'meta'])
                <div class="min-w-0 flex-1">
                    <p class="flex flex-wrap items-center gap-x-2.5 gap-y-0.5">
                        <span class="truncate text-sm font-medium text-gray-900 dark:text-white">{{ $account['name'] }}</span>
                        <x-ta.pill :tone="$account['active'] ? 'live' : match ($account['status']) { 'completed' => 'ok', 'partial', 'failed' => 'look', default => 'info' }">{{ $account['status_label'] }}</x-ta.pill>
                    </p>
                    <p class="mt-0.5 truncate text-[13px] text-gray-500 dark:text-gray-400" title="{{ $account['asset'] }}">ID {{ $account['external_id'] }}@if ($account['currency']), {{ $account['currency'] }}@endif. @if ($account['last_activity'])Son işlem {{ $account['last_activity'] }}. @endif{{ $account['asset'] }}@if ($account['brand']), {{ $account['brand'] }}@endif</p>
                </div>

                <button
                    type="button"
                    wire:click="updateAccount('{{ $account['binding_id'] }}')"
                    wire:loading.attr="disabled"
                    wire:target="updateAccount('{{ $account['binding_id'] }}')"
                    @disabled($account['active'])
                    class="btn w-full sm:w-auto"
                >
                    <svg wire:loading.class="animate-spin" wire:target="updateAccount('{{ $account['binding_id'] }}')" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M20 12a8 8 0 10-2.34 5.66M20 12V6m0 6h-6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <span wire:loading.remove wire:target="updateAccount('{{ $account['binding_id'] }}')">{{ $account['has_collection'] ? 'Şimdi Güncelle' : 'İlk Verileri Al' }}</span>
                    <span wire:loading wire:target="updateAccount('{{ $account['binding_id'] }}')">Başlatılıyor…</span>
                </button>
            </li>
        @empty
            <li><x-ta.empty-state size="sm" title="Henüz markaya bağlanmış Meta Ads reklam hesabı yok." /></li>
        @endforelse
    </ul>
</section>
