<?php

namespace App\Services\IntelligenceCore\Identity;

use App\Enums\IntelligenceCore\IdentityMatchMethod;
use App\Enums\IntelligenceCore\IdentityResolutionStatus;
use App\Models\Brand;
use App\Models\IntelligenceCore\IntelligenceEntityAlias;
use App\Models\IntelligenceCore\IntelligenceEntityIdentity;
use App\Support\BrandIntelligence\IdentityLabelNormalizer;
use App\Support\IntelligenceCore\IntelligenceSourceReference;
use App\Support\IntelligenceCore\IntelligenceTimeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class EntityIdentityResolver
{
    public function __construct(
        private readonly IdentityLabelNormalizer $normalizer,
    ) {}

    /** @param array<string, mixed> $metadata */
    public function resolve(
        Brand $brand,
        string $entityType,
        string $observedName,
        IntelligenceSourceReference $source,
        IntelligenceTimeContext $time,
        string $aliasKind = 'observed_name',
        ?string $externalEntityId = null,
        ?string $countryCode = null,
        IdentityMatchMethod $matchMethod = IdentityMatchMethod::SyntacticExact,
        array $metadata = [],
    ): IntelligenceEntityIdentity {
        if ($brand->getKey() === null) {
            throw new InvalidArgumentException('Entity identity requires a persisted Brand.');
        }

        $type = strtolower(trim($entityType));
        $name = trim($observedName);
        $normalizedName = $this->normalizer->normalize($name);
        if ($type === '' || $normalizedName === '') {
            throw new InvalidArgumentException('Entity type and name must not be empty.');
        }

        $status = $matchMethod === IdentityMatchMethod::SyntacticExact
            ? IdentityResolutionStatus::Provisional
            : IdentityResolutionStatus::Resolved;

        return DB::transaction(function () use (
            $brand,
            $type,
            $name,
            $normalizedName,
            $source,
            $time,
            $aliasKind,
            $externalEntityId,
            $countryCode,
            $matchMethod,
            $metadata,
            $status,
        ): IntelligenceEntityIdentity {
            $observedAt = $time->observedAt ?? now();
            $identityHash = hash('sha256', json_encode([
                'brand_id' => $brand->getKey(),
                'entity_type' => $type,
                'normalized_name' => $normalizedName,
                'country_code' => $countryCode,
                'language_code' => $time->languageCode,
                'normalization_version' => $this->normalizer->version(),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            $identity = IntelligenceEntityIdentity::query()->firstOrCreate(
                ['identity_hash' => $identityHash],
                [
                    'uuid' => (string) Str::uuid(),
                    'brand_id' => $brand->getKey(),
                    'entity_type' => $type,
                    'canonical_name' => $name,
                    'normalized_name' => $normalizedName,
                    'country_code' => $countryCode,
                    'language_code' => $time->languageCode,
                    'resolution_status' => $status,
                    'normalization_version' => $this->normalizer->version(),
                    'first_seen_at' => $observedAt,
                    'last_seen_at' => $observedAt,
                ],
            );

            if ($status === IdentityResolutionStatus::Resolved
                && $identity->resolution_status === IdentityResolutionStatus::Provisional) {
                $identity->resolution_status = IdentityResolutionStatus::Resolved;
            }
            if ($identity->last_seen_at->lessThan($observedAt)) {
                $identity->last_seen_at = $observedAt;
            }
            if ($identity->first_seen_at->greaterThan($observedAt)) {
                $identity->first_seen_at = $observedAt;
            }
            $identity->save();

            $fingerprint = $source->fingerprint(
                'entity:brand:'.$brand->getKey().':'.$type,
                ($externalEntityId ?: $normalizedName).'|'.$aliasKind,
            );
            $alias = IntelligenceEntityAlias::query()->firstOrNew(['source_fingerprint' => $fingerprint]);
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
                'entity_identity_id' => $identity->getKey(),
                'source_digital_asset_id' => $source->sourceDigitalAssetId,
                'external_resource_id' => $source->externalResourceId,
                'collection_run_id' => $source->collectionRunId,
                'dataset_run_id' => $source->datasetRunId,
                'provider_or_source' => $source->providerOrSource,
                'source_class' => $source->sourceClass,
                'source_semantic' => $source->sourceSemantic,
                'source_dataset_id' => $source->datasetId,
                'source_record_key' => $source->sourceRecordKey,
                'external_entity_id' => $externalEntityId,
                'alias_kind' => $aliasKind,
                'observed_name' => $name,
                'normalized_name' => $normalizedName,
                'match_method' => $matchMethod,
                'resolution_status' => $status,
                'source_timezone' => $time->sourceTimezone,
                'market_code' => $time->marketCode,
                'language_code' => $time->languageCode,
                'metadata' => array_merge([
                    'normalization_version' => $this->normalizer->version(),
                    'source_contract_version' => $source->contractVersion,
                ], $metadata),
            ]);
            $alias->save();

            return $identity->refresh();
        });
    }
}
