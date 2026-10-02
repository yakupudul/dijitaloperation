<?php

namespace App\Services\Website\PublicDiscovery;

use App\Models\DataPool\RawIngestionObject;
use App\Models\DigitalAsset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class StoredHtmlReader
{
    public const int MAX_BYTES = 5 * 1024 * 1024;

    /** @return array{html: string, snapshot: object, object: RawIngestionObject}|null */
    public function read(DigitalAsset $website, string $url, ?int $snapshotId = null): ?array
    {
        $snapshot = DB::table('website_html_snapshot')
            ->where('digital_asset_id', $website->id)->where('url', $url)
            ->when($snapshotId !== null, fn ($query) => $query->where('id', $snapshotId))
            ->latest('observed_at')->latest('id')->first();
        if ($snapshot === null || $snapshot->raw_ingestion_object_id === null) {
            return null;
        }
        $object = RawIngestionObject::query()->whereKey($snapshot->raw_ingestion_object_id)
            ->where('dataset_id', 'website_html_snapshot')
            ->whereHas('resourceRun', fn ($query) => $query->where('digital_asset_id', $website->id))
            ->first();
        if ($object === null || $object->byte_size > self::MAX_BYTES) {
            return null;
        }
        $disk = Storage::disk($object->storage_disk);
        if (! $disk->exists($object->object_key) || $disk->size($object->object_key) > self::MAX_BYTES) {
            return null;
        }
        $bytes = $disk->get($object->object_key);
        if (! hash_equals((string) $object->sha256, hash('sha256', $bytes))) {
            throw new RuntimeException('Stored HTML checksum mismatch.');
        }
        $html = match ($object->compression) {
            null, '' => $bytes,
            'gzip' => @gzdecode($bytes, self::MAX_BYTES),
            default => false,
        };
        if (! is_string($html) || trim($html) === '' || strlen($html) > self::MAX_BYTES) {
            return null;
        }

        if (! hash_equals((string) $snapshot->html_hash, hash('sha256', $html))) {
            throw new RuntimeException('Snapshot and stored HTML do not match.');
        }

        return ['html' => $html, 'snapshot' => $snapshot, 'object' => $object];
    }
}
