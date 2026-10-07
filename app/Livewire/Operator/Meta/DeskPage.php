<?php

namespace App\Livewire\Operator\Meta;

use App\Services\Ads\AdServiceStats;
use App\Services\Meta\MetaDesk;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Meta masası (`/meta`, menu "Meta reklamları"): every brand's Meta campaigns in one list with totals, what to look at
 * today, the service averages across brands and each campaign's distance to its service average. Numbers are the
 * daily 30-day rows (AdServiceStats); a campaign opens its own page. Nothing is written to Meta.
 */
#[Layout('operator.layouts.app')]
#[Title('Meta reklamları')]
final class DeskPage extends Component
{
    #[Url(as: 'sektor')]
    public string $sector = '';

    #[Url(as: 'hizmet')]
    public string $service = '';

    #[Url(as: 'marka')]
    public string $brand = '';

    #[Url(as: 'uyari')]
    public string $alert = '';

    #[Url(as: 'sirala')]
    public string $sort = 'worst';

    public function render(MetaDesk $desk): View
    {
        if (! isset(MetaDesk::SORTS[$this->sort])) {
            $this->sort = 'worst';
        }
        if ($this->alert !== '' && ! isset(MetaDesk::ALERTS[$this->alert])) {
            $this->alert = '';
        }

        return view('livewire.operator.meta.desk', [
            'desk' => $desk->desk(['sector' => $this->sector, 'service' => $this->service, 'brand' => $this->brand, 'alert' => $this->alert, 'sort' => $this->sort]),
            'alerts' => MetaDesk::ALERTS,
            'sorts' => MetaDesk::SORTS,
            'typeLabels' => AdServiceStats::TYPE_LABELS,
        ]);
    }
}
