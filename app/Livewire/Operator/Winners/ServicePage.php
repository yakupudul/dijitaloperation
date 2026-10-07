<?php

namespace App\Livewire\Operator\Winners;

use App\Services\Ads\Winners;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Kazananlar › hizmet (`/kazananlar/{serviceId}`): the four-channel podium, the overall ranking, each leader's recipe,
 * risers and fallers, leadership changes and the fair-race rules. Measure: cost per result or number of results.
 */
#[Layout('operator.layouts.app')]
#[Title('Kazananlar')]
final class ServicePage extends Component
{
    public int $serviceId;

    #[Url(as: 'sehir')]
    public string $city = '';

    #[Url(as: 'olcu')]
    public string $measure = 'cost';

    public function mount(int $serviceId): void
    {
        $this->serviceId = $serviceId;
    }

    public function render(Winners $winners): View
    {
        if (! isset(Winners::MEASURES[$this->measure])) {
            $this->measure = 'cost';
        }

        return view('livewire.operator.winners.service', [
            'ready' => Winners::ready(),
            'view' => Winners::ready() ? $winners->service($this->serviceId, $this->city, $this->measure) : null,
            'channels' => Winners::CHANNELS,
            'measures' => Winners::MEASURES,
        ]);
    }
}
