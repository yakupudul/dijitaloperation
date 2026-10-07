<?php

namespace App\Livewire\Operator\Portfolio;

use App\Models\Brand;
use App\Services\Brand\BrandIdentity;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Marka › Özet › Kimlik tutarlılığı: name, phone, address, website link, profile links (sameAs) and expert author
 * compared between the Business Profiles and the website. Read only; "Yeniden kontrol et" skips the 6-hour cache.
 */
final class BrandIdentityCard extends Component
{
    #[Locked]
    public int $brandId;

    public bool $fresh = false;

    public function mount(int $brandId): void
    {
        $this->brandId = $brandId;
    }

    /** Loaded after the page (lazy), so the brand page does not wait for the stored home page read. */
    public function placeholder(): string
    {
        return '<div></div>';
    }

    public function recheck(): void
    {
        $this->fresh = true;
    }

    public function render(): View
    {
        $brand = Brand::query()->findOrFail($this->brandId);
        $identity = app(BrandIdentity::class)->for($brand, $this->fresh);
        $this->fresh = false;

        return view('livewire.operator.portfolio.brand-identity-card', ['identity' => $identity]);
    }
}
