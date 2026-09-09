<?php

namespace MoxDop\Website\Standards;

use App\Models\DataPool\RawIngestionObject;
use App\Models\DigitalAsset;
use App\Models\IntelligenceProjection\WebsitePageProfile;
use App\Services\SearchDemand\CompetitorPageContentExtractor;
use App\Support\CanonicalLinkParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use MoxDop\Website\Diagnosis\DocumentHeadParser;
use RuntimeException;

final class StoredPageReader
{
    public function __construct(
        private readonly DocumentHeadParser $heads,
        private readonly CanonicalLinkParser $canonicals,
        private readonly CompetitorPageContentExtractor $contents,
    ) {}

    /** @return array<string, mixed>|null */
    public function read(DigitalAsset $website, WebsitePageProfile $profile): ?array
    {
        if ((int) $profile->website_asset_id !== (int) $website->id) {
            throw new RuntimeException('Page does not belong to this Website.');
        }
        $snapshot = DB::table('website_html_snapshot')
            ->where('digital_asset_id', $website->id)
            ->where('url', $profile->preferred_url)
            ->whereNotNull('raw_ingestion_object_id')
            ->latest('observed_at')->latest('id')->first();
        if ($snapshot === null) {
            return null;
        }
        $object = RawIngestionObject::query()->whereKey($snapshot->raw_ingestion_object_id)
            ->where('dataset_id', 'website_html_snapshot')
            ->whereHas('resourceRun', fn ($query) => $query->where('digital_asset_id', $website->id))
            ->first();
        if ($object === null || $object->byte_size > 5 * 1024 * 1024) {
            return null;
        }
        $disk = Storage::disk($object->storage_disk);
        if (! $disk->exists($object->object_key) || $disk->size($object->object_key) > 5 * 1024 * 1024) {
            return null;
        }
        $stored = $disk->get($object->object_key);
        if (! hash_equals((string) $object->sha256, hash('sha256', $stored))) {
            throw new RuntimeException('Stored HTML checksum mismatch.');
        }
        $html = match ($object->compression) {
            null, '' => $stored,
            'gzip' => gzdecode($stored, 5 * 1024 * 1024),
            default => false,
        };
        if (! is_string($html) || trim($html) === '' || strlen($html) > 5 * 1024 * 1024) {
            return null;
        }
        $head = $this->heads->parse($html);
        $canonical = $this->canonicals->parse($html);
        $content = $this->contents->extract($profile->preferred_url, $html, [], []);

        return [
            'url' => $profile->preferred_url, 'page_profile_id' => $profile->id,
            'seo_inspection' => (new StoredSeoInspector)->inspect($profile->preferred_url, $html),
            'snapshot_id' => (int) $snapshot->id, 'raw_ingestion_object_id' => $object->id,
            'observed_at' => $snapshot->observed_at, 'html_hash' => $snapshot->html_hash,
            'content_fingerprint' => $content['content_fingerprint'],
            'title' => $content['title'], 'meta_description' => $content['meta_description'],
            'h1' => $content['h1'], 'headings' => array_slice($content['headings'], 0, 80),
            'internal_link_count' => count($content['internal_links']),
            'external_link_count' => count($content['external_links']),
            'head' => $head, 'canonical_hrefs' => $canonical['canonical_hrefs'],
            'head_complete' => $canonical['head_complete'] && ! $canonical['head_truncated'],
            'normalized_text_excerpt' => mb_substr($content['normalized_text'], 0, 16000),
            'text_truncated' => mb_strlen($content['normalized_text']) > 16000,
        ];
    }
}
