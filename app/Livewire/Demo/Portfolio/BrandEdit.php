<?php

namespace App\Livewire\Demo\Portfolio;

use App\Livewire\Demo\Portfolio\Concerns\InteractsWithBrandForm;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Edit brand')]
class BrandEdit extends Component
{
    use InteractsWithBrandForm;

    public string $brandId = '';

    public function mount(string $brandId): void
    {
        $this->brandId = $brandId;
        $brand = DemoState::findBrand($brandId) ?? (
            $brandId === DemoCatalog::BRAND_ID ? DemoCatalog::brand() : null
        );

        abort_if($brand === null, 404);

        $this->fillBrandForm($brand);
        $this->customerLocked = true;
    }

    public function save(): mixed
    {
        if ($this->saving) {
            return null;
        }

        $this->saving = true;

        try {
            $this->validate($this->brandRules());

            $existing = DemoState::findBrand($this->brandId) ?? DemoCatalog::brand();
            $payload = array_merge($existing, $this->brandPayload($this->brandId));
            $payload['assets_count'] = $existing['assets_count'] ?? 0;
            $payload['open_findings'] = $existing['open_findings'] ?? 0;
            $payload['open_tasks'] = $existing['open_tasks'] ?? 0;
            $payload['health'] = $existing['health'] ?? 'healthy';
            $payload['health_label'] = $existing['health_label'] ?? 'Healthy';
            $payload['summary'] = $existing['summary'] ?? $payload['summary'];

            if (DemoState::findBrand($this->brandId) === null) {
                $state = DemoState::all();
                $state['brands'] = array_values(array_filter(
                    $state['brands'] ?? [],
                    static fn (array $b): bool => ($b['id'] ?? '') !== $this->brandId
                ));
                $state['brands'][] = DemoState::normalizeBrand($payload);
                session()->put(DemoState::SESSION_KEY, $state);
                DemoState::flash('Brand changes saved (Demo Mode).');
            } else {
                DemoState::updateBrand($this->brandId, $payload);
            }

            return $this->redirect(route('demo.brand', ['brand' => $this->brandId]), navigate: true);
        } finally {
            $this->saving = false;
        }
    }

    public function render(): View
    {
        return view('livewire.demo.portfolio.brand-form', array_merge($this->brandFormViewData(), [
            'mode' => 'edit',
            'pageTitle' => 'Edit brand',
            'pageSubtitle' => 'Update brand context used across digital assets.',
            'backUrl' => route('demo.brand', ['brand' => $this->brandId]),
            'primaryAction' => 'Save changes',
        ]));
    }
}
