<?php

namespace App\Livewire\Operator\Market;

use App\Enums\CustomerStatus;
use App\Jobs\GeocodeServiceAreasJob;
use App\Models\Brand;
use App\Models\Intel\BrandIntelSetting;
use App\Models\Intel\MapGridRun;
use App\Services\Intel\BrandGbpIdentity;
use App\Services\Intel\DataForSeoTaskQueue;
use App\Services\Intel\KmlBuilder;
use App\Services\Intel\MapGridService;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Pazar › Harita sıralaması (Faz 8): Google Maps grid scans per brand and keyword — heat map, ARP / ATRP / SoLV,
 * trend and which businesses take the top 3 around the brand. Settings and scans are admin-only (paid).
 */
#[Layout('operator.layouts.app')]
#[Title('Harita sıralaması')]
final class MapRankingsPage extends Component
{
    #[Url]
    public ?int $brand = null;

    #[Url]
    public ?int $run = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public string $scanKeyword = '';

    public string $message = '';

    public string $error = '';

    public function updatedBrand(): void
    {
        $this->run = null;
        $this->form = [];
    }

    public function saveSettings(): void
    {
        $this->admin();
        $brand = $this->selectedBrand() ?? abort(404);
        $data = validator($this->form, [
            'monthly_usd' => ['required', 'numeric', 'min:0', 'max:500'],
            'grid_enabled' => ['boolean'],
            'keywords' => ['nullable', 'string', 'max:2000'],
            'grid_center_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'grid_center_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'grid_size' => ['required', 'integer', 'in:'.implode(',', (array) config('moxdop-intel.grid.sizes', [3, 5, 7, 9]))],
            'grid_spacing_km' => ['required', 'numeric', 'min:0.2', 'max:20'],
            'grid_every_days' => ['required', 'integer', 'min:1', 'max:90'],
            'gbp_place_id' => ['nullable', 'string', 'max:120'],
            'gbp_cid' => ['nullable', 'regex:/^\d{5,25}$/'],
        ])->validate();
        $keywords = array_slice(array_values(array_unique(array_filter(array_map('trim', preg_split('/[\r\n]+/', (string) ($data['keywords'] ?? '')) ?: [])))), 0, (int) config('moxdop-intel.grid.max_keywords', 5));
        $settings = BrandIntelSetting::for($brand);
        $settings->fill([
            'monthly_usd' => (float) $data['monthly_usd'],
            'grid_enabled' => (bool) ($data['grid_enabled'] ?? false),
            'grid_keywords' => $keywords,
            'grid_center_lat' => filled($data['grid_center_lat'] ?? null) ? (float) $data['grid_center_lat'] : null,
            'grid_center_lng' => filled($data['grid_center_lng'] ?? null) ? (float) $data['grid_center_lng'] : null,
            'grid_size' => (int) $data['grid_size'],
            'grid_spacing_km' => (float) $data['grid_spacing_km'],
            'grid_every_days' => (int) $data['grid_every_days'],
            'gbp_place_id' => filled($data['gbp_place_id'] ?? null) ? trim((string) $data['gbp_place_id']) : null,
            'gbp_cid' => filled($data['gbp_cid'] ?? null) ? trim((string) $data['gbp_cid']) : null,
            'updated_by' => auth()->id(),
        ])->save();
        $this->form = [];
        $this->error = '';
        $this->message = 'Ayarlar kaydedildi.';
    }

    public function scan(MapGridService $grid): void
    {
        $this->admin();
        $brand = $this->selectedBrand() ?? abort(404);
        try {
            $run = $grid->start($brand, $this->scanKeyword, auth()->user());
            $this->run = $run->id;
            $this->error = '';
            $this->message = sprintf('"%s" için %d noktalı tarama kuyruğa alındı; sonuçlar birkaç dakikada gelir.', $run->keyword, $run->points_total);
        } catch (ValidationException $exception) {
            $this->message = '';
            $this->error = (string) collect($exception->errors())->flatten()->first();
        }
    }

