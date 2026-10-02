{{-- Why reviews are missing: the last collection's gbp_reviews result, in plain Turkish (raw Google message beside it). --}}
@if (($reviewAccess['state'] ?? null) === 'unavailable')
    <section class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm dark:border-rose-900/50 dark:bg-rose-950/20" data-testid="review-access">
        <p class="font-semibold text-rose-900 dark:text-rose-200">Yorumlar toplanamıyor</p>
        <p class="mt-1 text-rose-800 dark:text-rose-300">{{ $reviewAccess['reason'] }}</p>
        @if (filled($reviewAccess['raw'] ?? null))
            <p class="mt-1 break-words text-xs text-rose-700/70 dark:text-rose-300/70">Google: {{ \Illuminate\Support\Str::limit($reviewAccess['raw'], 300) }} · {{ $reviewAccess['checked_at'] }}</p>
        @endif
    </section>
@elseif (($reviewAccess['state'] ?? null) === 'never')
    <p class="rounded-lg bg-gray-50 px-4 py-2 text-xs text-gray-500 dark:bg-white/[0.03] dark:text-gray-400">Yorumlar henüz hiç toplanmadı; profil her gün otomatik toplanır. Hemen denemek için “Verileri yenile”.</p>
@endif
