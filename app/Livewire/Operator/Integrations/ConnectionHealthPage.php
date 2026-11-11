<?php

namespace App\Livewire\Operator\Integrations;

use App\Livewire\Concerns\AdminOnly;
use App\Models\Brand;
use App\Services\DataStatus\ConnectionHealth;
use App\Services\DataStatus\ConnectionMap;
use App\Support\WriteLevels;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Bağlantı sağlığı: every brand's gaps in what the system can see, "Sistem onarır" first (it also runs nightly), then
 * "Senin işin" with the exact step and a link. "Şimdi onar" starts the system's repairs at once. The second view,
 * "Harita" (Bağlantı Düzeni 2.1), is the same truth as a grid: brands down, channels across. The third,
 * "Yetkiler" (3.2), sets what the system may do per brand and channel: Yalnız oku / Öner / Yaz.
 */
#[Layout('operator.layouts.app')]
#[Title('Bağlantı sağlığı')]
final class ConnectionHealthPage extends Component
{
    use AdminOnly;

    public string $message = '';

    #[Url(as: 'gorunum')]
    public string $view = 'eksikler';

    /** Harita: only brands with a yellow or red cell. */
    #[Url(as: 'sorunlu')]
    public bool $onlyGaps = false;

    /** Harita: the brand whose access link panel is open (Bağlantı Düzeni 4.1). */
    public ?int $accessBrand = null;

    public function repairNow(ConnectionHealth $health): void
    {
        $done = $health->repair(auth()->user(), manual: true);
        $this->message = sprintf('%d hesabın veri çekimi ve %d site taraması başlatıldı%s. Sonuç birkaç dakika içinde burada görünür.',
            $done['collections'], $done['crawls'], $done['failed'] > 0 ? ', '.$done['failed'].' tanesi başlatılamadı' : '');
    }

    /** Yetki kademesi of one brand's channel (advertising channels are locked at "Yalnız oku"). */
    public function setLevel(int $brandId, string $channel, string $level): void
    {
        $this->adminOnly();
        $brand = Brand::query()->operational()->findOrFail($brandId);
        WriteLevels::set($brand, $channel, $level);
        $this->message = sprintf('%s, %s: %s.', $brand->name, WriteLevels::CHANNELS[$channel], WriteLevels::LABELS[$level]);
    }

    public function render(ConnectionHealth $health): View
    {
        $issues = $health->issues();
        $map = $this->view === 'harita' ? app(ConnectionMap::class)->rows() : [];

        return view('livewire.operator.integrations.connection-health', [
            'map' => $this->onlyGaps ? array_values(array_filter($map, fn (array $row): bool => in_array($row['worst'], ['warn', 'bad'], true))) : $map,
            'mapTotals' => ConnectionMap::totals($map),
            'mapBrands' => count($map),
            'levelBrands' => $this->view === 'yetkiler' ? Brand::query()->operational()->orderBy('name')->get(['id', 'name', 'logo_url', 'write_levels', 'created_at']) : collect(),
            'summary' => $health->summary($issues),
            'groups' => collect($issues)->filter(ConnectionHealth::isGap(...))->groupBy('brand')->sortKeys()->all(),
            'passive' => collect($issues)->reject(ConnectionHealth::isGap(...))->sortBy('brand')->values()->all(),
        ]);
    }
}
