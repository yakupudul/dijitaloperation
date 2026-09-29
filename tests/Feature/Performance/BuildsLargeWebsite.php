<?php

namespace Tests\Feature\Performance;

use App\Enums\CustomerStatus;
use App\Models\Brand;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\IntelligenceProjection\WebsiteIntelligenceProjectionRun;
use App\Models\ServiceCategory;
use App\Services\BrandIntelligence\BrandOfferingService;
use App\Services\SeoTasks\SeoText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A production-sized website fixture (panoramaankara.com: ~5 000 page profiles, ~2 300 sitemap URLs, thousands of
 * Search Console query × page rows, a crawled link graph, stored HTML) written with bulk inserts so performance
 * tests can assert bounded work (queries, chunks) on realistic volumes.
 */
trait BuildsLargeWebsite
{
    private const array WORDS = ['implant', 'diş', 'zirkonyum', 'kaplama', 'ortodonti', 'beyazlatma', 'kanal', 'tedavisi', 'ankara', 'çankaya',
        'fiyat', 'nasıl', 'yapılır', 'kliniği', 'estetik', 'gülüş', 'protez', 'dolgu', 'çekimi', 'lamina', 'porselen', 'şeffaf', 'plak',
        'pedodonti', 'çocuk', 'hekimi', 'acil', 'yakın', 'en', 'iyi'];

    private function phrase(int $words, int $seed): string
    {
        $out = [];
        for ($i = 0; $i < $words; $i++) {
            $out[] = self::WORDS[($seed * 7 + $i * 13 + intdiv($seed, 5)) % count(self::WORDS)];
        }

        return implode(' ', $out).' '.($seed % 997);
    }

