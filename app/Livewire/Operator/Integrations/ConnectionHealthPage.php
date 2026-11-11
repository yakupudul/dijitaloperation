<?php

namespace App\Livewire\Operator\Integrations;

use App\Livewire\Concerns\AdminOnly;
use App\Models\AccessLink;
use App\Models\Brand;
use App\Models\BrandSetupProposal;
use App\Services\BrandSetup\QuickBrand;
use App\Services\DataStatus\ConnectionHealth;
use App\Services\DataStatus\ConnectionMap;
use App\Services\DataStatus\ProviderWatch;
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

    /** Harita › Yeni marka (Bağlantı Düzeni 4.3): the domain, and a name only when the domain does not say it. */
    public string $newDomain = '';

    public string $newName = '';

    public function newBrand(QuickBrand $quick): void
    {
        $this->adminOnly();
        $brand = $quick->start($this->newDomain, $this->newName, auth()->user());
        $this->reset('newDomain', 'newName');
        $this->message = $brand->name.' açıldı. Hesaplar taranıyor; bulunanlar kendiliğinden bağlanır, eksikler için erişim bağlantısı hazırlanır.';
    }

    /**
     * The wizard's brands of the last three days and where each stands.
     *
     * @return list<array{brand_id: int, brand: string, state: string, line: string, link: bool}>
     */
    private function wizard(): array
    {
        $links = AccessLink::query()->pluck('asked', 'brand_id');

        return BrandSetupProposal::query()->with('brand:id,name')->where('summary->auto', true)->where('created_at', '>=', now()->subDays(3))->orderByDesc('id')->limit(5)->get()
            ->filter(fn (BrandSetupProposal $p): bool => $p->brand !== null)
            ->map(function (BrandSetupProposal $p) use ($links): array {
                $done = count(array_filter((array) $p->apply_result, fn ($r): bool => (bool) ($r['ok'] ?? false)));
                $asked = count((array) ($links[$p->brand_id] ?? []));

                return ['brand_id' => (int) $p->brand_id, 'brand' => (string) $p->brand->name, 'link' => $asked > 0,
                    'state' => match (true) {
                        $p->isPending() => 'wait', $p->status === BrandSetupProposal::STATUS_APPLIED => 'done', default => 'bad'
                    },
                    'line' => match (true) {
                        $p->isPending() => 'Hesaplar taranıyor',
                        $p->status === BrandSetupProposal::STATUS_APPLIED => $done.' adım uygulandı'.($asked > 0 ? ', '.$asked.' kanal için erişim bağlantısı hazır' : ''),
                        default => 'Tarama tamamlanamadı; marka sayfasındaki Otomatik kur ile yeniden dene',
                    }];
            })->values()->all();
    }

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
            'wizard' => $this->view === 'harita' ? $this->wizard() : [],
            'levelBrands' => $this->view === 'yetkiler' ? Brand::query()->operational()->orderBy('name')->get(['id', 'name', 'logo_url', 'write_levels', 'created_at']) : collect(),
            'watch' => $watch = app(ProviderWatch::class)->rows(),
            'watchDue' => count(array_filter($watch, fn (array $row): bool => in_array($row['tone'], ['warn', 'bad'], true))),
            'summary' => $health->summary($issues),
            'groups' => collect($issues)->filter(ConnectionHealth::isGap(...))->groupBy('brand')->sortKeys()->all(),
            'passive' => collect($issues)->reject(ConnectionHealth::isGap(...))->sortBy('brand')->values()->all(),
        ]);
    }
}
