<?php

namespace App\Services\SearchDemand;

use App\Models\ServiceCatalogItem;
use App\Models\ServiceMatchingKeyword;
use App\Support\Options\LocationOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ServiceKeywordService
{
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

    public function matches(string $text, array $ids, ?\Illuminate\Support\Collection $words = null): array
    {
        $haystack = ' '.LocationOptions::fold($text).' ';

        return ($words ?? ServiceMatchingKeyword::query()->whereIn('service_catalog_item_id', $ids)->get())
            ->filter(fn ($word): bool => str_contains($haystack, ' '.$word->normalized_key.' '))
            ->pluck('service_catalog_item_id')->unique()->values()->all();
    }
}