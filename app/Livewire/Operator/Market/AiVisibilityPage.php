<?php

namespace App\Livewire\Operator\Market;

use App\Enums\CustomerStatus;
use App\Models\Brand;
use App\Models\Intel\BrandIntelSetting;
use App\Services\Intel\AiVisibilityService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Pazar › AI görünürlüğü (Faz 10c): customer-style questions to the AI assistant, whether the brand is named and
 * where in the list, competitors named, and the trend across checks. AI runs only on click.
 */
#[Layout('operator.layouts.app')]
#[Title('AI görünürlüğü')]
final class AiVisibilityPage extends Component
{
    #[Url]
    public ?int $brand = null;

    #[Url]
    public ?string $batch = null;

    public string $prompts = '';

    public string $message = '';

    public string $error = '';

    public function updatedBrand(): void
    {
        $this->prompts = '';
        $this->batch = null;
    }

    public function savePrompts(): void
    {
        $brand = $this->selectedBrand() ?? abort(404);
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $this->prompts) ?: [])));
        BrandIntelSetting::for($brand)->fill(['ai_visibility_prompts' => array_slice($lines, 0, (int) config('moxdop-intel.ai_visibility.max_prompts', 6)), 'updated_by' => auth()->id()])->save();
        $this->message = 'Sorular kaydedildi.';
    }

    public function check(AiVisibilityService $service): void
    {
        $brand = $this->selectedBrand() ?? abort(404);
        try {
            $this->batch = $service->run($brand, preg_split('/\r?\n/', $this->prompts) ?: [], auth()->user());
            $this->error = '';
            $this->message = 'Sorular AI\'ya soruluyor; sonuçlar birkaç dakikada gelir.';
        } catch (ValidationException $exception) {
            $this->message = '';
            $this->error = (string) collect($exception->errors())->flatten()->first();
        }
    }

    public function render(AiVisibilityService $service): View
    {
        $brands = Brand::query()->whereHas('customer', fn ($q) => $q->where('status', CustomerStatus::Active->value))->orderBy('name')->get(['id', 'name']);
        $this->brand ??= $brands->first()?->id;
        $brand = $this->selectedBrand();
        if ($brand !== null && $this->prompts === '') {
            $this->prompts = implode("\n", $service->prompts($brand));
        }
        $history = $brand !== null ? $service->history($brand) : [];
        $this->batch ??= $history[0]['batch'] ?? null;

        return view('livewire.operator.market.ai-visibility', [
            'brands' => $brands,
            'history' => $history,
            'rows' => $this->batch !== null ? DB::table('ai_visibility_checks')->where('batch', $this->batch)->where('brand_id', $this->brand)->orderBy('id')->get() : collect(),
            'max' => (int) config('moxdop-intel.ai_visibility.max_prompts', 6),
        ]);
    }

    private function selectedBrand(): ?Brand
    {
        return $this->brand !== null ? Brand::query()->find($this->brand) : null;
    }
}
