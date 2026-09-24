<?php

namespace App\Services\Compliance;

use App\Models\AdvisorItem;
use App\Models\Brand;
use App\Models\ComplianceFinding;
use App\Models\ComplianceRule;
use App\Models\DigitalAsset;
use App\Models\SeoTask;
use App\Services\Measurement\BrandMeasurementScope;
use App\Services\Website\PublicDiscovery\StoredHtmlReader;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Compliance audit of one brand against the rules of its sector packs: AI drafts on advisor items, AI SEO
 * briefs, live Meta ad text and ad set targeting, stored website pages and Business Profile content (profile,
 * posts, services). Stored data only — no provider calls, no AI. Findings keep a copy of the evidence; a
 * finding that is no longer detected is resolved, a dismissed one stays dismissed.
 */
final class ComplianceAuditor
{
    public function __construct(
        private readonly SectorPackRegistry $packs,
        private readonly ComplianceChecker $checker,
        private readonly StoredHtmlReader $htmlReader,
    ) {}

    /** @return array{rules: int, subjects: int, open: int, new: int, resolved: int} */
    public function scan(Brand $brand): array
    {
        $rules = $this->packs->rulesForBrand($brand);
        $stats = ['rules' => $rules->count(), 'subjects' => 0, 'open' => 0, 'new' => 0, 'resolved' => 0];
        if ($rules->isEmpty()) {
            $stats['resolved'] = ComplianceFinding::query()->where('brand_id', $brand->id)->where('status', ComplianceFinding::STATUS_OPEN)
                ->update(['status' => ComplianceFinding::STATUS_RESOLVED, 'resolved_at' => now()]);

            return $stats;
        }
        $now = now()->startOfSecond();
        $seen = [];
        foreach ($this->subjects($brand) as $subject) {
            $stats['subjects']++;
            $hits = $subject['source'] === 'meta_targeting'
                ? $this->checker->checkTargeting($subject['targeting'], $rules)
                : $this->checker->checkText($subject['text'], $rules, $subject['source']);
            foreach ($hits as $hit) {
                $fingerprint = hash('sha256', implode('|', [$brand->id, $hit['rule']->id, $subject['source'], $subject['ref']]));
                $seen[] = $fingerprint;
                $finding = ComplianceFinding::query()->firstWhere('fingerprint', $fingerprint);
                $attributes = [
                    'matched' => mb_substr($hit['matched'], 0, 255), 'excerpt' => mb_substr($hit['excerpt'], 0, 2000),
                    'subject_label' => mb_substr((string) $subject['label'], 0, 255), 'last_seen_at' => $now,
                ];
                if ($finding === null) {
                    ComplianceFinding::query()->create($attributes + [
                        'brand_id' => $brand->id, 'digital_asset_id' => $subject['asset_id'], 'compliance_rule_id' => $hit['rule']->id,
                        'source' => $subject['source'], 'subject_ref' => mb_substr($subject['ref'], 0, 255), 'fingerprint' => $fingerprint,
                        'status' => ComplianceFinding::STATUS_OPEN, 'first_seen_at' => $now,
                    ]);
                    $stats['new']++;
                } else {
                    if ($finding->status === ComplianceFinding::STATUS_RESOLVED) {
                        $attributes += ['status' => ComplianceFinding::STATUS_OPEN, 'resolved_at' => null];
                        $stats['new']++;
                    }
                    $finding->fill($attributes)->save();
                }
            }
        }
        $stats['resolved'] = ComplianceFinding::query()->where('brand_id', $brand->id)->where('status', ComplianceFinding::STATUS_OPEN)
            ->whereNotIn('fingerprint', $seen === [] ? [''] : array_values(array_unique($seen)))
            ->update(['status' => ComplianceFinding::STATUS_RESOLVED, 'resolved_at' => $now]);
        $stats['open'] = ComplianceFinding::query()->where('brand_id', $brand->id)->where('status', ComplianceFinding::STATUS_OPEN)->count();

        return $stats;
    }

    /**
     * Live check of one text for a brand (AI draft badges); nothing is stored.
     *
     * @return list<array{rule: ComplianceRule, matched: string, excerpt: string}>
     */
    public function checkForBrand(?Brand $brand, string $text, string $source): array
    {
        if ($brand === null) {
            return [];
        }
        $rules = $this->packs->rulesForBrand($brand);

        return $rules->isEmpty() ? [] : $this->checker->checkText($text, $rules, $source);
    }

