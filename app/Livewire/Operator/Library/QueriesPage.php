<?php

namespace App\Livewire\Operator\Library;

use App\Models\Query;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Sorgular: the one query store (query_sources → queries → brand_queries). Faz 3 fills it and adds the filter basket,
 * matching-keyword assignment and clustering; Faz 0 only shows what is stored.
 */
#[Layout('operator.layouts.app')]
#[Title('Sorgular')]
final class QueriesPage extends Component
{
    public function render(): View
    {
        return view('livewire.operator.library.queries-page', [
            'count' => Query::query()->count(),
        ]);
    }
}
