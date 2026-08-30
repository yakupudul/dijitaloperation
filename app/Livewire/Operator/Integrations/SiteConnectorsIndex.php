<?php

namespace App\Livewire\Operator\Integrations;

use App\Models\CoreConnection;
use App\Services\Integrations\WordPress\WordPressConnectorPairingService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Site Connectors')]
final class SiteConnectorsIndex extends Component
{
    public function render(): View
    {
        $paired = CoreConnection::query()
            ->where('type', WordPressConnectorPairingService::CONNECTION_TYPE)
            ->where('config->pairing_state', WordPressConnectorPairingService::PAIRED)
            ->where('enabled', true)
            ->whereHas('credential')
            ->count();

        return view('livewire.operator.integrations.site-connectors-index', compact('paired'));
    }
}
