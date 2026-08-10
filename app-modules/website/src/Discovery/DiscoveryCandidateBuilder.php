<?php

namespace MoxDop\Website\Discovery;

use App\Models\DiscoveryCandidate;

/**
 * Build deterministic Discovery candidates from crawl Evidence payloads.
 */
final class DiscoveryCandidateBuilder
{
    /**
     * @param  array{
     *     status: string,
     *     seed_url: string,
     *     pages: list<array<string, mixed>>,
     *     failures: list<array<string, mixed>>,
     *     pages_inspected: int,
     *     total_bytes: int
     * }  $crawl
     * @return list<array<string, mixed>>
     */
    public function fromCrawl(array $crawl): array
    {
        $candidates = [];
        $serviceNames = [];
        $locations = [];
        $languages = [];
        $social = [];

        foreach ($crawl['pages'] as $page) {
            $extracted = is_array($page['extracted'] ?? null) ? $page['extracted'] : [];
            $sourceUrl = (string) ($extracted['source_url'] ?? $page['final_url'] ?? $page['requested_url'] ?? '');
            $retrievedAt = now()->toIso8601String();

            foreach (($extracted['same_site_links'] ?? []) as $link) {
                if (! is_array($link)) {
                    continue;
                }
                $label = isset($link['label']) && is_string($link['label']) ? trim($link['label']) : '';
                $url = isset($link['url']) && is_string($link['url']) ? $link['url'] : '';
                if ($label === '' || mb_strlen($label) > 80) {
                    continue;
                }
                if (! $this->looksLikeServiceLabel($label, $url)) {
                    continue;
                }
                $serviceNames[$this->normalizeKey($label)] = [
                    'value' => $label,
                    'source_url' => $sourceUrl !== '' ? $sourceUrl : $url,
                ];
            }

            foreach (($extracted['nav_labels'] ?? []) as $label) {
                if (! is_string($label)) {
                    continue;
                }
                $label = trim($label);
                if ($label === '' || mb_strlen($label) > 60) {
                    continue;
                }
                if ($this->looksLikeServiceLabel($label, null)) {
                    $serviceNames[$this->normalizeKey($label)] = [
                        'value' => $label,
                        'source_url' => $sourceUrl,
                    ];
                }
            }

            foreach (($extracted['address_candidates'] ?? []) as $address) {
                if (! is_string($address) || trim($address) === '') {
                    continue;
                }
                $locations[$this->normalizeKey($address)] = [
                    'value' => trim($address),
                    'source_url' => $sourceUrl,
                ];
            }

            $htmlLang = isset($extracted['html_lang']) && is_string($extracted['html_lang'])
                ? trim($extracted['html_lang'])
                : '';
            if ($htmlLang !== '') {
                $languages[$this->normalizeKey($htmlLang)] = [
                    'value' => $htmlLang,
                    'source_url' => $sourceUrl,
                ];
            }

            foreach (($extracted['hreflang'] ?? []) as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $lang = isset($row['hreflang']) && is_string($row['hreflang']) ? trim($row['hreflang']) : '';
                if ($lang === '' || strtolower($lang) === 'x-default') {
                    continue;
                }
                $languages[$this->normalizeKey($lang)] = [
                    'value' => $lang,
                    'source_url' => $sourceUrl,
                ];
            }

            foreach (($extracted['social_links'] ?? []) as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $platform = isset($row['platform']) && is_string($row['platform']) ? $row['platform'] : '';
                $url = isset($row['url']) && is_string($row['url']) ? $row['url'] : '';
                if ($platform === '' || $url === '') {
                    continue;
                }
                $social[$this->normalizeKey($platform.'|'.$url)] = [
                    'value' => $platform.': '.$url,
                    'platform' => $platform,
                    'url' => $url,
                    'source_url' => $sourceUrl,
                ];
            }

            $title = isset($extracted['title']) && is_string($extracted['title']) ? trim($extracted['title']) : '';
            $h1 = isset($extracted['h1']) && is_string($extracted['h1']) ? trim($extracted['h1']) : '';
            $meta = isset($extracted['meta_description']) && is_string($extracted['meta_description'])
                ? trim($extracted['meta_description'])
                : '';

            if ($meta !== '' && mb_strlen($meta) >= 40) {
                $candidates[] = $this->fact(
                    type: 'business_summary',
                    targetField: 'business_summary',
                    value: $meta,
                    sourceUrl: $sourceUrl,
                    retrievedAt: $retrievedAt,
                    supportLabel: 'moderate',
                    extra: ['from' => 'meta_description'],
                );
            } elseif ($h1 !== '' && $title !== '' && $h1 !== $title) {
                $candidates[] = $this->fact(
                    type: 'business_summary',
                    targetField: 'business_summary',
                    value: $h1,
                    sourceUrl: $sourceUrl,
                    retrievedAt: $retrievedAt,
                    supportLabel: 'weak',
                    extra: ['from' => 'h1'],
                );
            }
        }

        foreach ($serviceNames as $row) {
            $candidates[] = $this->fact(
                type: 'service',
                targetField: 'products_services',
                value: $row['value'],
                sourceUrl: $row['source_url'],
                retrievedAt: now()->toIso8601String(),
                supportLabel: 'strong',
            );
        }

        foreach ($locations as $row) {
            $candidates[] = $this->fact(
                type: 'location',
                targetField: 'target_markets',
                value: $row['value'],
                sourceUrl: $row['source_url'],
                retrievedAt: now()->toIso8601String(),
                supportLabel: 'moderate',
            );
        }

        foreach ($languages as $row) {
            $candidates[] = $this->fact(
                type: 'language',
                targetField: 'languages',
                value: $row['value'],
                sourceUrl: $row['source_url'],
                retrievedAt: now()->toIso8601String(),
                supportLabel: 'strong',
            );
        }

        foreach ($social as $row) {
            $candidates[] = $this->fact(
                type: 'social_link',
                targetField: 'social_links',
                value: $row['value'],
                sourceUrl: $row['source_url'],
                retrievedAt: now()->toIso8601String(),
                supportLabel: 'strong',
                extra: [
                    'platform' => $row['platform'],
                    'profile_url' => $row['url'],
                ],
            );
        }

        return $this->dedupeProposed($candidates);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function inferencesFromAi(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $type = isset($row['type']) && is_string($row['type']) ? $row['type'] : '';
            $field = isset($row['target_field']) && is_string($row['target_field']) ? $row['target_field'] : '';
            $value = isset($row['value']) && is_string($row['value']) ? trim($row['value']) : '';
            if ($type === '' || $field === '' || $value === '') {
                continue;
            }
            $out[] = [
                'candidate_kind' => DiscoveryCandidate::KIND_INFERENCE,
                'candidate_type' => $type,
                'target_field' => $field,
                'proposed_value' => $value,
                'support_label' => 'moderate',
                'support_json' => [
                    'source' => 'ai_inference',
                    'note' => 'AI-derived interpretation from bounded public Discovery Evidence. Not a discovered fact.',
                    'causal_attribution' => false,
                ],
            ];
        }

        return $out;
    }

