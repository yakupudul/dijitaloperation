<?php

namespace App\Services\Integrations;

use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Services\SeoTasks\SeoText;

/**
 * Faz 12: when an operator binds a discovered Google / Meta account, the brand is never pre-selected; this only
 * suggests the brand whose name or website domain shares the most words with the account name. The operator
 * still picks the brand.
 */
final class BrandMatchSuggester
{
    public function suggest(string $accountName): ?Brand
    {
        $words = $this->words($accountName);
        if ($words === []) {
            return null;
        }
        $best = null;
        $bestScore = 0;
        $domains = DigitalAsset::query()->whereNotNull('domain')->get(['brand_id', 'domain'])->groupBy('brand_id');
        foreach (Brand::query()->orderBy('name')->get(['id', 'name']) as $brand) {
            $brandWords = $this->words((string) $brand->name);
            foreach ($domains->get($brand->id, collect()) as $asset) {
                $brandWords = [...$brandWords, ...$this->words((string) preg_replace('/\.(com|net|org|com\.tr|tr|info|biz)$/', '', (string) $asset->domain))];
            }
            $score = count(array_intersect($words, array_unique($brandWords)));
            if ($score > $bestScore) {
                [$best, $bestScore] = [$brand, $score];
            }
        }

        return $best;
    }

    /** @return list<string> */
    private function words(string $text): array
    {
        return array_values(array_unique(array_filter(
            explode(' ', SeoText::fold(str_replace(['.', '-', '_'], ' ', $text))),
            static fn (string $word): bool => mb_strlen($word) >= 4 && ! in_array($word, ['klinik', 'clinic', 'google', 'meta', 'account', 'hesap', 'reklam', 'ads'], true),
        )));
    }
}
