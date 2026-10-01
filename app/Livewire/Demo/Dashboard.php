<?php

namespace App\Livewire\Demo;

use App\Enums\OfferingStatus;
use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Services\DataStatus\DataStatus;
use App\Services\DataStatus\DataStatusReader;
use App\Services\Observability\ErrorTriage;
use App\Services\Outcomes\OutcomeTracker;
use App\Services\Portfolio\BrandCandidateBuilder;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

/**
 * Bugün: every operational brand in one list (sector, services, areas, asset types, last data date) and "Sonuçlar" — the
 * applied suggestions measured at 28 / 56 days (işe yaradı / yaramadı / belirsiz, Faz 9).
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
            'results' => $this->results(),
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
            $brands = Brand::query()->operational()->with(['customer', 'sectorCategory', 'digitalAssets' => fn ($q) => $q->orderBy('type')->orderBy('name'), 'digitalAssets.ownSector:id,name'])
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
                    'sector' => $asset->sector_id !== null ? $asset->ownSector?->name : null,
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
     * Sonuçlar: the last measured suggestions and the verdict counts of the last 90 days.
     *
     * @return array{counts: array<string, int>, items: list<array<string, string>>}
     */
    private function results(): array
    {
        try {
            return app(OutcomeTracker::class)->summary();
        } catch (Throwable $error) {
            report($error);

            return ['counts' => [], 'items' => []];
        }
    }

    /**
     * Hata merkezi on Bugün: only what needs the operator (ErrorTriage `you` + `code`); self-healing problems stay off.
     *
     * @return array{critical: int, warning: int, top: ?string}
     */
    private function systemAlerts(): array
    {
        try {
            $groups = app(ErrorTriage::class)->groups();
            $you = collect($groups[ErrorTriage::YOU] ?? []);
            $code = collect($groups[ErrorTriage::CODE] ?? []);

            return [
                'critical' => (int) $you->sum('count'),
                'warning' => (int) $code->sum('count'),
                'top' => $you->first()['title'] ?? $code->first()['title'] ?? null,
            ];
        } catch (Throwable) {
            return ['critical' => 0, 'warning' => 0, 'top' => null];
        }
    }
}
