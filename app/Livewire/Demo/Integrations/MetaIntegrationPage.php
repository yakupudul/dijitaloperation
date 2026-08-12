<?php

namespace App\Livewire\Demo\Integrations;

use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Meta Integration')]
class MetaIntegrationPage extends Component
{
    public ?string $expandedAccountId = null;

    public function mount(): void
    {
        $import = DemoState::all()['import'] ?? [];
        $this->expandedAccountId = $import['selected_account_id'] ?? null;
    }

    public function startImport(): void
    {
        DemoState::startMetaImport();
    }

    public function pollImport(): void
    {
        DemoState::tickMetaImport();
    }

    public function expandAccount(string $id): void
    {
        $this->expandedAccountId = $this->expandedAccountId === $id ? null : $id;
        DemoState::put(['import' => array_merge(DemoState::all()['import'] ?? [], [
            'selected_account_id' => $this->expandedAccountId,
        ])]);
    }

    public function render(): View
    {
        $import = DemoState::all()['import'];

        return view('livewire.demo.integrations.meta-integration', [
            'import' => $import,
            'accounts' => DemoCatalog::metaImportAccounts(),
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
