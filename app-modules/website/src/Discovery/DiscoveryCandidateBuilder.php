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
    public function fromCrawl(array $crawl, array $knownServiceNames = []): array
    {
        $candidates = [];
        foreach ($crawl['pages'] as $page) {
            $extracted = $page['extracted'] ?? [];
            $sourceUrl = (string) ($extracted['source_url'] ?? $page['final_url'] ?? '');
            $observedAt = $page['observed_at'] ?? null;
            $source = [
                'url' => $sourceUrl, 'observed_at' => $observedAt,
                'snapshot_id' => $page['snapshot_id'] ?? null,
                'raw_ingestion_object_id' => $page['raw_ingestion_object_id'] ?? null,
                'html_hash' => $page['html_hash'] ?? null,
            ];
            $add = function (string $type, string $field, string $value, string $from, array $extra = []) use (&$candidates, $source, $sourceUrl, $observedAt): void {
                $value = trim($value);
                if ($value === '' || mb_strlen($value) > 2000) {
                    return;
                }
                $candidates[] = [
                    'candidate_kind' => DiscoveryCandidate::KIND_FACT,
                    'candidate_type' => $type, 'target_field' => $field, 'proposed_value' => $value,
                    'support_label' => 'moderate',
                    'support_json' => array_merge([
                        'source_url' => $sourceUrl, 'retrieved_at' => $observedAt,
                        'normalization_version' => DiscoveryConfig::VERSION,
                        'sources' => [array_merge($source, ['from' => $from, 'excerpt' => mb_substr($value, 0, 500)])],
                        'claim_type' => 'website_statement',
                    ], $extra),
                ];
            };
            $known = array_map($this->normalizeKey(...), $knownServiceNames);
            $heading = $extracted['h1'] ?? '';
            if (is_string($heading) && $heading !== '' && filled($extracted['main_text_excerpt'] ?? null)
                && in_array($this->normalizeKey($heading), $known, true)
                && rtrim($sourceUrl, '/') !== rtrim($crawl['seed_url'], '/')) {
                $add('service', 'products_services', $heading, 'known_service_heading');
            }
            foreach ($extracted['service_claims'] ?? [] as $claim) {
                $add('service', 'products_services', $claim['name'], $claim['from']);
            }
            foreach ($extracted['service_area_claims'] ?? [] as $area) {
                $add('service_area', 'service_areas', $area, 'structured_area_served');
            }
            foreach ($extracted['address_candidates'] ?? [] as $address) {
                $add('physical_address', 'physical_addresses', $address, 'address_text');
            }
            foreach (['phones' => 'phone', 'emails' => 'email'] as $field => $type) {
                foreach ($extracted[$field] ?? [] as $value) {
                    $add($type, $field, $value, 'contact_link');
                }
            }
            $languages = [$extracted['html_lang'] ?? ''];
            foreach ($extracted['hreflang'] ?? [] as $row) {
                $languages[] = $row['hreflang'] ?? '';
            }
            foreach (array_unique($languages) as $lang) {
                if (is_string($lang) && preg_match('/^[a-z]{2,3}(?:-[a-zA-Z0-9]{2,8})*$/', $lang) && strtolower($lang) !== 'x-default') {
                    $add('language', 'languages', $lang, 'document_language');
                }
            }
            foreach ($extracted['social_links'] ?? [] as $row) {
                $add('social_link', 'social_links', $row['platform'].': '.$row['url'], 'profile_link', [
                    'platform' => $row['platform'], 'profile_url' => $row['url'],
                ]);
            }
            $meta = $extracted['meta_description'] ?? '';
            if (is_string($meta) && mb_strlen($meta) >= 40
                && (rtrim($sourceUrl, '/') === rtrim($crawl['seed_url'], '/')
                    || rtrim((string) ($page['requested_url'] ?? ''), '/') === rtrim($crawl['seed_url'], '/'))) {
                $add('business_summary', 'business_summary', $meta, 'homepage_meta_description');
            }
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

    public function identity(string $kind, string $field, string $value): string
    {
        // Channel IDs and URL paths can be case-sensitive; service names are case-insensitive claims.
        return $kind.'|'.$field.'|'.($field === 'social_links' ? trim($value) : $this->normalizeKey($value));
    }

    public function normalizeKey(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));
    }

    /** Merge provenance for the same claim; URL identity is deliberately separate from claim identity. */
    private function dedupeProposed(array $candidates): array
    {
        $out = [];
        foreach ($candidates as $candidate) {
            $key = $this->identity($candidate['candidate_kind'], $candidate['target_field'], $candidate['proposed_value']);
            if (! isset($out[$key])) {
                $out[$key] = $candidate;
            } else {
                $sources = array_merge($out[$key]['support_json']['sources'], $candidate['support_json']['sources']);
                $out[$key]['support_json']['sources'] = collect($sources)->unique(fn ($source) => $source['url'].'|'.$source['from'])->values()->all();
            }
        }

        return array_values($out);
    }
}
