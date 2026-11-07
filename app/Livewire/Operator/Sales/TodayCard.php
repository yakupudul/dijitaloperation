<?php

namespace App\Livewire\Operator\Sales;

use App\Services\Inbox\InboxItem;
use App\Services\Inbox\InboxQueue;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Komuta's Moximu line (Aşama 2-3): what the Gelen kutusu holds today in one sentence ("3 cevap borcu, 2 adım, 1
 * gecikmiş ödeme") and its first lines; the work itself is done in the Gelen kutusu.
 */
final class TodayCard extends Component
{
    public function render(InboxQueue $queue): View
    {
        $items = $queue->items()->filter(fn (InboxItem $i): bool => $i->owed || $i->due)->values();
        $parts = array_filter([
            ($owed = $items->filter(fn (InboxItem $i): bool => $i->owed)->count()) > 0 ? $owed.' cevap borcu' : null,
            ($steps = $items->filter(fn (InboxItem $i): bool => $i->due && ! $i->owed && $i->type !== InboxItem::RISK)->count()) > 0 ? $steps.' adım' : null,
            ($risks = $items->filter(fn (InboxItem $i): bool => $i->type === InboxItem::RISK)->count()) > 0 ? $risks.' müşteri sinyali' : null,
        ]);

        return view('livewire.operator.sales.today-card', [
            'items' => $items->take(5),
            'count' => $items->count(),
            'line' => $parts !== [] ? implode(', ', $parts) : 'kutu boş',
        ]);
    }

    public function placeholder(): string
    {
        return '<div class="card" data-sales-today><div class="card-row"><p class="text-sm text-gray-500">Gelen kutusu yükleniyor…</p></div></div>';
    }
}
