<?php

namespace App\Livewire\Demo\Settings;

use App\Services\Playbooks\PlaybookReadService;
use App\Support\Demo\AgencyExecutionFixtures;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Playbook')]
class PlaybookShow extends Component
{
    public string $playbookId = '';

    public function mount(string $playbookId): void
    {
        $this->playbookId = $playbookId;
    }

    public function render(): View
    {
        $playbook = app(PlaybookReadService::class)->findPresentation($this->playbookId);

        return view('livewire.demo.settings.playbook-show', [
            'playbook' => $playbook,
            'recentReviews' => $playbook !== null
                ? AgencyExecutionFixtures::recentReviewsForPlaybook((string) ($playbook['stable_key'] ?? $this->playbookId))
                : [],
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
