<?php

namespace App\Livewire\Operator\Portfolio;

use App\Jobs\RunAreaSerpChecksJob;
use App\Models\Brand;
use App\Models\BrandDemandQuery;
use App\Models\BrandOffering;
use App\Models\BrandServiceArea;
use App\Services\Demand\AreaSerpChecker;
use App\Services\Demand\BrandDemandBuilder;
use App\Services\Demand\BrandedSplitReader;
use App\Support\Permissions;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Brand page › İşletme › Talep: the brand demand table per service (queries, clicks, impressions, top queries),
 * unassigned queries with a manual assignment (kept by the weekly rebuild), branded and out-of-area shares.
 */
final class BrandDemand extends Component
{
    #[Locked]
    public int $brandId;

    public string $message = '';

    public bool $serpEnabled = false;

    public string $serpCap = '';

    public function mount(int $brandId): void
    {
        abort_unless(auth()->user()?->is_active && auth()->user()?->can(Permissions::ACCESS_APP), 403);
        $this->brandId = $brandId;
        $brand = Brand::query()->findOrFail($brandId);
        $this->serpEnabled = (bool) $brand->demand_serp_enabled;
        $this->serpCap = number_format((float) ($brand->demand_serp_monthly_usd ?? config('moxdop-demand.serp.monthly_usd_per_brand', 2.0)), 2, '.', '');
    }

    /** Paid checks: admin only, capped per month. */
    public function saveSerp(): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
        $this->validate(['serpCap' => ['required', 'numeric', 'min:0', 'max:100']]);
        Brand::query()->whereKey($this->brandId)->firstOrFail()
            ->forceFill(['demand_serp_enabled' => $this->serpEnabled, 'demand_serp_monthly_usd' => round((float) $this->serpCap, 2)])->save();
        $this->message = $this->serpEnabled
            ? 'Bölge bazlı Google kontrolü açık; ayda en fazla '.$this->serpCap.' USD.'
            : 'Bölge bazlı Google kontrolü kapalı.';
    }

    public function runSerpNow(): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
        if (! Brand::query()->whereKey($this->brandId)->value('demand_serp_enabled')) {
            $this->message = 'Önce bölge bazlı Google kontrolünü açın.';

            return;
        }
        RunAreaSerpChecksJob::dispatch($this->brandId);
        $this->message = 'Kontrol arka planda başladı; birkaç dakika içinde sonuçlar burada görünür.';
    }

    public function rebuild(BrandDemandBuilder $builder): void
    {
        $stats = $builder->build(Brand::query()->findOrFail($this->brandId));
        $this->message = sprintf('%d sorgu güncellendi; %d tanesi bir hizmete atandı.', $stats['queries'], $stats['assigned']);
    }

    public function assign(int $queryId, string $offeringId): void
    {
        $query = BrandDemandQuery::query()->where('brand_id', $this->brandId)->findOrFail($queryId);
        $offering = $offeringId === '' ? null : BrandOffering::query()->where('brand_id', $this->brandId)->findOrFail((int) $offeringId);
        $query->forceFill(['brand_offering_id' => $offering?->id, 'assignment_source' => BrandDemandQuery::SOURCE_OPERATOR])->save();
        $this->message = '"'.$query->query.'" '.($offering !== null ? 'hizmete atandı.' : 'hizmetten çıkarıldı.');
    }

    /**
     * Latest check per keyword + area: our rank and the top three domains.
     *
     * @param  array<int, string>  $offerings
     * @return list<array<string, mixed>>
     */
    private function serpRows(array $offerings): array
    {
        $areas = BrandServiceArea::query()->where('brand_id', $this->brandId)->get()->keyBy('id');

        return DB::table('demand_serp_checks')->where('brand_id', $this->brandId)->whereIn('status', ['completed', 'reused'])
            ->orderByDesc('checked_at')->limit(300)->get()
            ->unique(fn ($row): string => mb_strtolower((string) $row->keyword).'|'.$row->location_code)
            ->take(40)
            ->map(fn ($row): array => [
                'service' => $offerings[$row->brand_offering_id] ?? '—',
                'keyword' => (string) $row->keyword,
                'area' => $row->brand_service_area_id !== null ? (string) ($areas[$row->brand_service_area_id]?->label() ?? '—') : 'Genel',
                'rank' => $row->our_rank,
                'top' => collect((array) json_decode((string) $row->results, true))->take(3)->pluck('domain')->all(),
                'checked' => Carbon::parse($row->checked_at)->timezone('Europe/Istanbul')->format('d.m.Y'),
            ])->values()->all();
    }

    public function render(): View
    {
        $rows = BrandDemandQuery::query()->where('brand_id', $this->brandId)->where('value_score', '>', 0)->get();
        $offerings = BrandOffering::query()->with('primaryName')->where('brand_id', $this->brandId)->where('status', 'active')->get()
            ->mapWithKeys(fn (BrandOffering $o): array => [$o->id => (string) ($o->primaryName?->raw_label ?? 'Hizmet #'.$o->id)]);
        $services = $rows->whereNotNull('brand_offering_id')->groupBy('brand_offering_id')
            ->map(fn ($group, $id): array => [
                'name' => $offerings[$id] ?? 'Hizmet #'.$id,
                'queries' => $group->count(),
                'clicks' => (int) $group->sum('gsc_clicks'),
                'impressions' => (int) $group->sum('gsc_impressions'),
                'conversions' => round((float) $group->sum('ads_conversions'), 1),
                'value' => (float) $group->sum('value_score'),
                'top' => $group->where('is_branded', false)->sortByDesc('value_score')->take(3)->pluck('query')->all(),
            ])->sortByDesc('value')->values()->all();

        return view('livewire.operator.portfolio.brand-demand', [
            'services' => $services,
            'unassigned' => $rows->whereNull('brand_offering_id')->where('is_branded', false)->sortByDesc('value_score')->take(10)->values(),
            'offerings' => $offerings->all(),
            'total' => $rows->count(),
            'branded' => $rows->where('is_branded', true)->count(),
            'outOfArea' => $rows->where('location_status', 'out_of_area')->count(),
            'builtAt' => BrandDemandQuery::query()->where('brand_id', $this->brandId)->max('built_at'),
            'serpRows' => $this->serpRows($offerings->all()),
            'serpSpent' => app(AreaSerpChecker::class)->spentThisMonth(Brand::query()->findOrFail($this->brandId)),
            'isAdmin' => (bool) auth()->user()?->hasRole(Roles::ADMIN),
            'split' => app(BrandedSplitReader::class)->monthly(Brand::query()->findOrFail($this->brandId)),
        ]);
    }
}
