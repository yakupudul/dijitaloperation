<?php

namespace App\Livewire\Operator\Winners;

use App\Services\Ads\AdLibraries;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Kütüphaneler (`/kutuphaneler`): saved ad texts and targetings from winning campaigns, the season calendar and the
 * hizmet × marka map. Nothing is sent anywhere.
 */
#[Layout('operator.layouts.app')]
#[Title('Kütüphaneler')]
final class LibrariesPage extends Component
{
    #[Url(as: 'sekme')]
    public string $tab = 'metin';

    #[Url(as: 'sektor')]
    public string $sector = '';

    #[Url(as: 'hizmet')]
    public string $service = '';

    public function remove(int $id, AdLibraries $libraries): void
    {
        $libraries->remove($id);
    }

    public function render(AdLibraries $libraries): View
    {
        if (! isset(AdLibraries::TABS[$this->tab])) {
            $this->tab = 'metin';
        }
        $sectors = $libraries->sectors();
        $sectorId = $this->sector !== '' && isset($sectors[(int) $this->sector]) ? (int) $this->sector : null;
        if ($sectorId === null && in_array($this->tab, ['mevsim', 'harita'], true) && $sectors !== []) {
            $sectorId = (int) array_key_first($sectors);
        }
        $serviceId = $this->service !== '' ? (int) $this->service : null;

        return view('livewire.operator.winners.libraries', [
            'tabs' => AdLibraries::TABS,
            'sectors' => $sectors,
            'sectorId' => $sectorId,
            'services' => $libraries->itemServices(),
            'items' => in_array($this->tab, ['metin', 'hedefleme'], true) ? $libraries->items($this->tab === 'metin' ? 'text' : 'targeting', $sectorId, $serviceId) : [],
            'season' => $this->tab === 'mevsim' && $sectorId !== null ? $libraries->season($sectorId) : null,
            'map' => $this->tab === 'harita' && $sectorId !== null ? $libraries->map($sectorId) : null,
            'months' => AdLibraries::MONTHS,
        ]);
    }
}
