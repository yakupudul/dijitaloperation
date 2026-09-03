<?php

namespace App\Services\SearchDemand;

use App\Models\SearchQueryLibraryImport;
use App\Models\SearchQueryLibraryItem;
use App\Models\SearchQueryLibrarySourceRecord;
use App\Models\ServiceCatalogItem;
use App\Models\User;
use App\Services\IntelligenceCore\Identity\SearchTermNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SearchQueryLibraryService
{
    public function __construct(private readonly SearchTermNormalizer $normalizer) {}

    /**
     * @param array<string, mixed> $attributes
     * @return array{item: SearchQueryLibraryItem, created: bool, source_record: SearchQueryLibrarySourceRecord}
     */
    public function store(string $query, string $sourceType, array $attributes = [], ?User $actor = null): array
    {
        $query = trim($query);
        $language = $this->nullable($attributes['language_code'] ?? null);
        $locale = $this->nullable($attributes['locale'] ?? null);
        $market = $this->nullable($attributes['market_code'] ?? null);
        $normalized = $this->normalizer->normalize($query, $language, $locale);

        if ($normalized->canonicalText === '') {
            throw ValidationException::withMessages(['query_text' => 'Sorgu metni gereklidir.']);
        }
        if (! in_array($sourceType, self::sourceTypes(), true)) {
            throw ValidationException::withMessages(['source_type' => 'Geçersiz sorgu kaynağı.']);
        }

        return DB::transaction(function () use ($query, $sourceType, $attributes, $actor, $language, $locale, $market, $normalized): array {
            $now = now();
            $identityHash = hash('sha256', json_encode([
                'canonical_text' => $normalized->canonicalText,
                'language_code' => $language,
                'locale' => $locale,
                'market_code' => $market,
                'normalization_version' => $normalized->normalizationVersion,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            $item = SearchQueryLibraryItem::query()->firstOrNew(['identity_hash' => $identityHash]);
            $created = ! $item->exists;
            if ($created) {
                $item->fill([
                    'uuid' => (string) Str::uuid(),
                    'canonical_text' => $normalized->canonicalText,
                    'folded_text' => $normalized->foldedText,
                    'language_code' => $language,
                    'locale' => $locale,
                    'market_code' => $market,
                    'normalization_version' => $normalized->normalizationVersion,
                    'first_seen_at' => $now,
                    'created_by' => $actor?->id,
                ]);
            }
            $item->fill([
                'sector' => $this->nullable($attributes['sector'] ?? null) ?? $item->sector,
                'demand_family' => $this->nullable($attributes['demand_family'] ?? null) ?? $item->demand_family,
                'search_intent' => $this->nullable($attributes['search_intent'] ?? null) ?? $item->search_intent,
                'user_problem' => $this->nullable($attributes['user_problem'] ?? null) ?? $item->user_problem,
                'decision_stage' => $this->nullable($attributes['decision_stage'] ?? null) ?? $item->decision_stage,
                'serp_intent_group' => $this->nullable($attributes['serp_intent_group'] ?? null) ?? $item->serp_intent_group,
                'content_target_cluster' => $this->nullable($attributes['content_target_cluster'] ?? null) ?? $item->content_target_cluster,
                'location_scope' => array_key_exists('location_scope', $attributes)
                    ? $this->locationScope($attributes['location_scope'])
                    : ($item->location_scope ?: 'none'),
                'location_value' => array_key_exists('location_value', $attributes)
                    ? $this->nullable($attributes['location_value'])
                    : $item->location_value,
                'is_branded' => (bool) ($attributes['is_branded'] ?? $item->is_branded ?? false),
                'status' => array_key_exists('status', $attributes)
                    ? $this->status($attributes['status'])
                    : ($item->status ?: 'active'),
                'notes' => $this->nullable($attributes['notes'] ?? null) ?? $item->notes,
                'classification_source' => $this->nullable($attributes['classification_source'] ?? null) ?? $item->classification_source,
                'classification_confidence' => is_numeric($attributes['classification_confidence'] ?? null)
                    ? max(0, min(100, (int) $attributes['classification_confidence']))
                    : $item->classification_confidence,
                'classification_version' => $this->nullable($attributes['classification_version'] ?? null) ?? $item->classification_version,
                'classified_at' => $attributes['classified_at'] ?? $item->classified_at,
                'classified_by' => $attributes['classified_by'] ?? $item->classified_by,
                'last_seen_at' => $now,
                'updated_by' => $actor?->id,
            ]);
            $item->save();

            $service = $this->service($attributes['service_catalog_item_id'] ?? null);
            if ($service instanceof ServiceCatalogItem) {
                $hasPrimary = $item->services()->wherePivot('is_primary', true)->exists();
                $item->services()->syncWithoutDetaching([
                    $service->id => [
                        'is_primary' => ! $hasPrimary,
                        'provenance' => $sourceType,
                    ],
                ]);
            }

            $import = $attributes['import'] ?? null;
            $importId = $import instanceof SearchQueryLibraryImport ? (int) $import->id : null;
            $rowNumber = is_numeric($attributes['row_number'] ?? null) ? (int) $attributes['row_number'] : null;
            $sourceReference = $this->nullable($attributes['source_reference'] ?? null);
            $sourceFingerprint = hash('sha256', json_encode([
                'source_type' => $sourceType,
                'item_id' => $item->id,
                'import_id' => $importId,
                'row_number' => $rowNumber,
                'source_reference' => $sourceReference,
                'observed_text' => $query,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            $sourceRecord = SearchQueryLibrarySourceRecord::query()->updateOrCreate(
                ['source_fingerprint' => $sourceFingerprint],
                [
                    'search_query_library_item_id' => $item->id,
                    'search_query_library_import_id' => $importId,
                    'service_catalog_item_id' => $service?->id,
                    'source_type' => $sourceType,
                    'source_reference' => $sourceReference,
                    'row_number' => $rowNumber,
                    'observed_text' => $query,
                    'country_code' => $this->nullable($attributes['country_code'] ?? null),
                    'city_name' => $this->nullable($attributes['city_name'] ?? null),
                    'district_name' => $this->nullable($attributes['district_name'] ?? null),
                    'period_start' => $this->nullable($attributes['period_start'] ?? null),
                    'period_end' => $this->nullable($attributes['period_end'] ?? null),
                    'impressions' => $this->number($attributes['impressions'] ?? null),
                    'clicks' => $this->number($attributes['clicks'] ?? null),
                    'conversions' => $this->number($attributes['conversions'] ?? null),
                    'cost' => $this->number($attributes['cost'] ?? null),
                    'search_volume' => $this->number($attributes['search_volume'] ?? null),
                    'cpc' => $this->number($attributes['cpc'] ?? null),
                    'competition' => $this->number($attributes['competition'] ?? null),
                    'raw_payload' => is_array($attributes['raw_payload'] ?? null) ? $attributes['raw_payload'] : null,
                    'observed_at' => $attributes['observed_at'] ?? $now,
                ],
            );

            return ['item' => $item->refresh(), 'created' => $created, 'source_record' => $sourceRecord];
        });
    }

    /** @return list<string> */
    public static function sourceTypes(): array
    {
        return ['manual', 'paste', 'csv', 'xlsx', 'google_ads', 'search_console', 'dataforseo', 'ai_candidate'];
    }

    /** @return array<string, string> */
    public static function sourceOptions(): array
    {
        return [
            'manual' => 'Manuel',
            'paste' => 'Toplu metin',
            'csv' => 'CSV / TSV',
            'xlsx' => 'Excel',
            'google_ads' => 'Google Ads arama terimleri',
            'search_console' => 'Search Console sorguları',
            'dataforseo' => 'DataForSEO',
            'ai_candidate' => 'AI adayı',
        ];
    }

    private function service(mixed $id): ?ServiceCatalogItem
    {
        return is_numeric($id) ? ServiceCatalogItem::query()->where('status', 'active')->find((int) $id) : null;
    }

    private function status(mixed $status): string
    {
        $status = trim((string) $status);

        return in_array($status, ['active', 'candidate', 'excluded', 'archived'], true) ? $status : 'active';
    }

    private function locationScope(mixed $scope): string
    {
        $scope = trim((string) $scope);

        return in_array($scope, ['none', 'country', 'city', 'district', 'pattern'], true) ? $scope : 'none';
    }

    private function nullable(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function number(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value)) {
            $value = str_replace([' ', "\u{00A0}", '%'], '', trim($value));
            if (str_contains($value, ',') && str_contains($value, '.') && strrpos($value, ',') > strrpos($value, '.')) {
                $value = str_replace('.', '', $value);
                $value = str_replace(',', '.', $value);
            } elseif (substr_count($value, ',') === 1 && substr_count($value, '.') === 0) {
                $value = str_replace(',', '.', $value);
            } else {
                $value = str_replace(',', '', $value);
            }
        }

        return is_numeric($value) ? (float) $value : null;
    }
}
