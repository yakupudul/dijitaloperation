<?php

namespace App\Livewire\Operator\GoogleAds;

use App\Models\GoogleAdsConversionBusinessMapping;
use App\Services\GoogleAds\GoogleAdsMeasurementControlService;
use App\Services\GoogleAds\GoogleAdsSpecialistBindingResolver;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Livewire\Component;

class MeasurementControlPanel extends Component
{
    public string $assetId;
    public string $period = 'last_28';
    public ?string $periodStart = null;
    public ?string $periodEnd = null;

    public ?string $mapping_action_id = null;
    public string $mapping_stage = 'lead';
    public ?string $mapping_label = null;
    public ?string $mapping_value = null;
    public bool $mapping_quality_signal = false;
    public ?string $mapping_notes = null;

    /** @var list<string> */
    private const STAGES = [
        'engagement',
        'lead',
        'phone_lead',
        'qualified_lead',
        'appointment',
        'sale',
        'purchase',
        'revenue',
        'other',
    ];

    public function mount(string $assetId, string $period = 'last_28', ?string $periodStart = null, ?string $periodEnd = null): void
    {
        $this->assetId = $assetId;
        $this->period = $period;
        $this->periodStart = $periodStart;
        $this->periodEnd = $periodEnd;
    }

    public function selectMappingAction(string $actionId): void
    {
        $workspace = app(GoogleAdsMeasurementControlService::class)->workspace($this->assetId, $this->periodStart, $this->periodEnd);
        $allowed = collect($workspace['actions'] ?? [])->pluck('id')->map(fn ($id) => (string) $id)->all();
        if (! in_array($actionId, $allowed, true)) {
            return;
        }

        $this->mapping_action_id = $actionId;
        $existing = Schema::hasTable('google_ads_conversion_business_mappings')
            ? GoogleAdsConversionBusinessMapping::query()
                ->where('digital_asset_id', (int) $this->assetId)
                ->where('conversion_action_id', $actionId)
                ->first()
            : null;

        $this->mapping_stage = (string) ($existing?->business_stage ?? 'lead');
        $this->mapping_label = $existing?->business_action_label;
        $this->mapping_value = $existing?->nominal_value !== null ? (string) $existing->nominal_value : null;
        $this->mapping_quality_signal = (bool) ($existing?->is_quality_signal ?? false);
        $this->mapping_notes = $existing?->notes;
    }

    public function saveMapping(): void
    {
        if (! Schema::hasTable('google_ads_conversion_business_mappings')) {
            DemoState::flash('İş Aksiyonu eşleme tablosu henüz hazır değil. Veritabanı geçiş durumunu kontrol edin.', 'warning');
            return;
        }

        $validated = $this->validate([
            'mapping_action_id' => ['required', 'string', 'max:128'],
            'mapping_stage' => ['required', Rule::in(self::STAGES)],
            'mapping_label' => ['nullable', 'string', 'max:160'],
            'mapping_value' => ['nullable', 'numeric', 'min:0', 'max:999999999999.999999'],
            'mapping_quality_signal' => ['boolean'],
            'mapping_notes' => ['nullable', 'string', 'max:1500'],
        ], [], [
            'mapping_action_id' => 'dönüşüm aksiyonu',
            'mapping_stage' => 'iş aşaması',
            'mapping_label' => 'aksiyon etiketi',
            'mapping_value' => 'nominal iş değeri',
            'mapping_quality_signal' => 'kalite sinyali',
            'mapping_notes' => 'not',
        ]);

        $validIds = app(GoogleAdsMeasurementControlService::class)->validActionIds($this->assetId, $this->periodStart, $this->periodEnd);
        if (! in_array((string) $validated['mapping_action_id'], $validIds, true)) {
            DemoState::flash('Seçilen dönüşüm aksiyonu bu Google Ads varlığına ait değil.', 'warning');
            return;
        }

        $binding = app(GoogleAdsSpecialistBindingResolver::class)->resolve($this->assetId);
        $userId = auth()->id();

        $mapping = GoogleAdsConversionBusinessMapping::query()->firstOrNew([
            'digital_asset_id' => (int) $this->assetId,
            'conversion_action_id' => (string) $validated['mapping_action_id'],
        ]);
        if (! $mapping->exists) {
            $mapping->created_by_user_id = $userId;
        }

        $mapping->fill([
            'business_stage' => (string) $validated['mapping_stage'],
            'business_action_label' => filled($validated['mapping_label'] ?? null) ? trim((string) $validated['mapping_label']) : null,
            'nominal_value' => filled($validated['mapping_value'] ?? null) ? (float) $validated['mapping_value'] : null,
            'currency' => $binding->currency ?: null,
            'is_quality_signal' => (bool) $validated['mapping_quality_signal'],
            'notes' => filled($validated['mapping_notes'] ?? null) ? trim((string) $validated['mapping_notes']) : null,
            'updated_by_user_id' => $userId,
        ])->save();

        DemoState::flash('Dönüşüm aksiyonu → İş Aksiyonu eşlemesi kaydedildi.', 'success');
    }

    public function deleteMapping(int $mappingId): void
    {
        if (! Schema::hasTable('google_ads_conversion_business_mappings')) {
            return;
        }

        GoogleAdsConversionBusinessMapping::query()
            ->whereKey($mappingId)
            ->where('digital_asset_id', (int) $this->assetId)
            ->delete();

        $this->mapping_action_id = null;
        $this->mapping_stage = 'lead';
        $this->mapping_label = null;
        $this->mapping_value = null;
        $this->mapping_quality_signal = false;
        $this->mapping_notes = null;
        DemoState::flash('İş Aksiyonu eşlemesi kaldırıldı.', 'info');
    }

    public function clearMappingForm(): void
    {
        $this->mapping_action_id = null;
        $this->mapping_stage = 'lead';
        $this->mapping_label = null;
        $this->mapping_value = null;
        $this->mapping_quality_signal = false;
        $this->mapping_notes = null;
        $this->resetValidation();
    }

    public function render(): View
    {
        $control = app(GoogleAdsMeasurementControlService::class)->workspace(
            $this->assetId,
            $this->periodStart,
            $this->periodEnd,
        );

        $view = app()->getLocale() === 'tr'
            ? 'livewire.operator.google-ads.measurement-control-panel-tr'
            : 'livewire.operator.google-ads.measurement-control-panel';

        return view($view, [
            'control' => $control,
            'stageOptions' => self::STAGES,
        ]);
    }
}
