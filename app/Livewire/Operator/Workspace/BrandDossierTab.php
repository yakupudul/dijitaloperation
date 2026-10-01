<?php

namespace App\Livewire\Operator\Workspace;

use App\Models\Brand;
use App\Models\User;
use App\Services\Brand\BrandDossier;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Marka dosyası" tab: the short file every AI agent reads first (compiled without AI), with the operator's goals and
 * constraints — the only hand-written part — and a "Yenile" button.
 */
class BrandDossierTab extends Component
{
    #[Locked]
    public int $brandId;

    public string $goals = '';

    public string $constraints = '';

    public string $message = '';

    public function mount(int $brandId): void
    {
        $this->brandId = $brandId;
        ['goals' => $this->goals, 'constraints' => $this->constraints] = BrandDossier::notes($this->brand());
    }

    public function rebuild(BrandDossier $dossier): void
    {
        $this->actor();
        $dossier->build($this->brand());
        $this->message = 'Marka dosyası yenilendi.';
    }

    public function saveNotes(BrandDossier $dossier): void
    {
        $this->actor();
        $this->validate(['goals' => ['nullable', 'string', 'max:4000'], 'constraints' => ['nullable', 'string', 'max:4000']]);
        $brand = $this->brand();
        BrandDossier::saveNotes($brand, $this->goals, $this->constraints);
        $dossier->build($brand);
        $this->message = 'Notlar kaydedildi; dosya yenilendi.';
    }

    public function render(BrandDossier $dossier): View
    {
        $brand = $this->brand();

        return view('livewire.operator.workspace.brand-dossier-tab', [
            'dossier' => BrandDossier::stored($brand) ?? $dossier->build($brand),
        ]);
    }

    private function brand(): Brand
    {
        return Brand::query()->findOrFail($this->brandId);
    }

    private function actor(): User
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && $actor->is_active, 403);

        return $actor;
    }
}
