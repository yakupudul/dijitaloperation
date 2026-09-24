<?php

namespace App\Livewire\Operator\Settings;

use App\Enums\CustomerStatus;
use App\Models\Brand;
use App\Services\Brain\SectorPatternReader;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Ayarlar › Sektör örüntüleri (ADR-066): what recurs across the agency's brands in one sector. Aggregates only,
 * agency-internal; a brand filter lists the sector's common queries that brand does not track yet.
 */
#[Layout('operator.layouts.app')]
#[Title('Sektör örüntüleri')]
final class SectorPatternsPage extends Component
{
    #[Url]
    public string $sector = '';

    #[Url]
    public ?int $brand = null;

    public function updatedSector(): void
    {
        $this->brand = null;
    }

    public function render(SectorPatternReader $reader): View
    {
        $sectors = $reader->sectors();
        if ($this->sector === '' || ! in_array($this->sector, array_column($sectors, 'code'), true)) {
            $this->sector = (string) ($sectors[0]['code'] ?? '');
        }
        $patterns = $this->sector !== '' ? $reader->forSector($this->sector, $this->brand) : null;
        $brands = $this->sector !== ''
            ? Brand::query()->where('sector', $this->sector)->whereHas('customer', fn ($q) => $q->where('status', CustomerStatus::Active->value))->orderBy('name')->get(['id', 'name'])
            : collect();

        return view('livewire.operator.settings.sector-patterns', [
            'sectors' => $sectors,
            'patterns' => $patterns,
            'brands' => $brands,
            'minBrands' => SectorPatternReader::minBrands(),
        ]);
    }
}
