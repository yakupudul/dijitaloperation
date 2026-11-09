@php
    use App\Models\BrandDirectionChange;
@endphp
{{-- Marka rotası (yakup, 2026-11-09): when the brand's direction changes, clusters, content ideas, profile posts and demand follow it. --}}
<section data-brand-direction>
    <div class="section-head">
        <h2 class="section-title" title="Hizmetler, ana hizmetler, özet ve konumlandırma değişince sistem bunu fark eder; kümeler, içerik fikirleri, İşletme Profili taslakları ve arama talebi yeni rotaya göre yeniden hizalanır.">Marka rotası</h2>
        @if ($isAdmin && ! $confirming)
            <button type="button" wire:click="$set('confirming', true)" class="btn ml-auto" data-brand-direction-open>Rota değişti</button>
        @endif
    </div>
    @if ($message !== '')
        <p role="status" class="mb-3 text-[13px] text-gray-600 dark:text-gray-300">{{ $message }}</p>
    @endif
    @if ($confirming)
        <div class="card mb-3 space-y-3 p-4 text-[13px]" data-brand-direction-confirm>
            <p class="text-gray-700 dark:text-gray-200">Markanın kümeleri ve henüz siteye gitmemiş yazı fikirleri şimdiki rotaya göre okunur; uymayan küme bu marka için kenara alınır, uymayan fikir kapanır (ikisi de geri alınabilir). Gelecek günlerin onaylanmamış İşletme Profili taslakları yeniden yazılır, yeni hizmetler için arama talebi toplanır.</p>
            <input wire:model="reason" type="text" maxlength="500" placeholder="Ne değişti? (isteğe bağlı)" class="h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-white" />
            <div class="flex gap-2">
                <button type="button" wire:click="realign" wire:loading.attr="disabled" class="btn-primary" data-brand-direction-go>Yeniden hizala</button>
                <button type="button" wire:click="$set('confirming', false)" class="btn">Vazgeç</button>
            </div>
        </div>
    @endif
    <div class="card divide-y divide-gray-200 text-[13px] dark:divide-gray-800">
        <div class="px-4 py-3">
            <p class="text-gray-800 dark:text-gray-200">{{ $now['positioning'] !== '' ? $now['positioning'] : ($now['summary'] !== '' ? $now['summary'] : 'Konumlandırma yazılmamış.') }}</p>
            <p class="mt-1 text-gray-500">{{ count($now['services']) }} hizmet{{ $now['main'] !== [] ? ' · ★ '.implode(', ', $now['main']) : '' }}</p>
        </div>
        @forelse ($changes as $change)
            @php
                $r = (array) $change->result;
                $done = array_filter([
                    ($r['clusters_set_aside'] ?? 0) > 0 ? $r['clusters_set_aside'].' küme kenara alındı' : null,
                    (($r['ideas_closed'] ?? 0) + ($r['ideas_of_removed_services'] ?? 0) + ($r['drafts_closed'] ?? 0)) > 0 ? (($r['ideas_closed'] ?? 0) + ($r['ideas_of_removed_services'] ?? 0) + ($r['drafts_closed'] ?? 0)).' fikir kapandı' : null,
                    ($r['gbp_drafts_skipped'] ?? 0) > 0 ? $r['gbp_drafts_skipped'].' Profil taslağı yeniden yazılacak' : null,
                    ($r['fit_waiting'] ?? 0) > 0 ? $r['fit_waiting'].' kayıt Claude\'un okumasını bekliyor' : null,
                ]);
            @endphp
            <div class="flex flex-col gap-0.5 px-4 py-2.5" wire:key="direction-{{ $change->id }}" data-brand-direction-change="{{ $change->magnitude }}">
                <p class="text-gray-900 dark:text-white">
                    <span class="font-medium">{{ BrandDirectionChange::MAGNITUDE_LABELS[$change->magnitude] ?? $change->magnitude }}</span>
                    <span class="text-gray-500">· {{ $change->created_at?->timezone('Europe/Istanbul')->format('d.m.Y H:i') }}{{ $change->trigger === 'operator' ? ' · elle' : '' }}{{ $change->magnitude !== BrandDirectionChange::BASELINE && $change->applied_at === null ? ' · sürüyor' : '' }}</span>
                </p>
                @if ($change->reason)
                    <p class="text-gray-700 dark:text-gray-300">{{ $change->reason }}</p>
                @endif
                @if ((array) $change->added !== [] || (array) $change->removed !== [])
                    <p class="text-gray-500">@if ((array) $change->added !== [])Eklenen: {{ implode(', ', (array) $change->added) }}@endif @if ((array) $change->removed !== [])· Çıkan: {{ implode(', ', (array) $change->removed) }}@endif</p>
                @endif
                @if ($done !== [])
                    <p class="text-gray-500">{{ implode(' · ', $done) }}</p>
                @endif
            </div>
        @empty
            <p class="px-4 py-3 text-gray-500">Henüz bir rota kaydı yok; ilk gece kontrolü başlangıcı yazar.</p>
        @endforelse
    </div>
</section>
