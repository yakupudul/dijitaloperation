<?php

namespace App\Livewire\Operator\Brain;

use App\Services\Brain\Proposals\ProposalService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "AI ile hazırla" on any page: starts one Brain preparation in the background and links to the review queue where
 * the result is approved in bulk. Nothing on the page changes until then.
 */
final class PrepareButton extends Component
{
    #[Locked]
    public string $kind = '';

    #[Locked]
    public array $options = [];

    public ?string $message = null;

    public function prepare(ProposalService $proposals): void
    {
        $proposals->queue($this->kind, auth()->user(), $this->options);
        $this->message = 'Hazırlanıyor; bitince onay kuyruğunda görünür.';
    }

    public function render(ProposalService $proposals): View
    {
        $kind = $proposals->kind($this->kind);

        return view('livewire.operator.brain.prepare-button', [
            'label' => $kind->label(),
            'ai' => $kind->usesAi(),
            'state' => $proposals->state($this->kind),
        ]);
    }
}