    /** @param  array<mixed>  $value */
    public static function flatten(array $value, array $skip = ['provider', 'model', 'prompt_version', 'created_at', 'source', 'error', 'target_url']): string
    {
        $parts = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && in_array($key, $skip, true)) {
                continue;
            }
            if (is_array($item)) {
                $parts[] = self::flatten($item, $skip);
            } elseif (is_scalar($item) && ! is_bool($item)) {
                $parts[] = (string) $item;
            }
        }

        return trim(implode(' . ', array_filter($parts, fn (string $p): bool => trim($p) !== '')));
    }

    /**
     * @return iterable<array{source: string, ref: string, label: string, asset_id: ?int, text: string, targeting?: array<string, mixed>}>
     */
    private function subjects(Brand $brand): iterable
    {
        foreach (AdvisorItem::query()->where('brand_id', $brand->id)->where('draft_status', 'ready')->whereNotNull('draft')->get() as $item) {
            if (is_array($item->draft) && ! isset($item->draft['error'])) {
                yield ['source' => 'ai_draft', 'ref' => 'AdvisorItem:'.$item->id, 'label' => (string) $item->title, 'asset_id' => $item->digital_asset_id, 'text' => self::flatten($item->draft)];
            }
        }
        foreach (SeoTask::query()->where('brand_id', $brand->id)->where('status', 'open')->whereNotNull('content_brief')->get() as $task) {
            if (($task->content_brief['source'] ?? null) === 'llm') {
                yield ['source' => 'seo_brief', 'ref' => 'SeoTask:'.$task->id, 'label' => (string) $task->title, 'asset_id' => $task->digital_asset_id, 'text' => self::flatten($task->content_brief)];
            }
        }

        $scope = BrandMeasurementScope::for($brand);
        if (! $scope->isEmpty() && Schema::hasTable('meta_creative_snapshot')) {
            foreach ($scope->apply(DB::table('meta_creative_snapshot'))->orderByDesc('last_collected_at')->limit(2000)->get()->unique('creative_id') as $row) {
                $meta = (array) json_decode((string) $row->metadata, true);
                $text = trim(implode(' . ', array_filter([(string) ($meta['title'] ?? ''), (string) ($meta['body'] ?? '')])));
                if ($text !== '') {
                    yield ['source' => 'meta_ad', 'ref' => 'meta_creative:'.$row->creative_id, 'label' => (string) ($meta['name'] ?? $meta['title'] ?? 'Kreatif '.$row->creative_id), 'asset_id' => $row->digital_asset_id, 'text' => $text];
                }
            }
        }
        // Faz 14: live Google Ads responsive search ad texts (headlines + descriptions from the last 30 days).
        if (! $scope->isEmpty() && Schema::hasTable('google_ads_ad_daily')) {
            foreach ($scope->apply(DB::table('google_ads_ad_daily'))->where('reporting_date', '>=', now()->subDays(30)->toDateString())
                ->orderByDesc('reporting_date')->limit(5000)->get(['digital_asset_id', 'ad_id', 'metadata'])->unique('ad_id') as $row) {
                $meta = (array) json_decode((string) $row->metadata, true);
                $text = trim(implode(' . ', array_merge((array) ($meta['headlines'] ?? []), (array) ($meta['descriptions'] ?? []))));
                if ($text !== '') {
                    yield ['source' => 'google_ads_ad', 'ref' => 'google_ads_ad:'.$row->ad_id, 'label' => trim((string) ($meta['ad_group_name'] ?? '').' · reklam '.$row->ad_id, ' ·'), 'asset_id' => $row->digital_asset_id, 'text' => $text];
                }
            }
        }
        if (! $scope->isEmpty() && Schema::hasTable('meta_adset_targeting_snapshot')) {
            foreach ($scope->apply(DB::table('meta_adset_targeting_snapshot'))->orderByDesc('last_collected_at')->limit(2000)->get()->unique('adset_id') as $row) {
                $targeting = (array) json_decode((string) $row->targeting, true);
                if ($targeting !== []) {
                    yield ['source' => 'meta_targeting', 'ref' => 'meta_adset:'.$row->adset_id, 'label' => (string) ($row->adset_name ?: 'Reklam seti '.$row->adset_id), 'asset_id' => $row->digital_asset_id, 'text' => '', 'targeting' => $targeting];
                }
            }
        }

        yield from $this->websitePages($brand);
        yield from $this->businessProfile($scope);
    }

    /** @return iterable<array{source: string, ref: string, label: string, asset_id: ?int, text: string}> */
    private function websitePages(Brand $brand): iterable
    {
        if (! Schema::hasTable('website_html_snapshot')) {
            return;
        }
        $limit = (int) config('moxdop-sector-packs.website_pages_per_brand', 150);
        foreach (DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'website')->get() as $site) {
            $latest = DB::table('website_html_snapshot')->where('digital_asset_id', $site->id)->whereNotNull('raw_ingestion_object_id')
                ->orderByDesc('observed_at')->orderByDesc('id')->limit($limit * 4)->get(['id', 'url'])->unique('url')->take($limit);
            foreach ($latest as $snapshot) {
                try {
                    $page = $this->htmlReader->read($site, (string) $snapshot->url, (int) $snapshot->id);
                } catch (Throwable $exception) {
                    report($exception);

                    continue;
                }
                if ($page === null) {
                    continue;
                }
                yield ['source' => 'website', 'ref' => 'page:'.$snapshot->url, 'label' => (string) (parse_url((string) $snapshot->url, PHP_URL_PATH) ?: '/'), 'asset_id' => (int) $site->id, 'text' => self::visibleText($page['html'])];
            }
        }
    }

    /** @return iterable<array{source: string, ref: string, label: string, asset_id: ?int, text: string}> */
    private function businessProfile(BrandMeasurementScope $scope): iterable
    {
        if ($scope->isEmpty()) {
            return;
        }
        if (Schema::hasTable('gbp_location_snapshots')) {
            foreach ($scope->apply(DB::table('gbp_location_snapshots'))->orderByDesc('captured_at')->limit(500)->get()->unique('location_name') as $row) {
                $profile = (array) json_decode((string) $row->profile, true);
                yield ['source' => 'gbp', 'ref' => 'gbp_profile:'.$row->location_name, 'label' => 'Profil: '.($row->title ?: $row->location_name), 'asset_id' => $row->digital_asset_id,
                    'text' => trim(($row->title ?? '').' . '.($profile['description'] ?? ''))];
            }
        }
        if (Schema::hasTable('gbp_posts')) {
            foreach ($scope->apply(DB::table('gbp_posts'))->whereNotNull('summary')->orderByDesc('collected_at')->limit(500)->get()->unique('post_name') as $row) {
                yield ['source' => 'gbp', 'ref' => 'gbp_post:'.$row->post_name, 'label' => 'Gönderi: '.mb_substr((string) $row->summary, 0, 60), 'asset_id' => $row->digital_asset_id,
                    'text' => trim((string) $row->summary.' . '.self::flatten((array) json_decode((string) $row->offer, true)))];
            }
        }
        if (Schema::hasTable('gbp_service_snapshots')) {
            foreach ($scope->apply(DB::table('gbp_service_snapshots'))->orderByDesc('captured_at')->limit(200)->get()->unique('location_name') as $row) {
                $text = self::flatten((array) json_decode((string) $row->service_items, true));
                if ($text !== '') {
                    yield ['source' => 'gbp', 'ref' => 'gbp_services:'.$row->location_name, 'label' => 'Hizmetler', 'asset_id' => $row->digital_asset_id, 'text' => $text];
                }
            }
        }
    }

    public static function visibleText(string $html): string
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($document);
        foreach ($xpath->query('//script|//style|//noscript|//template|//svg') ?: [] as $node) {
            $node->parentNode?->removeChild($node);
        }
        $title = (string) ($xpath->query('//title')->item(0)?->textContent ?? '');
        $meta = (string) ($xpath->query('//meta[@name="description"]/@content')->item(0)?->nodeValue ?? '');
        // Text nodes joined with spaces: textContent glues block elements together ("İmplantÖncesi").
        $body = implode(' ', array_map(static fn ($node): string => (string) $node->nodeValue, iterator_to_array($xpath->query('//body//text()') ?: [])));

        return mb_substr(trim(preg_replace('/\s+/u', ' ', $title.' . '.$meta.' . '.$body) ?? ''), 0, 30000);
    }
}
