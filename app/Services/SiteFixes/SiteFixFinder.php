<?php

namespace App\Services\SiteFixes;

use App\Models\DigitalAsset;
use App\Models\IntelligenceProjection\WebsitePageProfile;
use App\Models\SeoTask;
use App\Models\SiteFixItem;
use App\Services\SeoTasks\SeoText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-070: finds fixable problems of a WordPress site from stored data only (public crawl + connector snapshots),
 * no provider call. Rows are upserted by a stable key; an item already applied, queued or dismissed is left alone.
 * Values are proposed later (AI or operator); this class only says what is wrong and where.
 */
final class SiteFixFinder
{
    /** Pages that are normally kept out of Google. */
    private const string UTILITY_PATHS = '#/(tesekkur|thank|cart|sepet|checkout|odeme|my-account|hesabim|login|giris|wp-admin|feed|etiket|tag|author|yazar)(/|$)#i';

    /** @return array{found: int, by_type: array<string, int>} */
    public function find(DigitalAsset $site, int $phase = 3): array
    {
        $objects = $this->wordpressObjects($site);
        $pages = $this->crawledPages($site);
        $rows = [];

        foreach ($pages as $key => $page) {
            $object = $objects[$key] ?? null;
            if ($object === null || $object['status'] !== 'publish') {
                continue;
            }
            $title = trim((string) ($page['title'] ?? ''));
            $description = trim((string) ($page['meta_description'] ?? ''));
            if ($title === '' || mb_strlen($title) > 65 || mb_strlen($title) < 15) {
                $rows[] = $this->row($site, 'seo_title', $object, $page, $title === '' ? 'Sayfada başlık (title) yok.' : 'Başlık '.mb_strlen($title).' karakter; 30–60 arası önerilir.', ['value' => $title]);
            }
            if ($description === '' || mb_strlen($description) > 160 || mb_strlen($description) < 70) {
                $rows[] = $this->row($site, 'seo_description', $object, $page, $description === '' ? 'Meta açıklama yok; Google sayfadan rastgele metin seçer.' : 'Meta açıklama '.mb_strlen($description).' karakter; 120–155 arası önerilir.', ['value' => $description]);
            }
            if ($phase >= 2 && $page['noindex'] && ! preg_match(self::UTILITY_PATHS, (string) $page['url'])) {
                $rows[] = $this->row($site, 'noindex', $object, $page, 'Yayındaki sayfa "noindex": Google’da görünmez. Bilerek kapatılmadıysa açılmalı.', ['value' => true], ['value' => false]);
            }
            if ($phase >= 2 && $page['canonical'] !== null && SeoText::urlKey($page['canonical']) !== $key) {
                $rows[] = $this->row($site, 'canonical', $object, $page, 'Canonical başka bir adresi gösteriyor ('.$page['canonical'].'); Google bu sayfayı değil onu sıralar.', ['value' => $page['canonical']], ['value' => '']);
            }
        }
        $rows = [...$rows, ...$this->duplicateTitles($site, $pages, $objects)];

        foreach ($this->imagesWithoutAlt($site) as $image) {
            $rows[] = ['type' => 'alt_text', 'object_id' => $image['object_id'], 'url' => $image['url'], 'label' => $image['title'] ?: basename((string) $image['url']),
                'reason' => 'Görselin alt metni yok (erişilebilirlik ve görsel arama).', 'current' => ['value' => '', 'file' => $image['file']], 'key' => 'alt_text|'.$image['object_id']];
        }

        $home = collect($pages)->first(fn (array $p): bool => (parse_url((string) $p['url'], PHP_URL_PATH) ?: '/') === '/');
        $localTypes = ['LocalBusiness', 'Organization', 'Dentist', 'MedicalClinic', 'MedicalBusiness', 'Physician', 'LegalService', 'Restaurant', 'Store', 'ProfessionalService', 'HomeAndConstructionBusiness', 'AutoRepair', 'BeautySalon', 'Hotel'];
        if ($home !== null && array_intersect($localTypes, (array) $home['schema_types']) === []) {
            $rows[] = ['type' => 'schema', 'object_id' => '0', 'url' => $home['url'], 'label' => 'Ana sayfa · işletme bilgisi (LocalBusiness)',
                'reason' => 'Ana sayfada işletme türü, adres, telefon ve çalışma saatini Google’a anlatan yapılandırılmış veri yok.', 'current' => ['value' => implode(', ', (array) $home['schema_types'])], 'key' => 'schema|site'];
        }

        if ($phase >= 2) {
            foreach ($pages as $key => $page) {
                if (in_array($page['status_code'], [404, 410], true) && ! preg_match(self::UTILITY_PATHS, (string) $page['url'])) {
                    $path = parse_url((string) $page['url'], PHP_URL_PATH) ?: '/';
                    $rows[] = ['type' => 'redirect', 'object_id' => null, 'url' => $path, 'label' => $path,
                        'reason' => 'Adres '.$page['status_code'].' veriyor (sayfa yok). Ziyaretçiyi ve bağlantı değerini en yakın sayfaya 301 ile aktar.', 'current' => ['value' => $page['status_code']], 'key' => 'redirect|'.$path];
                }
            }
        }

        if ($phase >= 3) {
            foreach ($pages as $key => $page) {
                $object = $objects[$key] ?? null;
                if ($object !== null && $object['status'] === 'publish' && $object['object_type'] === 'page' && $page['word_count'] !== null && $page['word_count'] < 250
                    && (parse_url((string) $page['url'], PHP_URL_PATH) ?: '/') !== '/' && ! preg_match(self::UTILITY_PATHS, (string) $page['url'])) {
                    $rows[] = $this->row($site, 'content_update', $object, $page, 'Sayfada yalnız '.$page['word_count'].' kelime var; Google ve ziyaretçi için yetersiz.', ['value' => null, 'word_count' => $page['word_count']]);
                }
            }
            foreach (SeoTask::query()->where('digital_asset_id', $site->id)->where('status', 'open')->whereNotNull('content_brief')->limit(30)->get() as $task) {
                $brief = (array) $task->content_brief;
                if (blank($brief['page_title'] ?? null) || filled($task->target_url) && isset($objects[SeoText::urlKey((string) $task->target_url)])) {
                    continue;
                }
                $rows[] = ['type' => 'new_page', 'object_id' => null, 'url' => null, 'label' => (string) $brief['page_title'],
                    'reason' => 'SEO görevi: '.$task->title, 'current' => ['seo_task_id' => $task->id, 'brief' => $brief], 'key' => 'new_page|task-'.$task->id];
            }
        }

        return $this->store($site, $rows);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{found: int, by_type: array<string, int>}
     */
    private function store(DigitalAsset $site, array $rows): array
    {
        $keys = [];
        foreach ($rows as $row) {
            $key = hash('sha256', (string) $row['key']);
            $keys[] = $key;
            $existing = SiteFixItem::query()->where('digital_asset_id', $site->id)->where('item_key', $key)->first();
            if ($existing !== null && in_array($existing->status, ['queued', 'applied', 'drafted', 'dismissed'], true)) {
                continue;
            }
            SiteFixItem::query()->updateOrCreate(['digital_asset_id' => $site->id, 'item_key' => $key], [
                'brand_id' => $site->brand_id, 'type' => $row['type'], 'phase' => SiteFixItem::TYPES[$row['type']][1] ?? 1,
                'object_id' => $row['object_id'], 'url' => $row['url'] !== null ? mb_substr((string) $row['url'], 0, 1000) : null,
                'label' => mb_substr((string) $row['label'], 0, 255), 'reason' => $row['reason'], 'current' => $row['current'],
                'status' => $existing?->status === 'failed' || $existing?->status === 'undone' ? $existing->status : 'open',
            ] + ($existing === null && isset($row['proposed']) ? ['proposed' => $row['proposed'], 'proposed_by' => 'rule'] : []));
        }
        // Problems that are gone are closed (never rows that were applied or are in flight).
        SiteFixItem::query()->where('digital_asset_id', $site->id)->where('status', 'open')->whereNotIn('item_key', $keys)->delete();

        return ['found' => count($keys), 'by_type' => collect($rows)->countBy('type')->all()];
    }

    /**
     * @param  array{object_id: string, object_type: string, status: string, title: ?string}  $object
     * @param  array<string, mixed>  $page
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>|null  $proposed
     * @return array<string, mixed>
     */
    private function row(DigitalAsset $site, string $type, array $object, array $page, string $reason, array $current, ?array $proposed = null): array
    {
        return ['type' => $type, 'object_id' => $object['object_id'], 'url' => $page['url'], 'label' => $object['title'] ?: (string) $page['url'],
            'reason' => $reason, 'current' => $current + ['h1' => $page['h1']], 'key' => $type.'|'.$object['object_id'], 'proposed' => $proposed];
    }

    /**
     * @param  array<string, array<string, mixed>>  $pages
     * @param  array<string, array<string, mixed>>  $objects
     * @return list<array<string, mixed>>
     */
    private function duplicateTitles(DigitalAsset $site, array $pages, array $objects): array
    {
        $rows = [];
        $groups = collect($pages)->filter(fn (array $p, string $k): bool => filled($p['title']) && ($objects[$k]['status'] ?? null) === 'publish')
            ->groupBy(fn (array $p): string => mb_strtolower(trim((string) $p['title'])))->filter(fn ($g): bool => $g->count() > 1);
        foreach ($groups as $group) {
            foreach ($group->slice(1) as $page) {
                $object = $objects[SeoText::urlKey((string) $page['url'])];
                $rows[] = $this->row($site, 'seo_title', $object, $page, 'Aynı başlık '.$group->count().' sayfada kullanılıyor; Google hangisini göstereceğini seçemez.', ['value' => $page['title']]);
            }
        }

        return $rows;
    }

    /** @return array<string, array{object_id: string, object_type: string, status: string, title: ?string}> url key => latest object */
    private function wordpressObjects(DigitalAsset $site): array
    {
        if (! Schema::hasTable('website_cms_object_snapshot')) {
            return [];
        }
        $out = [];
        foreach (DB::table('website_cms_object_snapshot')->where('digital_asset_id', $site->id)->where('cms', 'wordpress')->where('object_type', '!=', 'attachment')
            ->whereNotNull('permalink')->orderBy('observed_at')->get(['object_id', 'object_type', 'status', 'title', 'permalink']) as $row) {
            $out[SeoText::urlKey((string) $row->permalink)] = ['object_id' => (string) $row->object_id, 'object_type' => (string) $row->object_type, 'status' => (string) $row->status, 'title' => $row->title];
        }

        return $out;
    }

    /** @return array<string, array<string, mixed>> url key => crawl facts */
    private function crawledPages(DigitalAsset $site): array
    {
        $pages = [];
        WebsitePageProfile::query()->where('website_asset_id', $site->id)->orderBy('id')->chunk(500, function ($profiles) use (&$pages): void {
            foreach ($profiles as $profile) {
                $web = (array) data_get($profile->source_states, 'website', []);
                $url = (string) ($web['url'] ?? $profile->preferred_url);
                if ($url === '') {
                    continue;
                }
                $robots = data_get($web, 'document_head.robots');
                $robots = is_array($robots) ? implode(',', $robots) : (string) $robots;
                $canonicals = array_values(array_filter((array) data_get($web, 'document_head.canonical_hrefs', []), 'is_string'));
                $pages[SeoText::urlKey($url)] = [
                    'url' => $url,
                    'status_code' => is_numeric(data_get($web, 'http.status_code')) ? (int) data_get($web, 'http.status_code') : null,
                    'title' => data_get($web, 'document_head.title'),
                    'meta_description' => data_get($web, 'document_head.meta_description'),
                    'h1' => data_get($web, 'document_head.h1') ?? data_get($web, 'headings.h1.0'),
                    'noindex' => str_contains(mb_strtolower($robots), 'noindex'),
                    'canonical' => count($canonicals) === 1 ? $canonicals[0] : null,
                    'word_count' => is_numeric(data_get($web, 'content.word_count')) ? (int) data_get($web, 'content.word_count') : null,
                    'schema_types' => (array) data_get($web, 'schema.types', []),
                ];
            }
        });

        return $pages;
    }

    /** @return list<array{object_id: string, url: ?string, title: ?string, file: ?string}> */
    private function imagesWithoutAlt(DigitalAsset $site): array
    {
        if (! Schema::hasTable('website_cms_object_snapshot')) {
            return [];
        }
        $latest = [];
        foreach (DB::table('website_cms_object_snapshot')->where('digital_asset_id', $site->id)->where('object_type', 'attachment')->orderBy('observed_at')
            ->limit(3000)->get(['object_id', 'permalink', 'title', 'metadata']) as $row) {
            $meta = is_string($row->metadata) ? json_decode($row->metadata, true) : (array) $row->metadata;
            $latest[(string) $row->object_id] = ['object_id' => (string) $row->object_id, 'url' => $row->permalink, 'title' => $row->title,
                'file' => $meta['file'] ?? null, 'mime' => (string) ($meta['mime_type'] ?? ''), 'alt' => trim((string) ($meta['alt_text'] ?? ''))];
        }

        return array_values(array_slice(array_filter($latest, fn (array $i): bool => str_starts_with($i['mime'], 'image/') && $i['alt'] === ''), 0, 60));
    }
}
