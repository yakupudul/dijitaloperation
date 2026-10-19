{{-- Why reviews are missing: the last collection's gbp_reviews result, in plain Turkish (raw Google message beside it). --}}
@if (($reviewAccess['state'] ?? null) === 'unavailable')
    <section class="tone-look rounded-xl p-4 text-sm" data-testid="review-access">
        <p class="font-semibold">Yorumlar toplanamıyor</p>
        <p class="mt-1 break-words">{{ $reviewAccess['reason'] }}</p>
        @if (filled($reviewAccess['raw'] ?? null))
            <p class="mt-1 break-words text-xs opacity-70">Google: {{ \Illuminate\Support\Str::limit($reviewAccess['raw'], 300) }} · {{ $reviewAccess['checked_at'] }}</p>
        @endif
    </section>
@elseif (($reviewAccess['state'] ?? null) === 'never')
    <p class="rounded-lg bg-gray-50 px-4 py-2 text-xs text-gray-500 dark:bg-white/[0.03] dark:text-gray-400">Yorumlar henüz hiç toplanmadı; profil her gün otomatik toplanır. Hemen denemek için “Verileri yenile”.</p>
@endif
