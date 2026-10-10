<?php

namespace App\Livewire\Operator\Winners;

use App\Services\Ads\Winners;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Kazananlar (`/kazananlar`): the race between brands per market (city × service). A city strip (busiest first, or
 * Türkiye geneli listing every city's markets), then two tabs: Pazarlar (each market with the leader of every channel
 * that has one, markets a brand is alone in with a reference against other cities) and Marka gözüyle (where one brand
 * is behind, with the leader's numbers and where to act). A market opens its own page. Rules only.
 */
#[Layout('operator.layouts.app')]
#[Title('Kazananlar')]
final class WinnersPage extends Component
{
    /** @var array<string, string> */
    public const array TABS = ['pazarlar' => 'Pazarlar', 'marka' => 'Marka gözüyle'];

    #[Url(as: 'sekme')]
    public string $tab = 'pazarlar';

    #[Url(as: 'sektor')]
    public string $sector = '';

    #[Url(as: 'sehir')]
    public string $city = '';

    #[Url(as: 'marka')]
    public string $brand = '';

    public function render(Winners $winners): View
    {
        if (! isset(self::TABS[$this->tab])) {
            $this->tab = 'pazarlar';
        }
        $ready = Winners::ready();

        return view('livewire.operator.winners.index', [
            'ready' => $ready,
            'view' => $ready && $this->tab === 'pazarlar' ? $winners->overview($this->city, $this->sector) : null,
            'brandView' => $ready && $this->tab === 'marka' ? $winners->brandView((int) $this->brand) : null,
            'channels' => Winners::CHANNELS,
            'tabs' => self::TABS,
        ]);
    }
}
