<?php

namespace App\Livewire\Demo\Meta;

class CreativesPage extends RedirectToWorkspace
{
    protected function targetTab(): string
    {
        return 'creatives';
    }
}
