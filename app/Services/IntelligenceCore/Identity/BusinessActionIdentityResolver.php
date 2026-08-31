<?php

namespace App\Services\IntelligenceCore\Identity;

use App\Enums\IntelligenceCore\BusinessActionSignalClass;
use App\Enums\IntelligenceCore\IdentityMatchMethod;
use App\Enums\IntelligenceCore\IdentityResolutionStatus;
use App\Models\Brand;
use App\Models\IntelligenceCore\IntelligenceBusinessActionAlias;
use App\Models\IntelligenceCore\IntelligenceBusinessActionIdentity;
use App\Support\BrandIntelligence\IdentityLabelNormalizer;
use App\Support\IntelligenceCore\IntelligenceSourceReference;
use App\Support\IntelligenceCore\IntelligenceTimeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class BusinessActionIdentityResolver
{
    public function __construct(
        private readonly IdentityLabelNormalizer $normalizer,
    ) {}

    public function define(
        Brand $brand,
        string $actionKey,
        string $actionKind,
        string $displayName,
        ?string $semanticDefinition = null,
        int $definitionVersion = 1,
    ): IntelligenceBusinessActionIdentity {
        if ($brand->getKey() === null) {
            throw new InvalidArgumentException('Business action requires a persisted Brand.');
        }

        $key = Str::of($actionKey)->trim()->lower()->replaceMatches('/[^a-z0-9._-]+/', '_')->trim('_')->value();
        $kind = strtolower(trim($actionKind));
        $name = trim($displayName);
        if ($key === '' || $kind === '' || $name === '' || $definitionVersion < 1) {
            throw new InvalidArgumentException('Business action definition is incomplete.');
        }

        return DB::transaction(function () use (
            $brand,
            $key,
            $kind,
            $name,
            $semanticDefinition,
            $definitionVersion,
        ): IntelligenceBusinessActionIdentity {
            $action = IntelligenceBusinessActionIdentity::query()->firstOrNew([
                'brand_id' => $brand->getKey(),
                'action_key' => $key,
            ]);
            if (! $action->exists) {
                $action->uuid = (string) Str::uuid();
                $action->identity_hash = hash('sha256', json_encode([
                    'brand_id' => $brand->getKey(),
                    'action_key' => $key,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            }
            $action->fill([
                'action_kind' => $kind,
                'display_name' => $name,
                'semantic_definition' => $semanticDefinition,
                'status' => 'active',
                'definition_version' => $definitionVersion,
            ]);
            $action->save();

            return $action->refresh();
        });
    }

    /**
     * Maps a provider signal to an explicitly defined Brand action. It never
     * upgrades platform attribution into a verified business outcome.
     *
     * @param array<string, mixed> $metadata
     */
    public function mapSignal(
        IntelligenceBusinessActionIdentity $action,
        string $observedName,
        BusinessActionSignalClass $signalClass,
        IntelligenceSourceReference $source,
        IntelligenceTimeContext $time,
        ?string $providerActionId = null,
        IdentityMatchMethod $matchMethod = IdentityMatchMethod::OperatorConfirmed,
        array $metadata = [],
    ): IntelligenceBusinessActionAlias {
        $name = trim($observedName);
        $normalizedName = $this->normalizer->normalize($name);
        if ($action->getKey() === null || $normalizedName === '') {
            throw new InvalidArgumentException('Business action mapping is incomplete.');
        }

        return DB::transaction(function () use (
            $action,
            $name,
            $normalizedName,
            $signalClass,
            $source,
            $time,
            $providerActionId,
            $matchMethod,
            $metadata,
        ): IntelligenceBusinessActionAlias {
            $observedAt = $time->observedAt ?? now();
            $fingerprint = $source->fingerprint(
                'business_action:'.$action->getKey().':'.$signalClass->value,
                $providerActionId ?: $normalizedName,
            );
            $alias = IntelligenceBusinessActionAlias::query()->firstOrNew([
                'source_fingerprint' => $fingerprint,
            ]);
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
                'business_action_identity_id' => $action->getKey(),
                'source_digital_asset_id' => $source->sourceDigitalAssetId,
                'external_resource_id' => $source->externalResourceId,
                'collection_run_id' => $source->collectionRunId,
                'dataset_run_id' => $source->datasetRunId,
                'provider_or_source' => $source->providerOrSource,
                'source_class' => $source->sourceClass,
                'source_semantic' => $source->sourceSemantic,
                'signal_class' => $signalClass,
                'source_dataset_id' => $source->datasetId,
                'source_record_key' => $source->sourceRecordKey,
                'provider_action_id' => $providerActionId,
                'observed_name' => $name,
                'normalized_name' => $normalizedName,
                'match_method' => $matchMethod,
                'resolution_status' => IdentityResolutionStatus::Resolved,
                'source_timezone' => $time->sourceTimezone,
                'market_code' => $time->marketCode,
                'language_code' => $time->languageCode,
                'metadata' => array_merge([
                    'source_contract_version' => $source->contractVersion,
                    'verified_business_outcome' => $signalClass === BusinessActionSignalClass::OperatorVerifiedOutcome,
                ], $metadata),
            ]);
            $alias->save();

            return $alias->refresh();
        });
    }
}
