<?php

namespace App\Services\SearchDemand;

use App\Models\ServiceCatalogItem;
use App\Models\ServiceMatchingKeyword;
use App\Support\Options\LocationOptions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ServiceKeywordService
{
    /** Folded words too generic to assign a query to one service on their own. */
    private const array GENERIC = ['fiyat', 'fiyati', 'fiyatlari', 'ucret', 'ucretleri', 'ameliyat', 'ameliyati', 'operasyon', 'tedavi', 'tedavisi', 'tedavileri',
        'estetik', 'estetigi', 'cerrahi', 'klinik', 'klinigi', 'doktor', 'doktoru', 'uzman', 'uzmani', 'hastane', 'merkez', 'merkezi', 'hizmet', 'hizmeti',
        'nedir', 'nasil', 'yorum', 'yorumlar', 'oncesi sonrasi', 'en iyi', 'surgery', 'clinic', 'cost', 'price', 'doctor', 'treatment', 'hospital'];

    public static function isGeneric(string $label): bool
    {
        return in_array(LocationOptions::fold($label), self::GENERIC, true);
    }

    public function replace(ServiceCatalogItem $service, string $text): void
    {
        $keywords = [];
        foreach (preg_split('/[\r\n,;]+/u', $text) ?: [] as $label) {
            $label = trim($label);
            $key = LocationOptions::fold($label);
            if ($key === '') {
                continue;
            }
            if (mb_strlen($label) > 255 || mb_strlen($key) > 255) {
                throw ValidationException::withMessages(['matching_words' => 'Her ifade en fazla 255 karakter olabilir.']);
            }
            $keywords[$key] = $label;
        }
        if (count($keywords) > 200) {
            throw ValidationException::withMessages(['matching_words' => 'Bir hizmete en fazla 200 ifade ekleyebilirsiniz.']);
        }
        DB::transaction(function () use ($service, $keywords): void {
            ServiceCatalogItem::query()->lockForUpdate()->findOrFail($service->id);
            $service->matchingKeywords()->delete();
            foreach ($keywords as $key => $label) {
                ServiceMatchingKeyword::query()->create(['service_catalog_item_id' => $service->id, 'label' => $label, 'normalized_key' => $key]);
            }
        });
    }

    /**
     * Add matching expressions without touching the existing ones (operator edits stay). Locations
     * are stripped so the service stays reusable for brands in other places.
     *
     * @param  list<string>  $labels
     * @return list<string> labels actually added
     */
    public function append(ServiceCatalogItem $service, array $labels): array
    {
        $existing = $service->matchingKeywords()->pluck('label', 'normalized_key')->all();
        $added = [];
        foreach ($labels as $label) {
            $label = trim(LocationOptions::strip((string) $label)['text']);
            $key = LocationOptions::fold($label);
            if (mb_strlen($key) < 3 || mb_strlen($label) > 255 || isset($existing[$key]) || in_array($key, self::GENERIC, true)) {
                continue;
            }
            $existing[$key] = $label;
            $added[] = $label;
        }
        if ($added === [] || count($existing) > 200) {
            return [];
        }
        $this->replace($service, implode("\n", $existing));

        return $added;
    }

    public function matches(string $text, array $ids, ?Collection $words = null): array
    {
        $haystack = ' '.LocationOptions::fold($text).' ';

        return ($words ?? ServiceMatchingKeyword::query()->whereIn('service_catalog_item_id', $ids)->get())
            ->filter(fn ($word): bool => str_contains($haystack, ' '.$word->normalized_key.' '))
            ->pluck('service_catalog_item_id')->unique()->values()->all();
    }
}
