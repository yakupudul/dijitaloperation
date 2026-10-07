<?php

namespace App\Livewire\Operator\Meta;

use App\Models\Brand;
use App\Services\Meta\MetaStrategy;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Strateji öner (`/meta/strateji`): pick a brand, service, city and result type; see the other brands' winning Meta
 * campaigns (30 days, threshold in MetaStrategy) cheapest first with their settings, targeting and best ad; the
 * rule-built "kazanan tarifi"; the brand's own campaigns beside it; save texts and targetings to Kütüphaneler; ask
 * Claude for a plan draft that lands in the brand's Meta Yapılacaklar. Nothing is written to Meta.
 */
#[Layout('operator.layouts.app')]
#[Title('Strateji öner')]
final class StrategyPage extends Component
{
    #[Url(as: 'marka')]
    public string $brand = '';

    #[Url(as: 'hizmet')]
    public string $service = '';

    /** null: the brand's own city (first visit); '' = all cities. */
    #[Url(as: 'sehir')]
    public ?string $city = null;

    #[Url(as: 'tur')]
    public string $type = 'leads';

    public string $flash = '';

    public function updatedBrand(): void
    {
        $this->service = '';
        $this->city = null;
    }

    public function requestPlan(MetaStrategy $strategy): void
    {
        $brand = $this->brand !== '' ? Brand::query()->find((int) $this->brand) : null;
        if ($brand === null || $this->service === '') {
            $this->flash = 'Plan için önce marka ve hizmet seçin.';

            return;
        }
        if (! $brand->isOperational()) {
            $this->flash = 'Marka operasyonel değil; AI kapalı.';

            return;
        }
        $this->flash = $strategy->requestPlan($brand, (int) $this->service, $this->type, (string) $this->city)['message'];
    }

    public function save(int $statId, string $kind, MetaStrategy $strategy): void
    {
        $this->flash = $strategy->save($statId, $kind, auth()->user())
            ? ($kind === 'text' ? 'Reklam metni kütüphaneye kaydedildi.' : 'Hedefleme kütüphaneye kaydedildi.')
            : 'Zaten kütüphanede.';
    }

    public function render(MetaStrategy $strategy): View
    {
        if (! isset(MetaStrategy::TYPES[$this->type])) {
            $this->type = 'leads';
        }
        $view = $strategy->strategy(['brand' => $this->brand, 'service' => $this->service, 'city' => $this->city, 'type' => $this->type]);
        if (($view['ready'] ?? false) && $this->service === '' && $view['service_id'] !== null) {
            $this->service = (string) $view['service_id'];
        }
        if (($view['ready'] ?? false) && $this->city === null) {
            $this->city = $view['city'];
        }

        return view('livewire.operator.meta.strategy', ['view' => $view, 'types' => MetaStrategy::TYPES, 'destinations' => MetaStrategy::DESTINATIONS,
            'optimizations' => MetaStrategy::OPTIMIZATIONS, 'minSpend' => MetaStrategy::MIN_SPEND, 'minResults' => MetaStrategy::MIN_RESULTS]);
    }
}
