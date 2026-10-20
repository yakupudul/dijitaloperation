{{-- Why reviews are missing: the last collection's gbp_reviews result, in plain Turkish (raw Google message beside it). --}}
@if (($reviewAccess['state'] ?? null) === 'unavailable')
    <section class="text-sm" data-testid="review-access">
        <x-ta.pill tone="look" class="text-sm font-medium">Yorumlar toplanamıyor</x-ta.pill>
        <p class="mt-1 break-words text-gray-700 dark:text-gray-300">{{ $reviewAccess['reason'] }}</p>
        @if (filled($reviewAccess['raw'] ?? null))
            <p class="mt-1 break-words text-xs text-gray-500">Google: {{ \Illuminate\Support\Str::limit($reviewAccess['raw'], 300) }}, {{ $reviewAccess['checked_at'] }}</p>
        @endif
    </section>
@elseif (($reviewAccess['state'] ?? null) === 'never')
    <p class="text-xs text-gray-500">Yorumlar henüz hiç toplanmadı; profil her gün otomatik toplanır. Hemen denemek için “Verileri yenile”.</p>
@endif
