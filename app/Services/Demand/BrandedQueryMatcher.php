<?php

namespace App\Services\Demand;

use App\Models\Brand;
use App\Services\BrandSetup\BrandSetupMatcher;
use App\Services\SeoTasks\SeoText;

/**
 * Branded search query test for one brand: the query contains the compact folded brand name or the website
 * domain root (≥ 4 letters). Single words of the brand name are not used: "Atlas Dental Kliniği" must not
 * make every "dental" query branded.
 */
final class BrandedQueryMatcher
{
    /** @param  list<string>  $marks */
    private function __construct(public readonly array $marks) {}

    public static function for(Brand $brand): self
    {
        $marks = [str_replace(' ', '', SeoText::fold((string) $brand->name))];
        foreach ($brand->digitalAssets()->where('type', 'website')->get(['primary_url', 'domain']) as $site) {
            $marks[] = SeoText::fold(BrandSetupMatcher::domainRoot(BrandSetupMatcher::host((string) ($site->primary_url ?: $site->domain))));
        }

        return new self(array_values(array_unique(array_filter($marks, fn (string $mark): bool => mb_strlen($mark) >= 4))));
    }

    public function isBranded(string $query): bool
    {
        $compact = str_replace(' ', '', SeoText::fold($query));
        foreach ($this->marks as $mark) {
            if (str_contains($compact, $mark)) {
                return true;
            }
        }

        return false;
    }
}
