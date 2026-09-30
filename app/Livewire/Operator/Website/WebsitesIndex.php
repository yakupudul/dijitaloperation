<?php

namespace App\Livewire\Operator\Website;

use App\Models\DigitalAsset;
use App\Models\Suggestion;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Web siteleri (sidebar): the brand-assigned websites — brand, domain, organic clicks of the last 28 days (Search
 * Console property totals), open suggestions — each row opens the website screen.
 */
#[Layout('operator.layouts.app')]
#[Title('Web siteleri')]
final class WebsitesIndex extends Component
{
    #[Url(as: 'ara')]
    public string $search = '';

    public function render(): View
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && $actor->is_active, 403);

        $sites = DigitalAsset::query()->with('brand.customer')->where('type', 'website')->whereNotNull('brand_id')
            ->orderBy('name')->orderBy('id')->get();
        $needle = mb_strtolower(trim($this->search));
        if ($needle !== '') {
            $sites = $sites->filter(fn (DigitalAsset $site): bool => str_contains(mb_strtolower($site->name.' '.$site->domain.' '.$site->primary_url.' '.$site->brand?->name), $needle))->values();
        }
        $ids = $sites->pluck('id')->all();

        return view('livewire.operator.website.websites-index', [
            'sites' => $sites,
            'clicks' => $this->clicks($ids),
            'open' => $this->openSuggestions($ids),
            'unassigned' => DigitalAsset::query()->where('type', 'website')->whereNull('brand_id')->count(),
        ]);
    }

    /**
     * @param  list<int>  $siteIds
     * @return array<int, int> site id => clicks of the last 28 days
     */
    private function clicks(array $siteIds): array
    {
        if ($siteIds === []) {
            return [];
        }
        $bindings = DB::table('core_asset_bindings')->whereIn('digital_asset_id', $siteIds)->where('capability', 'search_console')->where('status', 'active')
            ->orderBy('id')->get(['digital_asset_id', 'external_resource_id']);
        if ($bindings->isEmpty()) {
            return [];
        }
        $byResource = DB::table('gsc_property_daily')->whereIn('external_resource_id', $bindings->pluck('external_resource_id')->unique()->values()->all())
            ->where('search_type', 'web')->where('reporting_date', '>=', now()->subDays(29)->toDateString())
            ->groupBy('external_resource_id')->selectRaw('external_resource_id, sum(clicks) as clicks')->pluck('clicks', 'external_resource_id')->all();
        $out = [];
        foreach ($bindings as $binding) {
            $out[(int) $binding->digital_asset_id] = ($out[(int) $binding->digital_asset_id] ?? 0) + (int) ($byResource[$binding->external_resource_id] ?? 0);
        }

        return $out;
    }

    /**
     * @param  list<int>  $siteIds
     * @return array<int, int> site id => open search suggestions on its pages
     */
    private function openSuggestions(array $siteIds): array
    {
        if ($siteIds === []) {
            return [];
        }

        return DB::table('suggestions as s')->join('pages as p', 'p.id', '=', 's.page_id')->whereIn('p.website_asset_id', $siteIds)
            ->where('s.status', Suggestion::OPEN)->groupBy('p.website_asset_id')->selectRaw('p.website_asset_id as site_id, count(*) as n')
            ->pluck('n', 'site_id')->map(fn ($n): int => (int) $n)->all();
    }
}
