<?php

namespace App\Livewire\Demo\Meta;

class AdSetsPage extends RedirectToWorkspace
{
    protected function targetTab(): string
    {
        return 'campaigns';
    }
}
