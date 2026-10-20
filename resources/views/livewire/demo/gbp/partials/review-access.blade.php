{{-- Why reviews are missing: the last collection's gbp_reviews result, in plain Turkish (raw Google message beside it). --}}
@if (($reviewAccess['state'] ?? null) === 'unavailable')
    <section class="card" data-testid="review-access">
        <div class="card-row items-start">
            <span class="row-icon border-transparent bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-warning-400" aria-hidden="true"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg></span>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-medium text-gray-900 dark:text-white">Yorumlar toplanamıyor</p>
                <p class="mt-0.5 break-words text-[13px] text-gray-700 dark:text-gray-300">{{ $reviewAccess['reason'] }}</p>
                @if (filled($reviewAccess['raw'] ?? null))
                    <p class="mt-1 break-words text-xs text-gray-500">Google: {{ \Illuminate\Support\Str::limit($reviewAccess['raw'], 300) }}, {{ $reviewAccess['checked_at'] }}</p>
                @endif
            </div>
        </div>
    </section>
@elseif (($reviewAccess['state'] ?? null) === 'never')
    <p class="text-[13px] text-gray-500">Yorumlar henüz hiç toplanmadı; profil her gün otomatik toplanır. Hemen denemek için “Verileri yenile”.</p>
@endif
