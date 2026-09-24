<?php

namespace App\Livewire\Operator\Portfolio;

use App\Models\Brand;
use App\Models\BrandDemandQuery;
use App\Models\BrandOffering;
use App\Services\Demand\BrandDemandBuilder;
use App\Support\Permissions;
use Illuminate\Contracts\View\View;
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

    public function mount(int $brandId): void
    {
        abort_unless(auth()->user()?->is_active && auth()->user()?->can(Permissions::ACCESS_APP), 403);
        $this->brandId = $brandId;
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
        ]);
    }
}