    /**
     * @param  list<array{domain: string, intersections?: int|null, avg_position?: float|null}>  $competitors
     * @return list<array<string, mixed>>
     */
    public function fromCompetitors(array $competitors, string $provider, ?string $queryNote = null): array
    {
        $out = [];
        $seen = [];
        foreach ($competitors as $row) {
            $domain = isset($row['domain']) && is_string($row['domain']) ? strtolower(trim($row['domain'])) : '';
            if ($domain === '' || isset($seen[$domain])) {
                continue;
            }
            $seen[$domain] = true;
            $out[] = [
                'candidate_kind' => DiscoveryCandidate::KIND_FACT,
                'candidate_type' => 'competitor',
                'target_field' => 'known_competitors',
                'proposed_value' => $domain,
                'support_label' => 'moderate',
                'support_json' => [
                    'source' => $provider,
                    'provider' => $provider,
                    'domain' => $domain,
                    'intersections' => $row['intersections'] ?? null,
                    'avg_position' => $row['avg_position'] ?? null,
                    'query_note' => $queryNote,
                    'retrieved_at' => now()->toIso8601String(),
                ],
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function fact(
        string $type,
        string $targetField,
        string $value,
        string $sourceUrl,
        string $retrievedAt,
        string $supportLabel,
        array $extra = [],
    ): array {
        return [
            'candidate_kind' => DiscoveryCandidate::KIND_FACT,
            'candidate_type' => $type,
            'target_field' => $targetField,
            'proposed_value' => $value,
            'support_label' => $supportLabel,
            'support_json' => array_merge([
                'source_url' => $sourceUrl,
                'retrieved_at' => $retrievedAt,
            ], $extra),
        ];
    }

    private function looksLikeServiceLabel(string $label, ?string $url): bool
    {
        $lower = mb_strtolower($label);
        $blocked = ['home', 'contact', 'about', 'login', 'cart', 'privacy', 'terms', 'cookie', 'blog', 'news'];
        if (in_array($lower, $blocked, true)) {
            return false;
        }

        if ($url !== null) {
            $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?? ''));
            if (str_contains($path, 'service') || str_contains($path, 'product') || str_contains($path, 'offer')) {
                return true;
            }
        }

        return str_word_count($label) <= 6 && mb_strlen($label) >= 3;
    }

    private function normalizeKey(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return list<array<string, mixed>>
     */
    private function dedupeProposed(array $candidates): array
    {
        $seen = [];
        $out = [];
        foreach ($candidates as $candidate) {
            $key = $this->normalizeKey(($candidate['candidate_type'] ?? '').'|'.($candidate['proposed_value'] ?? ''));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $candidate;
        }

        return $out;
    }
}
