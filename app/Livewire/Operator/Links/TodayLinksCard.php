<?php

namespace App\Livewire\Operator\Links;

use App\Models\BacklinkSource;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

/** Komuta's "Bağlantılar" box (Backlink hizmeti, 2026-11-06): ready applications, own calls / visits, follow-ups due. */
final class TodayLinksCard extends Component
{
    public function render(): View
    {
        $base = fn (): Builder => BacklinkSource::query()->whereHas('brand', fn (Builder $q) => $q->operational());

        return view('livewire.operator.links.today-links-card', [
            'ready' => $base()->where('status', BacklinkSource::NONE)->where('lane', 'masa')->where('material_status', 'ready')->count(),
            'person' => $base()->where('status', BacklinkSource::NONE)->where('lane', 'sen')->whereNotNull('material_status')->count(),
            'late' => $base()->where('status', BacklinkSource::APPLIED)->where('sent_at', '<', now()->subDays(BacklinkSource::FOLLOW_UP_DAYS))->count(),
            'won' => $base()->where('status', BacklinkSource::VERIFIED)->where('verified_at', '>=', now('Europe/Istanbul')->startOfMonth()->utc())->count(),
        ]);
    }

    public function placeholder(): string
    {
        return '<div class="card" data-links-today><div class="card-row"><p class="text-sm text-gray-500">Bağlantılar yükleniyor…</p></div></div>';
    }
}
