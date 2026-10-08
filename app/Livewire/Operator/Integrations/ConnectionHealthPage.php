<?php

namespace App\Livewire\Operator\Integrations;

use App\Services\DataStatus\ConnectionHealth;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Bağlantı sağlığı: every brand's gaps in what the system can see, "Sistem onarır" first (it also runs nightly), then
 * "Senin işin" with the exact step and a link. "Şimdi onar" starts the system's repairs at once.
 */
#[Layout('operator.layouts.app')]
#[Title('Bağlantı sağlığı')]
final class ConnectionHealthPage extends Component
{
    public string $message = '';

    public function repairNow(ConnectionHealth $health): void
    {
        $done = $health->repair(auth()->user(), manual: true);
        $this->message = sprintf('%d hesabın veri çekimi ve %d site taraması başlatıldı%s. Sonuç birkaç dakika içinde burada görünür.',
            $done['collections'], $done['crawls'], $done['failed'] > 0 ? ', '.$done['failed'].' tanesi başlatılamadı' : '');
    }

    public function render(ConnectionHealth $health): View
    {
        $issues = $health->issues();

        return view('livewire.operator.integrations.connection-health', [
            'summary' => $health->summary($issues),
            'groups' => collect($issues)->groupBy('brand')->sortKeys()->all(),
        ]);
    }
}
