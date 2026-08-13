<?php

namespace App\Livewire\Demo\Meta;

class BreakdownsPage extends RedirectToWorkspace
{
    protected function targetTab(): string
    {
        return 'audience';
    }
}
