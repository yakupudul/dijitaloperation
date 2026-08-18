<?php

namespace App\Livewire\Demo\Sales;

use App\Models\Prospect;
use App\Services\Prospects\ConvertProspectService;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Convert Prospect')]
class ProspectConvert extends Component
{
    public string $prospectId = '';

    public string $customer_name = '';

    public string $brand_name = '';

    public string $existing_customer_id = '';

    public string $existing_brand_id = '';

    public bool $confirm_create_despite_duplicates = false;

    public bool $promote_observed_summary = false;

    /** @var list<string> */
    public array $selected_assets = [];

    /**
     * @var array<string, mixed>
     */
    public array $preview = [];

    public function mount(string $prospectId): void
    {
        abort_unless(ctype_digit($prospectId), 404);
        $prospect = Prospect::query()->find($prospectId);
        abort_if($prospect === null, 404);

        $this->prospectId = $prospectId;
        $this->preview = app(ConvertProspectService::class)->preview($prospect);
        $this->customer_name = (string) ($this->preview['customer_name'] ?? $prospect->company_name);
        $this->brand_name = (string) ($this->preview['brand_name'] ?? $prospect->company_name);
        $this->selected_assets = collect($this->preview['promotable_assets'] ?? [])
            ->filter(fn (array $item): bool => $item['supported'] ?? false)
            ->pluck('key')
            ->map(fn ($key): string => (string) $key)
            ->all();
    }

    public function convert(): mixed
    {
        $prospect = Prospect::query()->findOrFail($this->prospectId);

        try {
            $converted = app(ConvertProspectService::class)->convert($prospect, [
                'customer_name' => $this->customer_name,
                'brand_name' => $this->brand_name,
                'existing_customer_id' => $this->existing_customer_id !== '' ? $this->existing_customer_id : null,
                'existing_brand_id' => $this->existing_brand_id !== '' ? $this->existing_brand_id : null,
                'confirm_create_despite_duplicates' => $this->confirm_create_despite_duplicates,
                'promote_observed_summary' => $this->promote_observed_summary,
                'selected_assets' => $this->selected_assets,
            ], auth()->user());
        } catch (ValidationException $exception) {
            $this->preview = app(ConvertProspectService::class)->preview($prospect->fresh());
            throw $exception;
        }

        DemoState::flash(__('operator.prospects.conversion.completed', ['name' => $converted->company_name]));

        return $this->redirect(route('operator.prospect', ['prospectId' => $converted->id]), navigate: true);
    }

    public function render(): View
    {
        $prospect = Prospect::query()->findOrFail($this->prospectId);
        $this->preview = app(ConvertProspectService::class)->preview($prospect);

        return view('livewire.demo.sales.prospect-convert', [
            'prospect' => $prospect,
            'preview' => $this->preview,
        ]);
    }
}
