<?php

namespace App\Livewire\Demo;

use App\Support\Demo\AgencyExecutionFixtures;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

class CaptureModal extends Component
{
    public bool $open = false;

    public string $captureType = 'client_request';

    #[Url(as: 'capture_brand')]
    public ?string $prefillBrand = null;

    #[Url(as: 'capture_customer')]
    public ?string $prefillCustomer = null;

    public string $title = '';

    public string $description = '';

    public string $source = 'meeting';

    public string $priority = 'medium';

    public string $due = 'Next week';

    public string $note_scope = 'Operations';

    public string $noteKind = 'note';

    public string $service_code = 'seo';

    public function mount(): void
    {
        if ($this->prefillBrand === null) {
            $this->prefillBrand = DemoCatalog::BRAND_ID;
        }
        if ($this->prefillCustomer === null) {
            $this->prefillCustomer = DemoCatalog::CUSTOMER_ID;
        }
    }

    #[On('open-capture')]
    public function openCapture(?string $type = null, ?string $brand = null, ?string $customer = null): void
    {
        $this->open = true;
        if ($type !== null && in_array($type, ['client_request', 'task', 'opportunity_hypothesis', 'note'], true)) {
            $this->captureType = $type;
        }
        if ($brand !== null) {
            $this->prefillBrand = $brand;
        }
        if ($customer !== null) {
            $this->prefillCustomer = $customer;
        }
        $this->resetForm();
    }

    public function close(): void
    {
        $this->open = false;
        $this->resetForm();
    }

    public function setCaptureType(string $type): void
    {
        if (in_array($type, ['client_request', 'task', 'opportunity_hypothesis', 'note'], true)) {
            $this->captureType = $type;
        }
    }

    public function save(): void
    {
        $this->validate([
            'title' => ['required', 'string', 'min:2', 'max:200'],
        ]);

        match ($this->captureType) {
            'client_request' => DemoState::captureClientRequest([
                'title' => $this->title,
                'description' => $this->description,
                'brand_id' => $this->prefillBrand,
                'customer_id' => $this->prefillCustomer,
                'source' => $this->source,
                'priority' => $this->priority,
                'due' => $this->due,
            ]),
            'task' => DemoState::captureTask([
                'title' => $this->title,
                'description' => $this->description,
                'brand_id' => $this->prefillBrand,
                'priority' => $this->priority,
                'due' => $this->due,
            ]),
            'opportunity_hypothesis' => DemoState::captureOpportunityHypothesis([
                'title' => $this->title,
                'description' => $this->description,
                'brand_id' => $this->prefillBrand,
                'service_code' => $this->service_code,
            ]),
            'note' => DemoState::captureNote([
                'title' => $this->title,
                'body' => $this->description,
                'scope' => $this->note_scope,
                'kind' => $this->noteKind,
                'brand_id' => $this->prefillBrand,
                'customer_id' => $this->prefillCustomer,
            ]),
            default => null,
        };

        $this->close();
        $this->dispatch('capture-saved');
    }

    public function render(): View
    {
        return view('livewire.demo.capture-modal', [
            'defaults' => AgencyExecutionFixtures::captureDefaults(),
        ]);
    }

    private function resetForm(): void
    {
        $this->title = '';
        $this->description = '';
        $this->source = 'meeting';
        $this->priority = 'medium';
        $this->due = 'Next week';
        $this->note_scope = 'Operations';
        $this->noteKind = 'note';
        $this->service_code = 'seo';
        $this->resetValidation();
    }
}
