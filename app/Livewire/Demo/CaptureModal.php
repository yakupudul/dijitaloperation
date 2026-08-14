<?php

namespace App\Livewire\Demo;

use App\Enums\ClientRequestChannel;
use App\Enums\TaskScopeKind;
use App\Models\Brand;
use App\Models\Customer;
use App\Services\ClientRequests\CreateClientRequest;
use App\Services\Tasks\CreateDirectTask;
use App\Support\Demo\AgencyExecutionFixtures;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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

    public string $captureNonce = '';

    public function mount(): void
    {
        if ($this->prefillBrand === null) {
            $this->prefillBrand = DemoCatalog::BRAND_ID;
        }
        if ($this->prefillCustomer === null) {
            $this->prefillCustomer = DemoCatalog::CUSTOMER_ID;
        }
        $this->captureNonce = (string) Str::uuid();
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
            'client_request' => $this->saveClientRequest(),
            'task' => $this->saveDirectTask(),
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

    private function saveClientRequest(): void
    {
        $customerId = $this->prefillCustomer;
        $brandId = $this->prefillBrand;

        if (! is_numeric($customerId) || ! is_numeric($brandId)) {
            DemoState::flash('Client Request capture requires a production Customer and Brand.');

            return;
        }

        $customer = Customer::query()->find((int) $customerId);
        $brand = Brand::query()->find((int) $brandId);

        if ($customer === null || $brand === null) {
            DemoState::flash('Client Request capture requires a production Customer and Brand.');

            return;
        }

        $channel = ClientRequestChannel::tryFrom($this->source)?->value
            ?? ClientRequestChannel::Other->value;

        try {
            app(CreateClientRequest::class)->create([
                'title' => $this->title,
                'description' => $this->description,
                'customer_id' => $customer->id,
                'brand_id' => $brand->id,
                'channel' => $channel,
                'priority' => $this->priority,
                'due_label' => $this->due !== '' ? $this->due : null,
            ], auth()->user(), 'capture-client-request:'.$this->captureNonce);

            $this->captureNonce = (string) Str::uuid();
            DemoState::flash(__('operator.capture.saved_request'));
        } catch (ValidationException $exception) {
            DemoState::flash(collect($exception->errors())->flatten()->first() ?? 'Client Request capture failed.');
        }
    }

    private function saveDirectTask(): void
    {
        $customerId = $this->prefillCustomer;
        $brandId = $this->prefillBrand;

        if (! is_numeric($customerId)) {
            DemoState::flash('Direct Task capture requires a production Customer.');

            return;
        }

        $customer = Customer::query()->find((int) $customerId);
        if ($customer === null) {
            DemoState::flash('Direct Task capture requires a production Customer.');

            return;
        }

        $brand = is_numeric($brandId) ? Brand::query()->find((int) $brandId) : null;
        if ($brand !== null && (int) $brand->customer_id !== (int) $customer->id) {
            DemoState::flash('Brand must belong to the selected Customer.');

            return;
        }

        try {
            app(CreateDirectTask::class)->create([
                'title' => $this->title,
                'action' => $this->description !== '' ? $this->description : $this->title,
                'customer_id' => $customer->id,
                'brand_id' => $brand?->id,
                'digital_asset_id' => null,
                'scope_kind' => $brand !== null
                    ? TaskScopeKind::Brand->value
                    : TaskScopeKind::Customer->value,
                'priority' => $this->priority,
                'due_date' => null,
            ], auth()->user(), 'capture-direct-task:'.$this->captureNonce);

            $this->captureNonce = (string) Str::uuid();
            DemoState::flash('Task captured — Direct source, no fake Recommendation or Client Request.');
        } catch (ValidationException $exception) {
            DemoState::flash(collect($exception->errors())->flatten()->first() ?? 'Direct Task capture failed.');
        } catch (\Throwable $exception) {
            DemoState::flash($exception->getMessage());
        }
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
        $this->captureNonce = (string) Str::uuid();
        $this->resetValidation();
    }
}
