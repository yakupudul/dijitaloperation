<?php

namespace App\Livewire\Operator\Portfolio;

use App\Livewire\Concerns\AdminOnly;
use App\Models\Brand;
use App\Models\BrandFinding;
use App\Services\Brand\Findings;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Marka sayfası › Bulgular (Marka Düzeni 2.1 / 2.2, the approved prototype): what Claude saw on this brand with the
 * evidence, "Çöz" / "Hepsini çöz" that hand the work to Claude, the operator's own jobs, what was looked at and left
 * alone, what could not be seen, and what was done with its result. Shown at the top of the brand's Genel tab once
 * the brand has had its first deep look.
 */
final class BrandFindingsCard extends Component
{
    use AdminOnly;

    #[Locked]
    public int $brandId;

    public string $message = '';

    public function mount(int $brandId): void
    {
        $this->brandId = $brandId;
    }

    public function solve(int $id, Findings $findings): void
    {
        $this->adminOnly();
        $row = $this->row($id);
        $findings->solve($row, auth()->user());
        $this->message = '"'.$row->title.'" Claude\'a iletildi. Sonuç bu satıra döner.';
    }

    public function solveAll(Findings $findings): void
    {
        $this->adminOnly();
        $count = $findings->solveAll(Brand::query()->findOrFail($this->brandId), auth()->user());
        $this->message = $count > 0 ? $count.' bulgu Claude\'a iletildi. Sonuçlar satırlarına döner.' : 'İletilecek açık bulgu yok.';
    }

    /** Geri al / Kapat / Onayla (Yapıldı) / Yeniden aç. */
    public function mark(int $id, string $status, Findings $findings): void
    {
        $this->adminOnly();
        $findings->setStatus($this->row($id), $status);
        $this->message = '';
    }

    private function row(int $id): BrandFinding
    {
        return BrandFinding::query()->where('brand_id', $this->brandId)->findOrFail($id);
    }

    public function render(Findings $findings): View
    {
        $brand = Brand::query()->findOrFail($this->brandId);

        return view('livewire.operator.portfolio.brand-findings-card', ['brand' => $brand, 'page' => $findings->page($brand)]);
    }
}
