<?php

namespace App\Livewire\Operator\Integrations;

use App\Services\DataStatus\ConnectionHealth;
use App\Services\DataStatus\ConnectionMap;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Bağlantı sağlığı: every brand's gaps in what the system can see, "Sistem onarır" first (it also runs nightly), then
 * "Senin işin" with the exact step and a link. "Şimdi onar" starts the system's repairs at once. The second view,
 * "Harita" (Bağlantı Düzeni 2.1), is the same truth as a grid: brands down, channels across.
 */
#[Layout('operator.layouts.app')]
#[Title('Bağlantı sağlığı')]
final class ConnectionHealthPage extends Component
{
    public string $message = '';

    #[Url(as: 'gorunum')]
    public string $view = 'eksikler';

    /** Harita: only brands with a yellow or red cell. */
    #[Url(as: 'sorunlu')]
    public bool $onlyGaps = false;

    public function repairNow(ConnectionHealth $health): void
    {
        $done = $health->repair(auth()->user(), manual: true);
        $this->message = sprintf('%d hesabın veri çekimi ve %d site taraması başlatıldı%s. Sonuç birkaç dakika içinde burada görünür.',
            $done['collections'], $done['crawls'], $done['failed'] > 0 ? ', '.$done['failed'].' tanesi başlatılamadı' : '');
    }

    public function render(ConnectionHealth $health): View
    {
        $issues = $health->issues();
        $map = $this->view === 'harita' ? app(ConnectionMap::class)->rows() : [];

        return view('livewire.operator.integrations.connection-health', [
            'map' => $this->onlyGaps ? array_values(array_filter($map, fn (array $row): bool => in_array($row['worst'], ['warn', 'bad'], true))) : $map,
            'mapTotals' => ConnectionMap::totals($map),
            'mapBrands' => count($map),
            'summary' => $health->summary($issues),
            'groups' => collect($issues)->filter(ConnectionHealth::isGap(...))->groupBy('brand')->sortKeys()->all(),
            'passive' => collect($issues)->reject(ConnectionHealth::isGap(...))->sortBy('brand')->values()->all(),
        ]);
    }
}