    /** Faz 8b: find service-area coordinates in the background (OpenStreetMap, 1 request per second). */
    public function geocodeAreas(): void
    {
        $this->admin();
        $brand = $this->selectedBrand() ?? abort(404);
        GeocodeServiceAreasJob::dispatch((int) $brand->id);
        $this->error = '';
        $this->message = 'Hizmet bölgelerinin konumu arka planda aranıyor (bölge başına ~1 sn).';
    }

    /** Faz 8b: mark the day the pinned map was published, or clear it. */
    public function toggleExperiment(): void
    {
        $this->admin();
        $settings = BrandIntelSetting::for($this->selectedBrand() ?? abort(404));
        $settings->forceFill(['kml_experiment_started_on' => $settings->kml_experiment_started_on === null ? now()->toDateString() : null, 'updated_by' => auth()->id()])->save();
        $this->error = '';
        $this->message = $settings->kml_experiment_started_on !== null ? 'Deney başlangıcı bugün olarak kaydedildi; önce/sonra grid taramalarıyla karşılaştırılır.' : 'Deney tarihi silindi.';
    }

    public function render(BrandGbpIdentity $identity, DataForSeoTaskQueue $queue, MapGridService $grid, KmlBuilder $kml): View
    {
        $brands = Brand::query()->whereHas('customer', fn ($q) => $q->where('status', CustomerStatus::Active->value))->orderBy('name')->get(['id', 'name']);
        $this->brand ??= $brands->first()?->id;
        $brand = $this->selectedBrand();
        $settings = $brand !== null ? BrandIntelSetting::for($brand) : null;
        if ($settings !== null && $this->form === []) {
            $this->form = [
                'monthly_usd' => $settings->monthly_usd, 'grid_enabled' => $settings->grid_enabled, 'keywords' => implode("\n", $settings->keywords()),
                'grid_center_lat' => $settings->grid_center_lat, 'grid_center_lng' => $settings->grid_center_lng, 'grid_size' => $settings->grid_size,
                'grid_spacing_km' => $settings->grid_spacing_km, 'grid_every_days' => $settings->grid_every_days,
                'gbp_place_id' => $settings->gbp_place_id, 'gbp_cid' => $settings->gbp_cid,
            ];
            $this->scanKeyword = $settings->keywords()[0] ?? '';
        }
        $runs = $brand !== null ? MapGridRun::query()->where('brand_id', $brand->id)->latest('started_at')->latest('id')->limit(40)->get() : collect();
        $selected = $this->run !== null ? $runs->firstWhere('id', $this->run) : $runs->first();
        $previous = $selected !== null ? $runs->where('keyword', $selected->keyword)->where('id', '<', $selected->id)->whereIn('status', [MapGridRun::STATUS_COMPLETED, MapGridRun::STATUS_PARTIAL])->first() : null;

        return view('livewire.operator.market.map-rankings', [
            'brands' => $brands,
            'settings' => $settings,
            'identity' => $brand !== null ? $identity->for($brand) : null,
            'spent' => $brand !== null ? $queue->spentThisMonth((int) $brand->id) : 0.0,
            'estimate' => $settings !== null ? $grid->estimate((int) $settings->grid_size) : 0.0,
            'latest' => $runs->groupBy('keyword')->map(fn ($group) => $group->first())->values(),
            'selected' => $selected,
            'previous' => $previous,
            'points' => $selected?->points()->orderBy('row')->orderBy('col')->get(),
            'competitors' => $selected !== null ? MapGridService::competitors($selected) : [],
            'isAdmin' => (bool) auth()->user()?->hasRole(Roles::ADMIN),
            'connected' => $queue->available(),
            'kml' => $brand !== null ? $kml->build($brand) : null,
            'experiment' => $brand !== null ? $kml->experiment($brand) : [],
        ]);
    }

    private function selectedBrand(): ?Brand
    {
        return $this->brand !== null ? Brand::query()->find($this->brand) : null;
    }

    private function admin(): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
    }
}
