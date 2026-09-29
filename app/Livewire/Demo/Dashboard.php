<?php

namespace App\Livewire\Demo;

use App\Enums\Observability\OperationalAlertState;
use App\Enums\OfferingStatus;
use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\Observability\OperationalAlert;
use App\Services\DataStatus\DataStatus;
use App\Services\DataStatus\DataStatusReader;
use App\Services\Portfolio\BrandCandidateBuilder;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

/**
 * Bugün: every operational brand in one list (sector, services, areas, asset types, last data date). Faz 9 adds the applied-suggestion results (worked / not) per brand;
 * until then the list is the entry point to each brand's workspace.
 */
#[Layout('operator.layouts.app')]
#[Title('Bugün')]
class Dashboard extends Component
{
    public function render(): View
    {
        return view('livewire.demo.dashboard', [
            'rows' => $this->rows(),
            'systemAlerts' => $this->systemAlerts(),
            'flash' => DemoState::pullFlash(),
        ]);
    }

    /**
     * One row per operational brand: sector, service / area counts, bound asset types and the last data date of each
     * asset (from the collected facts, "—" when none). Suggestion counts arrive with Faz 4+.
     *
     * @return list<array{id: int, name: string, customer: ?string, sector: ?string, services: int, areas: int, assets: list<array{type: string, name: string, last: ?string}>, suggestions: ?int}>
     */
    private function rows(): array
    {
        try {
            $brands = Brand::query()->operational()->with(['customer', 'sectorCategory', 'digitalAssets' => fn ($q) => $q->orderBy('type')->orderBy('name')])
                ->withCount([
                    'offerings as services_count' => fn ($q) => $q->where('status', OfferingStatus::Active->value),
                    'serviceAreas as areas_count' => fn ($q) => $q->where('status', 'active'),
                ])->orderBy('name')->get();
            $assets = $brands->flatMap(fn (Brand $brand) => $brand->digitalAssets)->values();
            $statuses = $this->lastDataDates($assets);

            return $brands->map(fn (Brand $brand): array => [
                'id' => (int) $brand->id,
                'name' => (string) $brand->name,
                'customer' => $brand->customer?->name,
                'sector' => $brand->sectorCategory?->name,
                'services' => (int) ($brand->services_count ?? 0),
                'areas' => (int) ($brand->areas_count ?? 0),
                'assets' => $brand->digitalAssets->map(fn (DigitalAsset $asset): array => [
                    'type' => BrandCandidateBuilder::typeLabel((string) $asset->type),
                    'name' => (string) $asset->name,
                    'last' => $statuses[(int) $asset->id] ?? null,
                ])->all(),
                'suggestions' => null,
            ])->all();
        } catch (Throwable $error) {
            report($error);

            return [];
        }
    }

    /**
     * @param  Collection<int, DigitalAsset>  $assets
     * @return array<int, string> asset id => newest data date (d.m.Y) over its sources
     */
    private function lastDataDates(Collection $assets): array
    {
        if ($assets->isEmpty()) {
            return [];
        }
        try {
            $out = [];
            foreach (app(DataStatusReader::class)->forAssets($assets) as $assetId => $statuses) {
                $last = collect($statuses)->map(fn (DataStatus $s) => $s->lastDataDate)->filter()->max();
                if ($last !== null) {
                    $out[(int) $assetId] = $last->format('d.m.Y');
                }
            }

            return $out;
        } catch (Throwable $error) {
            report($error);

            return [];
        }
    }

    /**
     * Open operational alerts (collection failure, reconnect needed, quota, stopped worker): one line on Bugün.
     *
     * @return array{critical: int, warning: int, top: ?string}
     */
    private function systemAlerts(): array
    {
        try {
            $open = OperationalAlert::query()->whereIn('state', [OperationalAlertState::Open->value, OperationalAlertState::Acknowledged->value]);
            $top = (clone $open)->orderByRaw("case severity when 'CRITICAL' then 0 when 'WARNING' then 1 else 2 end")->orderByDesc('last_observed_at')->value('title');

            return [
                'critical' => (clone $open)->where('severity', 'CRITICAL')->count(),
                'warning' => (clone $open)->where('severity', 'WARNING')->count(),
                'top' => $top !== null ? (string) $top : null,
            ];
        } catch (Throwable) {
            return ['critical' => 0, 'warning' => 0, 'top' => null];
        }
    }
}
