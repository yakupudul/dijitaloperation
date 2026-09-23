<?php

namespace App\Livewire\Operator\Seo;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * /seo-tasks — every brand's SEO tasks in one priority-ordered list.
 */
#[Layout('operator.layouts.app')]
#[Title('SEO Görevleri')]
final class SeoTasksIndex extends Component
{
    public function render(): View
    {
        return view('livewire.operator.seo.seo-tasks-index');
    }
}
