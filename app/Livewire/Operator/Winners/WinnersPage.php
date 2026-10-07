<?php

namespace App\Livewire\Operator\Winners;

use App\Services\Ads\Winners;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Kazananlar (`/kazananlar`): the race between brands per service. Sectors first; the chosen sector's services each
 * show the leader of the website, Google Ads, Meta and Business Profile. A service opens its own page. Rules only.
 */
#[Layout('operator.layouts.app')]
#[Title('Kazananlar')]
final class WinnersPage extends Component
{
    #[Url(as: 'sektor')]
    public string $sector = '';

    #[Url(as: 'sehir')]
    public string $city = '';

    public function render(Winners $winners): View
    {
        return view('livewire.operator.winners.index', [
            'ready' => Winners::ready(),
            'view' => Winners::ready() ? $winners->overview($this->city, $this->sector) : null,
            'channels' => Winners::CHANNELS,
        ]);
    }
}
