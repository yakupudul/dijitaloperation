<?php

namespace App\Services\DataPool;

use App\Models\DataPool\RawIngestionObject;
use App\Services\Collection\Contracts\RawPayloadWriter;
use App\Services\DataPool\Support\RawPayloadEnvelope;
use App\Services\DataPool\Support\RawPayloadReference;
use App\Services\DataPool\Support\SecretSanitizer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Private object-storage raw writer (local or S3-compatible via Laravel disk).
 */
final class FilesystemRawPayloadWriter implements RawPayloadWriter
{
    public function __construct(
        private readonly SecretSanitizer $sanitizer = new SecretSanitizer,
    ) {}

    public function write(RawPayloadEnvelope $envelope): RawPayloadReference
    {
        $this->sanitizer->assertSafe($envelope->providerSafeMetadata);
        $metadata = $this->sanitizer->sanitize($envelope->providerSafeMetadata);

        $diskName = (string) config('moxdop-data-pool.raw_disk', 'raw_ingestion');
        $objectKey = $this->objectKey($envelope);

        $existing = RawIngestionObject::query()
            ->where('dataset_run_id', $envelope->datasetRunId)
            ->where('batch_key', $envelope->batchKey)
            ->first();

        if ($existing !== null) {
            return new RawPayloadReference(
                rawIngestionObjectId: (int) $existing->id,
                uuid: (string) $existing->uuid,
                storageDisk: (string) $existing->storage_disk,
                objectKey: (string) $existing->object_key,
                sha256: (string) $existing->sha256,
                byteSize: (int) $existing->byte_size,
                compression: $existing->compression,
                reusedExisting: true,
            );
        }

        [$bytes, $compression, $sha256] = $this->encode($envelope);
        $disk = Storage::disk($diskName);

        if ($this->isContentAddressedWebsiteHtml($envelope)) {
            $existingArtifact = RawIngestionObject::query()
                ->where('storage_disk', $diskName)
                ->where('object_key', $objectKey)
                ->first();

            if ($existingArtifact !== null) {
                if (! hash_equals((string) $existingArtifact->sha256, $sha256)) {
                    throw new RuntimeException("Content-addressed HTML checksum mismatch [{$objectKey}]");
                }

                return new RawPayloadReference(
                    rawIngestionObjectId: (int) $existingArtifact->id,
                    uuid: (string) $existingArtifact->uuid,
                    storageDisk: (string) $existingArtifact->storage_disk,
                    objectKey: (string) $existingArtifact->object_key,
                    sha256: (string) $existingArtifact->sha256,
                    byteSize: (int) $existingArtifact->byte_size,
                    compression: $existingArtifact->compression,
                    reusedExisting: true,
                );
            }
        }

        if (! $disk->exists($objectKey)) {
            $ok = $disk->put($objectKey, $bytes);
            if ($ok === false) {
                throw new RuntimeException("Failed to write raw object [{$objectKey}] on disk [{$diskName}]");
            }
        } else {
            $existingBytes = $disk->get($objectKey);
            if (hash('sha256', $existingBytes) !== $sha256) {
                throw new RuntimeException("Raw object key collision with checksum mismatch [{$objectKey}]");
            }
        }

        $attributes = [
            'uuid' => (string) Str::uuid(),
            'collection_run_id' => $envelope->collectionRunId,
            'resource_run_id' => $envelope->resourceRunId,
            'dataset_run_id' => $envelope->datasetRunId,
            'dataset_id' => $envelope->logicalDatasetId,
            'request_family_id' => $envelope->requestFamilyId,
            'batch_key' => $envelope->batchKey,
            'provider_or_source' => $envelope->providerOrSource,
            'storage_disk' => $diskName,
            'object_key' => $objectKey,
            'content_type' => $envelope->contentType,
            'compression' => $compression,
            'byte_size' => strlen($bytes),
            'sha256' => $sha256,
            'record_count' => $envelope->recordCount,
            'provider_request_fingerprint' => $envelope->providerRequestFingerprint,
            'captured_at' => $envelope->capturedAt ?? now(),
            'retention_class' => $envelope->retentionClass,
            'metadata' => $metadata,
        ];

        $reusedExisting = false;
        try {
            $row = RawIngestionObject::query()->create($attributes);
        } catch (QueryException $exception) {
            if (! $this->isContentAddressedWebsiteHtml($envelope)) {
                throw $exception;
            }

            $row = RawIngestionObject::query()
                ->where('storage_disk', $diskName)
                ->where('object_key', $objectKey)
                ->first();
            if ($row === null || ! hash_equals((string) $row->sha256, $sha256)) {
                throw $exception;
            }
            $reusedExisting = true;
        }

        return new RawPayloadReference(
            rawIngestionObjectId: (int) $row->id,
            uuid: (string) $row->uuid,
            storageDisk: $diskName,
            objectKey: $objectKey,
            sha256: $sha256,
            byteSize: (int) $row->byte_size,
            compression: $compression,
            reusedExisting: $reusedExisting,
        );
    }

    private function objectKey(RawPayloadEnvelope $envelope): string
    {
        if ($this->isContentAddressedWebsiteHtml($envelope)) {
            $assetId = max(0, (int) ($envelope->providerSafeMetadata['digital_asset_id'] ?? 0));
            $htmlHash = strtolower((string) $envelope->providerSafeMetadata['html_hash']);

            return sprintf(
                'raw/website_direct/html/%s/%s/%s.html%s',
                $assetId,
                substr($htmlHash, 0, 2),
                $htmlHash,
                config('moxdop-data-pool.raw_compression') === 'gzip' ? '.gz' : '',
            );
        }

        $provider = Str::slug(strtolower($envelope->providerOrSource), '_');
        $run = $envelope->collectionRunId ?? 0;
        $resource = $envelope->resourceRunId ?? 0;
        $dataset = $envelope->logicalDatasetId;
        $batch = preg_replace('/[^A-Za-z0-9._-]+/', '_', $envelope->batchKey) ?: 'batch';

        return sprintf(
            'raw/%s/%s/%s/%s/%s.json%s',
            $provider,
            $run,
            $resource,
            $dataset,
            $batch,
            $envelope->alreadyCompressed ? '' : (
                config('moxdop-data-pool.raw_compression') === 'gzip' ? '.gz' : ''
            ),
        );
    }

    /**
     * @return array{0: string, 1: ?string, 2: string}
     */
    private function encode(RawPayloadEnvelope $envelope): array
    {
        $payload = $envelope->payload;
        $compression = null;

        if ($envelope->alreadyCompressed) {
            $bytes = $payload;
            $compression = $envelope->encoding ?? 'precompressed';
        } elseif (config('moxdop-data-pool.raw_compression') === 'gzip') {
            $compressed = gzencode($payload, 6);
            if ($compressed === false) {
                throw new RuntimeException('gzip compression failed for raw payload');
            }
            $bytes = $compressed;
            $compression = 'gzip';
        } else {
            $bytes = $payload;
        }

        return [$bytes, $compression, hash('sha256', $bytes)];
    }

    private function isContentAddressedWebsiteHtml(RawPayloadEnvelope $envelope): bool
    {
        $htmlHash = $envelope->providerSafeMetadata['html_hash'] ?? null;

        return $envelope->logicalDatasetId === 'website_html_snapshot'
            && $envelope->retentionClass === 'website_html_version'
            && is_string($htmlHash)
            && preg_match('/^[a-f0-9]{64}$/i', $htmlHash) === 1;
    }
}
