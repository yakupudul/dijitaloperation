<?php

namespace App\Livewire\Demo\Meta;

class InsightsPage extends RedirectToWorkspace
{
    protected function targetTab(): string
    {
        return 'operations';
    }
}
