<?php

namespace App\Livewire\Demo\Sales;

use App\Models\SalesSearchProfile;
use App\Support\Options\AgencyServiceOptions;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Search Profiles')]
class SearchProfilesIndex extends Component
{
    public string $search = '';

    public function render(): View
    {
        $rows = SalesSearchProfile::query()
            ->with('owner')
            ->when($this->search !== '', function ($query): void {
                $query->where('name', 'like', '%'.$this->search.'%');
            })
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (SalesSearchProfile $profile): array => [
                'id' => (string) $profile->id,
                'name' => $profile->name,
                'service' => AgencyServiceOptions::label($profile->service_definition_code),
                'active' => $profile->active,
                'owner' => $profile->owner?->name,
                'includes' => is_array($profile->include_concepts) ? count($profile->include_concepts) : 0,
            ])
            ->all();

        return view('livewire.demo.sales.search-profiles-index', [
            'rows' => $rows,
        ]);
    }
}
