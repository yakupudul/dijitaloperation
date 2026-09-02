<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\DataPool\RawIngestionObject;
use App\Support\Reality\OperatorCanonicalAsset;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class WebsiteHtmlSnapshotController extends Controller
{
    public function show(int $assetId, int $rawObjectId): Response
    {
        $asset = OperatorCanonicalAsset::require((string) $assetId, ['website']);

        $belongsToAsset = DB::table('website_html_snapshot')
            ->where('digital_asset_id', $asset->getKey())
            ->where('raw_ingestion_object_id', $rawObjectId)
            ->exists();
        abort_unless($belongsToAsset, 404);

        $object = RawIngestionObject::query()
            ->whereKey($rawObjectId)
            ->where('dataset_id', 'website_html_snapshot')
            ->firstOrFail();
        $disk = Storage::disk((string) $object->storage_disk);
        abort_unless($disk->exists((string) $object->object_key), 404);

        $storedBytes = $disk->get((string) $object->object_key);
        if (! hash_equals((string) $object->sha256, hash('sha256', $storedBytes))) {
            throw new RuntimeException('Stored Website HTML checksum verification failed.');
        }

        $html = match ($object->compression) {
            null, '' => $storedBytes,
            'gzip' => gzdecode($storedBytes),
            default => false,
        };
        if (! is_string($html)) {
            throw new RuntimeException('Stored Website HTML could not be decoded.');
        }

        $filename = sprintf(
            '%s-%s.html.txt',
            preg_replace('/[^A-Za-z0-9.-]+/', '-', (string) ($asset->domain ?: 'website')) ?: 'website',
            substr((string) $object->sha256, 0, 12),
        );

        return response($html, 200, [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Content-Type' => 'text/plain; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
