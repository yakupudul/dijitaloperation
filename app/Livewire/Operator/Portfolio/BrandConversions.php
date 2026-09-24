<?php

namespace App\Livewire\Operator\Portfolio;

use App\Models\Brand;
use App\Models\BrandConversionSource;
use App\Services\Measurement\BrandConversionDictionary;
use App\Support\BrandIntelligence\ConversionGoalTypes;
use App\Support\Permissions;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Brand page › İşletme › Dönüşümler: what counts as a conversion for this brand across GA4, Google Ads,
 * Meta and Business Profile, with the last 30 days against the 30 days before.
 */
final class BrandConversions extends Component
{
    #[Locked]
    public int $brandId;

    public string $message = '';

    public function mount(int $brandId, BrandConversionDictionary $dictionary): void
    {
        abort_unless(auth()->user()?->is_active && auth()->user()?->can(Permissions::ACCESS_APP), 403);
        $this->brandId = $brandId;
        $brand = Brand::query()->findOrFail($brandId);
        if (! BrandConversionSource::query()->where('brand_id', $brandId)->exists()) {
            $dictionary->discover($brand);
        }
    }

    public function refresh(BrandConversionDictionary $dictionary): void
    {
        $stats = $dictionary->discover(Brand::query()->findOrFail($this->brandId));
        $this->message = sprintf('%d sinyal bulundu, %d tanesi yeni.', $stats['found'], $stats['created']);
    }

    public function setType(int $id, string $type, BrandConversionDictionary $dictionary): void
    {
        $row = BrandConversionSource::query()->where('brand_id', $this->brandId)->findOrFail($id);
        abort_unless(array_key_exists($type, ConversionGoalTypes::options()), 422);
        $dictionary->set($row, $type, (bool) $row->counts);
    }

    public function toggleCounts(int $id, BrandConversionDictionary $dictionary): void
    {
        $row = BrandConversionSource::query()->where('brand_id', $this->brandId)->findOrFail($id);
        $dictionary->set($row, $row->conversion_type, ! $row->counts);
    }

    public function render(BrandConversionDictionary $dictionary): View
    {
        $brand = Brand::query()->findOrFail($this->brandId);
        $summary = $dictionary->summary($brand);
        $rows = BrandConversionSource::query()->where('brand_id', $this->brandId)->orderByDesc('counts')->orderBy('source')->orderBy('label')->get();

        return view('livewire.operator.portfolio.brand-conversions', [
            'summary' => $summary,
            'rows' => $rows,
            'types' => self::typeLabels(),
            'sources' => self::sourceLabels(),
        ]);
    }

    /** @return array<string, string> */
    public static function typeLabels(): array
    {
        return [
            ConversionGoalTypes::FORM_SUBMISSION => 'Form',
            ConversionGoalTypes::PHONE_CALL => 'Telefon araması',
            ConversionGoalTypes::WHATSAPP_CONVERSATION => 'WhatsApp / mesaj',
            ConversionGoalTypes::APPOINTMENT_REQUEST => 'Randevu talebi',
            ConversionGoalTypes::BOOKING => 'Rezervasyon',
            ConversionGoalTypes::PURCHASE => 'Satış',
            ConversionGoalTypes::QUALIFIED_LEAD => 'Nitelikli aday',
            ConversionGoalTypes::CUSTOM => 'Diğer',
        ];
    }

    /** @return array<string, string> */
    public static function sourceLabels(): array
    {
        return [
            BrandConversionSource::SOURCE_GA4 => 'GA4',
            BrandConversionSource::SOURCE_GOOGLE_ADS => 'Google Ads',
            BrandConversionSource::SOURCE_META => 'Meta',
            BrandConversionSource::SOURCE_GBP => 'İşletme Profili',
        ];
    }
}
