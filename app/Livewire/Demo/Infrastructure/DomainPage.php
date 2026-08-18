<?php

namespace App\Livewire\Demo\Infrastructure;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Domain')]
class DomainPage extends Component
{
    public function mount(?string $assetId = null): void
    {
        $this->redirect(route('operator.assets'));
    }

    public function render(): View
    {
        return view('livewire.demo.infrastructure.domain');
    }
}
