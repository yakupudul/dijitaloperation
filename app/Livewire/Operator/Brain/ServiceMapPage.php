<?php

namespace App\Livewire\Operator\Brain;

use App\Services\Brain\ServiceMapReader;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Hizmet Beyni › Hizmet haritası: one service end to end — its page-sized query clusters, which page of each brand
 * owns each cluster, where two pages split one topic, and (later phases) ads, Meta creatives, success and methods.
 */
#[Layout('operator.layouts.app')]
#[Title('Hizmet haritası')]
final class ServiceMapPage extends Component
{
    #[Url]
    public ?int $service = null;

    public function dismissCannibalization(int $id): void
    {
        abort_unless(auth()->user()?->is_active, 403);
        DB::table('brain_cannibalizations')->where('id', $id)->update(['status' => 'dismissed', 'updated_at' => now()]);
        DemoState::flash('Bu çakışma bilinçli kabul edildi; tekrar gösterilmez.');
    }

    public function render(ServiceMapReader $reader): View
    {
        $services = $reader->services();
        if ($this->service === null || ! in_array($this->service, array_column($services, 'id'), true)) {
            $this->service = $services[0]['id'] ?? null;
        }

        return view('livewire.operator.brain.service-map', [
            'services' => $services,
            'map' => $this->service !== null ? $reader->forService($this->service) : null,
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
