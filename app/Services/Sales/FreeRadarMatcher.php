<?php

namespace App\Services\Sales;

use App\Models\SalesRadarPage;
use App\Models\SalesSearchProfile;
use Illuminate\Support\Str;

final class FreeRadarMatcher
{
    public static function fold(string $text): string
    {
        return Str::lower(Str::ascii(str_replace(['İ', 'ı'], ['I', 'i'], $text)));
    }

    public static function hasDemand(string $text): bool
    {
        return (bool) preg_match('/\b(araniyor|ariyorum|ariyoruz|yaptirilacak(?:tir)?|yapilacak|isi verilecektir|yaptirmak|yaptiracagim|yaptiracagiz|teklif talebi|teklif almak|tavsiye|onerisi|looking for|seeking|request for proposal|need a)\b/', self::fold($text));
    }

    /** @return list<string> */
    public function terms(SalesSearchProfile $profile): array
    {
        $service = $profile->catalogService;
        if ($profile->service_catalog_item_id && (! $service || $service->status !== 'active')) {
            return [];
        }
        $terms = $service
            ? array_merge($service->names->where('is_active', true)->pluck('raw_label')->all(), $service->matchingKeywords->pluck('label')->all())
            : ($profile->include_concepts ?? []);
        $terms = array_merge($terms, $profile->include_concepts ?? []);
        $label = self::fold(implode(' ', $terms).' '.($profile->service_definition_code ?? ''));
        foreach ([
            'web|wordpress|website' => ['web sitesi', 'web tasarim', 'wordpress', 'kurumsal site', 'website'],
            'google ads|adwords|google_ads' => ['google ads', 'adwords', 'ads reklam'],
            'seo|arama motoru' => ['seo', 'arama motoru optimizasyonu'],
            'meta|sosyal medya|social_media' => ['meta ads', 'facebook reklam', 'instagram reklam', 'sosyal medya'],
        ] as $pattern => $aliases) {
            if (preg_match('/'.$pattern.'/', $label)) {
                $terms = array_merge($terms, $aliases);
            }
        }
        return array_values(array_filter(array_unique(array_map(fn ($term) => trim(self::fold((string) $term)), $terms)), fn ($term) => strlen($term) >= 3));
    }

    public function matches(string $text, array $terms): bool
    {
        $text = self::fold($text);
        foreach ($terms as $term) {
            if (preg_match('/(?<![a-z0-9])'.preg_quote($term, '/').'(?![a-z0-9])/', $text)) {
                return true;
            }
        }
        return false;
    }

    /** @return array{eligible: bool, score: int, reason: string, stage: string, negatives: array} */
    public function evaluate(SalesSearchProfile $profile, SalesRadarPage $page): array
    {
        $text = self::fold($page->title.' '.($page->excerpt ?? ''));
        $negative = [];
        if (preg_match('/\b(eleman|personel|maasli|tam zamanli|full time|is ariyorum|hizmet veriyoruz|hizmetlerimiz|musteri ariyorum|konu kapali|is verildi|is tamamlandi|anlasildi|alim kapanmistir)\b/', $text)) {
            $negative[] = 'seller_employee_or_closed';
        }
        if ($this->matches($text, array_map(fn ($s) => self::fold((string) $s), array_filter($profile->exclude_concepts ?? [])))) {
            $negative[] = 'excluded_term';
        }
        if ($page->published_at?->lessThan(now()->subDays(30))) {
            $negative[] = 'older_than_30_days';
        }
        $matched = $this->matches($text, $this->terms($profile));
        $demand = self::hasDemand($text);
        $verified = $page->state === 'read' && $page->published_at !== null && $page->fetched_at?->greaterThan(now()->subDays(2));
        $score = $verified ? 80 : 60;
        $market = trim((string) $profile->location);
        if ($market !== '' && ! $this->matches($text, [self::fold($market)])) {
            $negative[] = 'location_unconfirmed';
            $score = min($score, 60);
        }
        $reason = $verified ? 'explicit_demand' : 'review_date_or_content';
        if (in_array('location_unconfirmed', $negative, true)) {
            $reason = 'review_location';
        }
        $reject = array_diff($negative, ['location_unconfirmed']) !== [];
        return [
            'eligible' => $matched && $demand && ! $reject && $score >= $profile->minimum_intent_confidence,
            'score' => $score,
            'reason' => $reason,
            'stage' => $verified && $negative === [] ? 'high_intent' : 'unknown',
            'negatives' => $negative,
        ];
    }
}
