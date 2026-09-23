<?php

namespace App\Livewire\Demo;

use App\Enums\TaskScopeKind;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Task;
use App\Services\Tasks\CreateDirectTask;
use App\Support\Demo\DemoState;
use App\Support\Work\WorkUrl;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

class CaptureModal extends Component
{
    public bool $open = false;

    public string $captureType = 'task';

    #[Url(as: 'capture_brand')]
    public ?string $prefillBrand = null;

    #[Url(as: 'capture_customer')]
    public ?string $prefillCustomer = null;

    public ?string $prefillAsset = null;

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
        $this->captureNonce = (string) Str::uuid();
    }

    #[On('open-capture')]
    public function openCapture(?string $type = null, ?string $brand = null, ?string $customer = null, ?string $path = null): void
    {
        $this->open = true;
        if ($type !== null && $type === 'task') {
            $this->captureType = $type;
        }

        $inferred = $this->inferContextFromPath($path);

        if ($customer !== null && $customer !== '') {
            $this->prefillCustomer = $customer;
        } elseif ($inferred['customer'] !== null) {
            $this->prefillCustomer = $inferred['customer'];
        }

        if ($brand !== null && $brand !== '') {
            $this->prefillBrand = $brand;
        } elseif ($inferred['brand'] !== null) {
            $this->prefillBrand = $inferred['brand'];
        }

        if ($inferred['asset'] !== null) {
            $this->prefillAsset = $inferred['asset'];
        }

        $this->clearIncompatibleBrand();
        $this->resetForm();
    }

    public function close(): void
    {
        $this->open = false;
        $this->resetForm();
    }

    public function setCaptureType(string $type): void
    {
        if ($type === 'task') {
            $this->captureType = $type;
        }
    }

    public function updatedPrefillCustomer(mixed $value): void
    {
        $this->prefillCustomer = is_numeric($value) ? (string) $value : null;
        $this->clearIncompatibleBrand();
    }

    public function updatedPrefillBrand(mixed $value): void
    {
        $this->prefillBrand = is_numeric($value) ? (string) $value : null;
        $this->clearIncompatibleBrand();
    }

    public function save(): void
    {
        $this->validate([
            'title' => ['required', 'string', 'min:2', 'max:200'],
        ]);

        $saved = match ($this->captureType) {
            'task' => $this->saveDirectTask(),
            'opportunity_hypothesis' => $this->saveOpportunityHypothesis(),
            'note' => $this->saveNote(),
            default => false,
        };

        if ($saved instanceof Task) {
            $this->open = false;
            $this->resetForm();
            $this->redirect(WorkUrl::show(WorkUrl::TYPE_TASK, $saved->id));

            return;
        }

        if ($saved === true) {
            $this->close();
            $this->dispatch('capture-saved');
        }
    }

    public function render(): View
    {
        $customerId = is_numeric($this->prefillCustomer) ? (int) $this->prefillCustomer : null;

        return view('livewire.demo.capture-modal', [
            'customerOptions' => Customer::query()->orderBy('name')->pluck('name', 'id')->all(),
            'brandOptions' => $customerId === null
                ? []
                : Brand::query()->where('customer_id', $customerId)->orderBy('name')->pluck('name', 'id')->all(),
        ]);
    }

    private function saveDirectTask(): Task|false
    {
        if (! is_numeric($this->prefillCustomer)) {
            throw ValidationException::withMessages([
                'prefillCustomer' => [__('operator.capture.validation.customer')],
            ]);
        }

        $customer = Customer::query()->find((int) $this->prefillCustomer);
        if ($customer === null) {
            throw ValidationException::withMessages([
                'prefillCustomer' => [__('operator.capture.validation.customer')],
            ]);
        }

        $brand = is_numeric($this->prefillBrand) ? Brand::query()->find((int) $this->prefillBrand) : null;
        if ($brand !== null && (int) $brand->customer_id !== (int) $customer->id) {
            $this->prefillBrand = null;
            DemoState::flash(__('operator.flash.brand_must_belong'));

            return false;
        }

        $asset = is_numeric($this->prefillAsset) ? DigitalAsset::query()->find((int) $this->prefillAsset) : null;
        if ($asset !== null && $brand !== null && (int) $asset->brand_id !== (int) $brand->id) {
            $asset = null;
        }
        if ($asset !== null && $brand === null) {
            $brand = $asset->brand;
            if ($brand !== null && (int) $brand->customer_id !== (int) $customer->id) {
                $asset = null;
                $brand = null;
            }
        }

        $scopeKind = $asset !== null
            ? TaskScopeKind::DigitalAsset
            : ($brand !== null ? TaskScopeKind::Brand : TaskScopeKind::Customer);

        try {
            $task = app(CreateDirectTask::class)->create([
                'title' => $this->title,
                'action' => $this->description !== '' ? $this->description : $this->title,
                'customer_id' => $customer->id,
                'brand_id' => $brand?->id,
                'digital_asset_id' => $asset?->id,
                'scope_kind' => $scopeKind->value,
                'priority' => $this->priority,
                'due_date' => null,
            ], auth()->user(), 'capture-direct-task:'.$this->captureNonce);

            $this->captureNonce = (string) Str::uuid();
            DemoState::flash(__('operator.flash.task_captured_direct'));

            return $task;
        } catch (ValidationException $exception) {
            DemoState::flash(collect($exception->errors())->flatten()->first() ?? 'Direct Task capture failed.');

            return false;
        } catch (\Throwable $exception) {
            DemoState::flash($exception->getMessage());

            return false;
        }
    }

    private function saveOpportunityHypothesis(): bool
    {
        DemoState::flash(__('operator.flash.capture_opportunity_unavailable'), 'info');

        return false;
    }

    private function saveNote(): bool
    {
        DemoState::flash(__('operator.flash.capture_note_unavailable'), 'info');

        return false;
    }

    private function clearIncompatibleBrand(): void
    {
        if (! is_numeric($this->prefillBrand)) {
            $this->prefillBrand = null;

            return;
        }

        if (! is_numeric($this->prefillCustomer)) {
            $this->prefillBrand = null;

            return;
        }

        $brand = Brand::query()->find((int) $this->prefillBrand);
        if ($brand === null || (int) $brand->customer_id !== (int) $this->prefillCustomer) {
            $this->prefillBrand = null;
        }
    }

    /**
     * @return array{customer: ?string, brand: ?string, asset: ?string}
     */
    private function inferContextFromPath(?string $path): array
    {
        $empty = ['customer' => null, 'brand' => null, 'asset' => null];
        if (! is_string($path) || $path === '') {
            return $empty;
        }

        $normalized = '/'.ltrim($path, '/');

        if (preg_match('#^/customers/(\d+)(?:/|$)#', $normalized, $matches) === 1) {
            $customer = Customer::query()->find((int) $matches[1]);

            return [
                'customer' => $customer !== null ? (string) $customer->id : null,
                'brand' => null,
                'asset' => null,
            ];
        }

        if (preg_match('#^/brands/(\d+)(?:/|$)#', $normalized, $matches) === 1) {
            $brand = Brand::query()->find((int) $matches[1]);

            return [
                'customer' => $brand?->customer_id !== null ? (string) $brand->customer_id : null,
                'brand' => $brand !== null ? (string) $brand->id : null,
                'asset' => null,
            ];
        }

        if (preg_match('#^/assets/(?:website|gbp|google-ads|meta|analytics|search-console|instagram)/(\d+)(?:/|$)#', $normalized, $matches) === 1) {
            $asset = DigitalAsset::query()->with('brand')->find((int) $matches[1]);
            $brand = $asset?->brand;

            return [
                'customer' => $brand?->customer_id !== null ? (string) $brand->customer_id : null,
                'brand' => $brand !== null ? (string) $brand->id : null,
                'asset' => $asset !== null ? (string) $asset->id : null,
            ];
        }

        return $empty;
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
