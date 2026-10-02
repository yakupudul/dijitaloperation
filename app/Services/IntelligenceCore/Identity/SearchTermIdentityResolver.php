<?php

namespace App\Services\IntelligenceCore\Identity;

use App\Enums\IntelligenceCore\IdentityMatchMethod;
use App\Enums\IntelligenceCore\IdentityResolutionStatus;
use App\Enums\IntelligenceCore\SearchTermKind;
use App\Models\Brand;
use App\Models\IntelligenceCore\IntelligenceSearchTermAlias;
use App\Models\IntelligenceCore\IntelligenceSearchTermIdentity;
use App\Support\IntelligenceCore\IntelligenceSourceReference;
use App\Support\IntelligenceCore\IntelligenceTimeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class SearchTermIdentityResolver
{
    public function __construct(
        private readonly SearchTermNormalizer $normalizer,
    ) {}

    /** @param array<string, mixed> $metadata */
    public function resolve(
        Brand $brand,
        string $observedText,
        SearchTermKind $termKind,
        IntelligenceSourceReference $source,
        IntelligenceTimeContext $time,
        ?string $locale = null,
        array $metadata = [],
    ): IntelligenceSearchTermIdentity {
        if ($brand->getKey() === null) {
            throw new InvalidArgumentException('Search-term identity requires a persisted Brand.');
        }

        $normalized = $this->normalizer->normalize($observedText, $time->languageCode, $locale);
        if ($normalized->canonicalText === '') {
            throw new InvalidArgumentException('Search-term identity cannot be empty.');
        }

        return DB::transaction(function () use (
            $brand,
            $observedText,
            $termKind,
            $source,
            $time,
            $locale,
            $metadata,
            $normalized,
        ): IntelligenceSearchTermIdentity {
            $observedAt = $time->observedAt ?? now();
            $identityHash = hash('sha256', json_encode([
                'brand_id' => $brand->getKey(),
                'canonical_text' => $normalized->canonicalText,
                'language_code' => $time->languageCode,
                'locale' => $locale,
                'market_code' => $time->marketCode,
                'normalization_version' => $normalized->normalizationVersion,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            $identity = IntelligenceSearchTermIdentity::query()->firstOrCreate(
                ['identity_hash' => $identityHash],
                [
                    'uuid' => (string) Str::uuid(),
                    'brand_id' => $brand->getKey(),
                    'canonical_text' => $normalized->canonicalText,
                    'folded_text' => $normalized->foldedText,
                    'language_code' => $time->languageCode,
                    'locale' => $locale,
                    'market_code' => $time->marketCode,
                    'resolution_status' => IdentityResolutionStatus::Resolved,
                    'normalization_version' => $normalized->normalizationVersion,
                    'first_seen_at' => $observedAt,
                    'last_seen_at' => $observedAt,
                ],
            );
            if ($identity->last_seen_at->lessThan($observedAt)) {
                $identity->last_seen_at = $observedAt;
            }
            if ($identity->first_seen_at->greaterThan($observedAt)) {
                $identity->first_seen_at = $observedAt;
            }
            $identity->save();

            $fingerprint = $source->fingerprint(
                'search_term:brand:'.$brand->getKey().':'.$termKind->value,
                $normalized->canonicalText,
            );
            $alias = IntelligenceSearchTermAlias::query()->firstOrNew(['source_fingerprint' => $fingerprint]);
            if (! $alias->exists) {
                $alias->first_observed_at = $observedAt;
                $alias->last_observed_at = $observedAt;
            } else {
                if ($alias->first_observed_at->greaterThan($observedAt)) {
                    $alias->first_observed_at = $observedAt;
                }
                if ($alias->last_observed_at->lessThan($observedAt)) {
                    $alias->last_observed_at = $observedAt;
                }
            }
            $alias->fill([
                'search_term_identity_id' => $identity->getKey(),
                'source_digital_asset_id' => $source->sourceDigitalAssetId,
                'external_resource_id' => $source->externalResourceId,
                'collection_run_id' => $source->collectionRunId,
                'dataset_run_id' => $source->datasetRunId,
                'provider_or_source' => $source->providerOrSource,
                'source_class' => $source->sourceClass,
                'term_kind' => $termKind,
                'source_dataset_id' => $source->datasetId,
                'source_record_key' => $source->sourceRecordKey,
                'observed_text' => $observedText,
                'normalized_text' => $normalized->canonicalText,
                'folded_text' => $normalized->foldedText,
                'match_method' => IdentityMatchMethod::SyntacticExact,
                'resolution_status' => IdentityResolutionStatus::Resolved,
                'source_timezone' => $time->sourceTimezone,
                'market_code' => $time->marketCode,
                'language_code' => $time->languageCode,
                'metadata' => array_merge([
                    'locale' => $locale,
                    'normalization_version' => $normalized->normalizationVersion,
                    'source_contract_version' => $source->contractVersion,
                ], $metadata),
            ]);
            $alias->save();

            return $identity->refresh();
        });
    }
}
