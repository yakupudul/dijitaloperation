<?php

namespace App\Support\IntelligenceCore;

use App\Enums\IntelligenceCore\IntelligenceSourceClass;
use InvalidArgumentException;

final class IntelligenceSourceReference
{
    public function __construct(
        public readonly string $providerOrSource,
        public readonly IntelligenceSourceClass $sourceClass,
        public readonly string $sourceSemantic,
        public readonly ?string $datasetId = null,
        public readonly ?string $sourceRecordKey = null,
        public readonly ?int $sourceDigitalAssetId = null,
        public readonly ?int $externalResourceId = null,
        public readonly ?int $collectionRunId = null,
        public readonly ?int $datasetRunId = null,
        public readonly ?int $contractVersion = null,
    ) {
        if (trim($this->providerOrSource) === '') {
            throw new InvalidArgumentException('Intelligence source provider must not be empty.');
        }

        if (trim($this->sourceSemantic) === '') {
            throw new InvalidArgumentException('Intelligence source semantic must not be empty.');
        }
    }

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'provider_or_source' => $this->providerOrSource,
            'source_class' => $this->sourceClass->value,
            'source_semantic' => $this->sourceSemantic,
            'dataset_id' => $this->datasetId,
            'source_record_key' => $this->sourceRecordKey,
            'source_digital_asset_id' => $this->sourceDigitalAssetId,
            'external_resource_id' => $this->externalResourceId,
            'collection_run_id' => $this->collectionRunId,
            'dataset_run_id' => $this->datasetRunId,
            'contract_version' => $this->contractVersion,
        ];
    }

    public function fingerprint(string $dimension, string $observedIdentity): string
    {
        return hash('sha256', json_encode([
            'dimension' => $dimension,
            'provider_or_source' => $this->providerOrSource,
            'source_semantic' => $this->sourceSemantic,
            'dataset_id' => $this->datasetId,
            'source_record_key' => $this->sourceRecordKey,
            'source_digital_asset_id' => $this->sourceDigitalAssetId,
            'external_resource_id' => $this->externalResourceId,
            'observed_identity' => $observedIdentity,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
