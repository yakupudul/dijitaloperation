<?php

namespace App\Livewire\Operator\Portfolio;

use App\Models\Brand;
use App\Models\BrandDirectionChange;
use App\Services\Brand\BrandDirection;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Marka › Bilgi dosyası › Marka rotası (yakup, 2026-11-09): the brand's direction as the system sees it (services, ★,
 * positioning), the last changes and what each realignment did. "Rota değişti" tells the system the brand took a new
 * direction: clusters and unsent ideas are read again against it and the old ones set aside (restorable).
 */
final class BrandDirectionCard extends Component
{
    #[Locked]
    public int $brandId;

    public bool $confirming = false;

    public string $reason = '';

    public string $message = '';

    public function mount(int $brandId): void
    {
        $this->brandId = $brandId;
    }

    public function placeholder(): string
    {
        return '<div></div>';
    }

    public function realign(BrandDirection $direction): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
        $brand = Brand::query()->findOrFail($this->brandId);
        $direction->detect($brand, force: true, trigger: 'operator', reason: trim($this->reason) !== '' ? trim($this->reason) : null, userId: auth()->id());
        $this->confirming = false;
        $this->reason = '';
        $this->message = 'Yeniden hizalama başladı: kümeler ve gönderilmemiş fikirler yeni rotaya göre okunuyor. Birkaç dakika içinde sonuç burada görünür.';
    }

    public function render(): View
    {
        $brand = Brand::query()->findOrFail($this->brandId);

        return view('livewire.operator.portfolio.brand-direction-card', [
            'now' => BrandDirection::snapshot($brand),
            'changes' => BrandDirectionChange::query()->where('brand_id', $this->brandId)->orderByDesc('id')->limit(5)->get(),
            'isAdmin' => (bool) auth()->user()?->hasRole(Roles::ADMIN),
        ]);
    }
}
