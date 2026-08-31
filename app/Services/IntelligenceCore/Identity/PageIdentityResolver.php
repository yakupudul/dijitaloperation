<?php

namespace App\Services\IntelligenceCore\Identity;

use App\Enums\IntelligenceCore\IdentityMatchMethod;
use App\Enums\IntelligenceCore\IdentityResolutionStatus;
use App\Models\DigitalAsset;
use App\Models\IntelligenceCore\IntelligencePageAlias;
use App\Models\IntelligenceCore\IntelligencePageIdentity;
use App\Support\IntelligenceCore\IntelligenceSourceReference;
use App\Support\IntelligenceCore\IntelligenceTimeContext;
use App\Support\IntelligenceCore\UrlJoinKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class PageIdentityResolver
{
    public function __construct(
        private readonly UrlJoinKeyNormalizer $normalizer,
    ) {}

    /** @param array<string, mixed> $metadata */
    public function resolveObserved(
        DigitalAsset $websiteAsset,
        string $observedUrl,
        IntelligenceSourceReference $source,
        IntelligenceTimeContext $time,
        string $aliasKind = 'observed_url',
        array $metadata = [],
    ): IntelligencePageIdentity {
        $this->assertWebsite($websiteAsset);
        $joinKey = $this->normalizer->normalize($observedUrl, $websiteAsset->primary_url);
        if (! $joinKey instanceof UrlJoinKey) {
            throw new InvalidArgumentException("URL cannot participate in Page identity [{$observedUrl}].");
        }

        return DB::transaction(function () use (
            $websiteAsset,
            $observedUrl,
            $source,
            $time,
            $aliasKind,
            $metadata,
            $joinKey,
        ): IntelligencePageIdentity {
            $observedAt = $time->observedAt ?? now();
            $identityHash = hash('sha256', json_encode([
                'website_asset_id' => $websiteAsset->getKey(),
                'join_url' => $joinKey->url,
                'normalization_version' => $joinKey->normalizationVersion,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            $identity = IntelligencePageIdentity::query()->firstOrCreate(
                ['identity_hash' => $identityHash],
                [
                    'uuid' => (string) Str::uuid(),
                    'website_asset_id' => $websiteAsset->getKey(),
                    'preferred_url' => $joinKey->url,
                    'preferred_url_hash' => $joinKey->hash,
                    'scheme' => $joinKey->scheme,
                    'host' => $joinKey->host,
                    'path' => $joinKey->path,
                    'resolution_status' => IdentityResolutionStatus::Provisional,
                    'normalization_version' => $joinKey->normalizationVersion,
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

            $this->persistAlias(
                identity: $identity,
                observedUrl: $observedUrl,
                joinKey: $joinKey,
                source: $source,
                time: $time,
                aliasKind: $aliasKind,
                matchMethod: IdentityMatchMethod::SyntacticExact,
                resolutionStatus: IdentityResolutionStatus::Provisional,
                metadata: $metadata,
            );

            return $identity->refresh();
        });
    }

    /**
     * Requires redirect, canonical, CMS permalink, rule or operator evidence.
     *
     * @param array<string, mixed> $metadata
     */
    public function attachEvidenceAlias(
        IntelligencePageIdentity $identity,
        string $observedUrl,
        IntelligenceSourceReference $source,
        IntelligenceTimeContext $time,
        string $aliasKind,
        IdentityMatchMethod $matchMethod,
        array $metadata = [],
    ): IntelligencePageAlias {
        if ($matchMethod === IdentityMatchMethod::SyntacticExact) {
            throw new InvalidArgumentException('Evidence alias requires a non-syntactic match method.');
        }

        $joinKey = $this->normalizer->normalize($observedUrl, $identity->preferred_url);
        if (! $joinKey instanceof UrlJoinKey) {
            throw new InvalidArgumentException("URL cannot participate in Page identity [{$observedUrl}].");
        }

        return DB::transaction(function () use (
            $identity,
            $observedUrl,
            $source,
            $time,
            $aliasKind,
            $matchMethod,
            $metadata,
            $joinKey,
        ): IntelligencePageAlias {
            $observedAt = $time->observedAt ?? now();
            $identity->resolution_status = IdentityResolutionStatus::Resolved;
            if ($identity->last_seen_at->lessThan($observedAt)) {
                $identity->last_seen_at = $observedAt;
            }
            if ($identity->first_seen_at->greaterThan($observedAt)) {
                $identity->first_seen_at = $observedAt;
            }
            $identity->save();

            return $this->persistAlias(
                identity: $identity,
                observedUrl: $observedUrl,
                joinKey: $joinKey,
                source: $source,
                time: $time,
                aliasKind: $aliasKind,
                matchMethod: $matchMethod,
                resolutionStatus: IdentityResolutionStatus::Resolved,
                metadata: $metadata,
            );
        });
    }

    /** @param array<string, mixed> $metadata */
    private function persistAlias(
        IntelligencePageIdentity $identity,
        string $observedUrl,
        UrlJoinKey $joinKey,
        IntelligenceSourceReference $source,
        IntelligenceTimeContext $time,
        string $aliasKind,
        IdentityMatchMethod $matchMethod,
        IdentityResolutionStatus $resolutionStatus,
        array $metadata,
    ): IntelligencePageAlias {
        $observedAt = $time->observedAt ?? now();
        $fingerprint = $source->fingerprint(
            'page:website:'.$identity->website_asset_id,
            $joinKey->url.'|'.$aliasKind,
        );

        $alias = IntelligencePageAlias::query()->firstOrNew(['source_fingerprint' => $fingerprint]);
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
            'page_identity_id' => $identity->getKey(),
            'source_digital_asset_id' => $source->sourceDigitalAssetId,
            'external_resource_id' => $source->externalResourceId,
            'collection_run_id' => $source->collectionRunId,
            'dataset_run_id' => $source->datasetRunId,
            'provider_or_source' => $source->providerOrSource,
            'source_class' => $source->sourceClass,
            'source_semantic' => $source->sourceSemantic,
            'source_dataset_id' => $source->datasetId,
            'source_record_key' => $source->sourceRecordKey,
            'alias_kind' => $aliasKind,
            'observed_url' => $observedUrl,
            'observed_url_hash' => hash('sha256', $observedUrl),
            'join_url' => $joinKey->url,
            'join_url_hash' => $joinKey->hash,
            'match_method' => $matchMethod,
            'resolution_status' => $resolutionStatus,
            'source_timezone' => $time->sourceTimezone,
            'market_code' => $time->marketCode,
            'language_code' => $time->languageCode,
            'metadata' => array_merge([
                'normalization_version' => $joinKey->normalizationVersion,
                'source_contract_version' => $source->contractVersion,
            ], $metadata),
        ]);
        $alias->save();

        return $alias->refresh();
    }

    private function assertWebsite(DigitalAsset $asset): void
    {
        if ($asset->getKey() === null || strtolower((string) $asset->type) !== 'website') {
            throw new InvalidArgumentException('Page identity requires a persisted Website Digital Asset.');
        }
    }
}
