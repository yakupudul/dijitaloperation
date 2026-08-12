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
        $accounts = collect(DemoCatalog::metaImportAccounts());

        $groups = [
            'ready' => [
                'label' => 'Ready',
                'hint' => 'History imported and usable for analysis.',
                'accounts' => $accounts->where('status', 'ready')->values()->all(),
            ],
            'importing' => [
                'label' => 'Importing',
                'hint' => 'Chunks in flight — progress updates as the simulation ticks.',
                'accounts' => $accounts->where('status', 'importing')->values()->all(),
            ],
            'waiting' => [
                'label' => 'Waiting',
                'hint' => 'Waiting on Meta Insights / provider readiness.',
                'accounts' => $accounts->where('status', 'waiting')->values()->all(),
            ],
            'queued' => [
                'label' => 'Queued',
                'hint' => 'Queued behind active imports.',
                'accounts' => $accounts->where('status', 'queued')->values()->all(),
            ],
            'needs_attention' => [
                'label' => 'Needs attention',
                'hint' => 'Permission or provider errors blocking import.',
                'accounts' => $accounts->where('status', 'needs_attention')->values()->all(),
            ],
        ];

        return view('livewire.demo.integrations.meta-integration', [
            'import' => $import,
            'groups' => $groups,
            'accounts' => $accounts->values()->all(),
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
