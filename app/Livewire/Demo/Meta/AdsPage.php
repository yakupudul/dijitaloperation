<?php

namespace App\Livewire\Demo\Meta;

class AdsPage extends RedirectToWorkspace
{
    protected function targetTab(): string
    {
        return 'creatives';
    }
}
