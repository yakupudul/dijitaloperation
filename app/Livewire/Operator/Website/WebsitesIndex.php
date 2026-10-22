<?php

namespace App\Livewire\Operator\Website;

use App\Models\DigitalAsset;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\Brand\BrandScope;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\Site\WebsiteConnections;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Web siteleri (sidebar), one list since Aşama 6: every website with its brand, connector version (one-click
 * "Güncelle" for admins), Search Console and GA4, last crawl, organic clicks of the last 28 days, open work and a state.
 * Filters: Tümü (brand-assigned), Sorun var, Eski eklenti, Markasız. Each row opens the website screen.
 */
#[Layout('operator.layouts.app')]
#[Title('Web siteleri')]
final class WebsitesIndex extends Component
{
    /** Filter => label. */
    public const array FILTERS = ['tumu' => 'Tümü', 'sorun' => 'Sorun var', 'eski' => 'Eski eklenti', 'markasiz' => 'Markasız'];

    #[Url(as: 'ara')]
    public string $search = '';

    #[Url(as: 'durum', except: 'tumu')]
    public string $filter = 'tumu';

    public string $message = '';

    public function setFilter(string $filter): void
    {
        $this->filter = isset(self::FILTERS[$filter]) ? $filter : 'tumu';
    }

    /** One click: the connector on this site updates itself to the version MoxDOP ships (admin; same as Entegrasyonlar). */
    public function updateConnector(int $siteId, ExternalWriteService $writes): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
        try {
            $writes->requestConnectorUpdate(auth()->user(), DigitalAsset::query()->findOrFail($siteId));
            $this->message = 'Eklenti '.config('moxdop-wordpress.connector_version').' güncellemesi kuyruğa alındı.';
        } catch (ValidationException $exception) {
            $this->message = (string) collect($exception->errors())->flatten()->first();
        }
    }

    public function render(): View
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && $actor->is_active, 403);

        $all = DigitalAsset::query()->with('brand.customer')->where('type', 'website')->orderBy('name')->orderBy('id')->get();
        $needle = mb_strtolower(trim($this->search));
        if ($needle !== '') {
            $all = $all->filter(fn (DigitalAsset $site): bool => str_contains(mb_strtolower($site->name.' '.$site->domain.' '.$site->primary_url.' '.$site->brand?->name), $needle))->values();
        }
        $ids = $all->pluck('id')->all();
        $status = WebsiteConnections::for($all);
        $assigned = $all->filter(fn (DigitalAsset $site): bool => $site->brand_id !== null)->values();
        $counts = [
            'tumu' => $assigned->count(),
            'sorun' => $assigned->filter(fn (DigitalAsset $s): bool => $status[$s->id]['problems'] !== [])->count(),
            'eski' => $all->filter(fn (DigitalAsset $s): bool => $status[$s->id]['outdated'])->count(),
            'markasiz' => $all->count() - $assigned->count(),
        ];
        $sites = match ($this->filter) {
            'sorun' => $assigned->filter(fn (DigitalAsset $s): bool => $status[$s->id]['problems'] !== [])->values(),
            'eski' => $all->filter(fn (DigitalAsset $s): bool => $status[$s->id]['outdated'])->values(),
            'markasiz' => $all->filter(fn (DigitalAsset $s): bool => $s->brand_id === null)->values(),
            default => $assigned,
        };

        return view('livewire.operator.website.websites-index', [
            'sites' => $sites,
            'status' => $status,
            'counts' => $counts,
            'clicks' => $this->clicks($ids),
            'open' => $this->openSuggestions($ids),
            'unassigned' => DigitalAsset::query()->where('type', 'website')->whereNull('brand_id')->count(),
            'latestConnector' => (string) config('moxdop-wordpress.connector_version'),
            'isAdmin' => (bool) $actor->hasRole(Roles::ADMIN),
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
        $bindings = collect();
        foreach (DigitalAsset::query()->whereIn('id', $siteIds)->get() as $site) {
            foreach (BrandScope::siteResources($site, 'search_console') as $resourceId) {
                $bindings->push((object) ['digital_asset_id' => $site->id, 'external_resource_id' => $resourceId]);
            }
        }
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