    /**
     * @return array{site: DigitalAsset, brand: Brand, urls: list<string>}
     */
    private function buildLargeWebsite(int $pages = 5000, int $gscRows = 5000, int $sitemapUrls = 2333, int $htmlPages = 60, int $offerings = 20): array
    {
        $host = 'panorama.test';
        $customer = Customer::factory()->create(['status' => CustomerStatus::Active]);
        $brand = Brand::factory()->create(['customer_id' => $customer->id, 'name' => 'Panorama Ankara']);
        $category = ServiceCategory::query()->firstOrCreate(['code' => 'dental'], ['name' => 'Diş', 'normalized_key' => 'dental']);
        $brand->update(['sector_id' => $category->id]);
        $site = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website', 'status' => 'active',
            'primary_url' => 'https://'.$host.'/', 'domain' => $host, 'seo_market_language_code' => 'tr']);
        $projection = WebsiteIntelligenceProjectionRun::query()->create([
            'uuid' => (string) Str::uuid(), 'website_asset_id' => $site->id, 'trigger' => 'test', 'status' => 'completed',
            'schema_version' => 1, 'intelligence_registry_version' => 1, 'period_start' => now()->subDays(90), 'period_end' => now()->subDay(),
        ]);
        $now = now()->toDateTimeString();

        $urls = [];
        for ($i = 0; $i < $pages; $i++) {
            $urls[] = 'https://'.$host.($i === 0 ? '/' : '/'.SeoText::slugify($this->phrase(3, $i)).'-'.$i.'/');
        }
        foreach (array_chunk($urls, 500, true) as $chunk) {
            $identities = [];
            foreach ($chunk as $i => $url) {
                $identities[] = ['uuid' => (string) Str::uuid(), 'website_asset_id' => $site->id, 'identity_hash' => hash('sha256', $site->id.':'.$url),
                    'preferred_url' => $url, 'preferred_url_hash' => hash('sha256', $url), 'scheme' => 'https', 'host' => $host,
                    'path' => (string) parse_url($url, PHP_URL_PATH), 'resolution_status' => 'resolved', 'normalization_version' => 'v1',
                    'first_seen_at' => $now, 'last_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now];
            }
            DB::table('intelligence_page_identities')->insert($identities);
            $ids = DB::table('intelligence_page_identities')->where('website_asset_id', $site->id)
                ->whereIn('preferred_url_hash', array_map(fn (string $u): string => hash('sha256', $u), $chunk))->pluck('id', 'preferred_url');
            $profiles = [];
            foreach ($chunk as $i => $url) {
                // Every ninth URL is a non-document (media / feed) the way WordPress inventories contain them.
                $document = $i % 9 !== 8;
                $profiles[] = ['website_asset_id' => $site->id, 'page_identity_id' => $ids[$url], 'projection_run_id' => $projection->id,
                    'preferred_url' => $document ? $url : $url.'image-'.$i.'.jpg', 'profile_version' => 1, 'projected_at' => $now, 'last_observed_at' => $now,
                    'source_states' => json_encode(['website' => [
                        'url' => $document ? $url : $url.'image-'.$i.'.jpg',
                        'http' => ['status_code' => $i % 50 === 49 ? 404 : 200, 'content_type' => $document ? 'text/html' : 'image/jpeg'],
                        'document_head' => ['title' => ucfirst($this->phrase(4, $i)), 'meta_description' => $i % 7 ? 'Açıklama '.$i : null, 'robots' => 'index,follow', 'canonical_hrefs' => []],
                        'headings' => ['h1' => ucfirst($this->phrase(3, $i + 1)), 'h1_present' => true],
                        'content' => ['word_count' => 200 + $i % 900],
                    ], 'wordpress' => ['object' => ['id' => (string) (1000 + $i), 'type' => 'post', 'status' => 'publish', 'title' => 'WP '.$i, 'permalink' => $url]]]),
                    'created_at' => $now, 'updated_at' => $now];
            }
            DB::table('website_page_profiles')->insert($profiles);
        }

        // Search Console: query × page rows spread over the site (two days each), page totals for decay / pruning.
        $gsc = [];
        for ($i = 0; $i < $gscRows; $i++) {
            $url = $urls[($i * 37) % $pages];
            foreach ([10, 40] as $day) {
                $gsc[] = ['digital_asset_id' => $site->id, 'external_resource_id' => null, 'site_url' => 'https://'.$host.'/',
                    'reporting_date' => now()->subDays($day)->toDateString(), 'query' => $this->phrase(3, $i + 11), 'page' => $url,
                    'clicks' => $i % 5, 'impressions' => 20 + $i % 400, 'contract_version' => 1, 'first_collected_at' => $now, 'last_collected_at' => $now,
                    'record_fingerprint' => hash('sha256', 'q'.$i.'-'.$day), 'metadata' => json_encode(['provider_average_position' => 3 + $i % 40]),
                    'created_at' => $now, 'updated_at' => $now];
            }
            if (count($gsc) >= 1000) {
                DB::table('gsc_query_page_daily')->insert($gsc);
                $gsc = [];
            }
        }
        if ($gsc !== []) {
            DB::table('gsc_query_page_daily')->insert($gsc);
        }
        $pageRows = [];
        foreach (array_slice($urls, 0, intdiv($pages, 2)) as $i => $url) {
            $pageRows[] = ['digital_asset_id' => $site->id, 'site_url' => 'https://'.$host.'/', 'reporting_date' => now()->subDays(20 + $i % 100)->toDateString(),
                'page' => $url, 'clicks' => $i % 9, 'impressions' => 30 + $i % 300, 'contract_version' => 1, 'first_collected_at' => $now,
                'last_collected_at' => $now, 'record_fingerprint' => hash('sha256', 'p'.$i), 'created_at' => $now, 'updated_at' => $now];
            if (count($pageRows) >= 1000) {
                DB::table('gsc_page_daily')->insert($pageRows);
                $pageRows = [];
            }
        }
        if ($pageRows !== []) {
            DB::table('gsc_page_daily')->insert($pageRows);
        }

        // Crawled link graph: every page links to the home page and three others.
        $edges = [];
        foreach ($urls as $i => $from) {
            foreach ([0, ($i + 17) % $pages, ($i + 34) % $pages, ($i + 51) % $pages] as $target) {
                $to = $urls[$target];
                $edges[] = ['digital_asset_id' => $site->id, 'edge_key' => hash('sha256', $from.'>'.$to), 'source_url' => $from, 'target_url' => $to,
                    'normalized_target_url' => $to, 'is_internal' => true, 'observed_at' => $now, 'contract_version' => 1, 'first_collected_at' => $now,
                    'last_collected_at' => $now, 'record_fingerprint' => hash('sha256', 'e'.$from.'>'.$to), 'created_at' => $now, 'updated_at' => $now];
            }
            if (count($edges) >= 1000) {
                DB::table('website_link_edge')->insertOrIgnore($edges);
                $edges = [];
            }
        }
        if ($edges !== []) {
            DB::table('website_link_edge')->insertOrIgnore($edges);
        }

        $sitemap = [];
        foreach (array_slice($urls, 0, $sitemapUrls) as $url) {
            $sitemap[$url] = ['m' => null, 's' => 'https://'.$host.'/sitemap.xml'];
        }
        DB::table('website_sitemap_watch')->insert(['digital_asset_id' => $site->id, 'pages' => json_encode($sitemap), 'page_count' => count($sitemap),
            'checked_at' => $now, 'created_at' => $now, 'updated_at' => $now]);

        // Stored HTML of the first pages (home, services).
        if ($htmlPages > 0) {
            Storage::fake('large-site-test');
            $resource = CollectionResourceRun::factory()->create(['digital_asset_id' => $site->id, 'provider_or_source' => 'website']);
            foreach (array_slice($urls, 0, $htmlPages) as $i => $url) {
                $html = '<html lang="tr"><head><title>'.e($this->phrase(4, $i)).'</title></head><body><h1>'.e($this->phrase(3, $i + 1)).'</h1><p>'
                    .str_repeat(e($this->phrase(6, $i)).'. ', 60).'</p><h2>Süreç nasıl işler?</h2><a href="tel:+903125551122">Ara</a></body></html>';
                $key = 'html-'.$i.'.html';
                Storage::disk('large-site-test')->put($key, $html);
                $objectId = DB::table('raw_ingestion_objects')->insertGetId(['uuid' => (string) Str::uuid(), 'resource_run_id' => $resource->id,
                    'collection_run_id' => $resource->collection_run_id, 'dataset_id' => 'website_html_snapshot', 'batch_key' => $key, 'provider_or_source' => 'website',
                    'storage_disk' => 'large-site-test', 'object_key' => $key, 'byte_size' => strlen($html), 'sha256' => hash('sha256', $html), 'captured_at' => $now,
                    'created_at' => $now, 'updated_at' => $now]);
                DB::table('website_html_snapshot')->insert(['digital_asset_id' => $site->id, 'url' => $url, 'raw_ingestion_object_id' => $objectId,
                    'html_hash' => hash('sha256', $html), 'html_bytes' => strlen($html), 'change_state' => 'new', 'observed_at' => $now, 'contract_version' => 1,
                    'first_collected_at' => $now, 'last_collected_at' => $now, 'record_fingerprint' => hash('sha256', 'h'.$key)]);
            }
        }

        $service = app(BrandOfferingService::class);
        for ($o = 0; $o < $offerings; $o++) {
            $offering = $service->create($brand, ucfirst(self::WORDS[$o % count(self::WORDS)]).' '.self::WORDS[($o + 1) % count(self::WORDS)].' '.$o);
            if ($o < 5) {
                $offering->forceFill(['is_priority' => true, 'priority_rank' => $o + 1])->save();
            }
        }

        return ['site' => $site->fresh(), 'brand' => $brand, 'urls' => $urls];
    }
}
