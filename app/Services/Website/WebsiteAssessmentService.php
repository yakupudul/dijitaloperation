<?php

namespace App\Services\Website;

use App\Jobs\Async\WebsiteStandardsAssessmentJob;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\IntelligenceProjection\WebsitePageProfile;
use App\Models\ModuleRegistry;
use App\Models\Run;
use App\Models\SearchDemandImprovementProposal;
use App\Models\SearchDemandImprovementRun;
use App\Models\SearchDemandPageOwnership;
use App\Models\User;
use App\Services\Async\AsyncOperationService;
use App\Support\IntelligenceProjection\Website\WebsitePageFamilyClassifier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use MoxDop\Website\Standards\StoredPageReader;
use MoxDop\Website\Standards\WebsiteStandardCatalog;
use MoxDop\Website\Standards\WebsiteStandardEvaluator;
use Throwable;

final class WebsiteAssessmentService
{
    public const string MODE = 'website_standards';

    public const int PAGE_LIMIT = 500;

    public function __construct(
        private readonly WebsiteStandardCatalog $catalog,
        private readonly WebsiteStandardEvaluator $evaluator,
        private readonly StoredPageReader $html,
        private readonly WebsiteCoverageService $coverage,
        private readonly WebsitePageFamilyClassifier $families,
    ) {}

    public function queue(DigitalAsset $website, ?User $actor = null): SearchDemandImprovementRun
    {
        $this->assertEnabled($website);

        return Cache::lock('website-standards:'.$website->id, 15)->block(5, function () use ($website, $actor): SearchDemandImprovementRun {
            $existing = SearchDemandImprovementRun::query()->where('digital_asset_id', $website->id)
                ->where('brand_id', $website->brand_id)
                ->whereNull('search_demand_cluster_id')->whereIn('status', ['queued', 'running'])->latest('id')->first();
            if ($existing !== null) {
                return $existing;
            }
            $standards = $this->catalog->all(true);
            if ($standards === []) {
                throw ValidationException::withMessages(['assessment' => 'Önce standartlar kütüphanesinde en az bir kriteri etkinleştirin.']);
            }

            return DB::transaction(function () use ($website, $actor, $standards): SearchDemandImprovementRun {
                $activity = Run::query()->create([
                    'digital_asset_id' => $website->id, 'module_id' => 'website', 'status' => 'queued', 'started_at' => now(),
                    'metadata' => [
                        'async' => true, 'operation_type' => self::MODE, 'human_title' => 'Web sitesi standart değerlendirmesi',
                        'phase' => 'queued', 'phase_label' => 'Kuyrukta', 'progress_at' => now()->toIso8601String(),
                        'triggered_by_user_id' => $actor?->id, 'provider_calls' => 0, 'ai_calls' => 0,
                    ],
                ]);
                $run = SearchDemandImprovementRun::query()->create([
                    'uuid' => (string) Str::uuid(), 'run_id' => $activity->id, 'brand_id' => $website->brand_id,
                    'digital_asset_id' => $website->id, 'status' => 'queued',
                    'input_payload' => ['mode' => self::MODE, 'standards' => $standards],
                    'input_fingerprint' => hash('sha256', 'queued:'.$activity->id),
                    'agent_signature' => 'deterministic', 'skill_signature' => WebsiteStandardCatalog::VERSION,
                    'skill_fingerprint' => $this->catalog->fingerprint($standards),
                    'route_key' => self::MODE, 'route_signature' => hash('sha256', self::MODE), 'requested_by' => $actor?->id,
                ]);
                dispatch(new WebsiteStandardsAssessmentJob($activity->id))->afterCommit();

                return $run;
            });
        });
    }

    public function execute(int $activityId, AsyncOperationService $async): void
    {
        $run = DB::transaction(function () use ($activityId): ?SearchDemandImprovementRun {
            $run = SearchDemandImprovementRun::query()->where('run_id', $activityId)->lockForUpdate()->firstOrFail();
            if ($run->status !== 'queued') {
                return null;
            }
            $run->update(['status' => 'running', 'started_at' => now()]);

            return $run;
        });
        if ($run === null) {
            return;
        }
        $website = $run->website;
        $this->assertEnabled($website);
        $activity = $run->activityRun;
        $async->markRunning($activity, 'evaluating_stored_pages', 'Saklı sayfalar değerlendiriliyor');
        $standards = $run->input_payload['standards'];
        $profiles = WebsitePageProfile::query()->where('website_asset_id', $website->id)->orderBy('id')->limit(self::PAGE_LIMIT)->get();
        $total = WebsitePageProfile::query()->where('website_asset_id', $website->id)->count();
        $snapshots = DB::query()->fromSub(DB::table('website_html_snapshot')
            ->where('digital_asset_id', $website->id)->whereIn('url', $profiles->pluck('preferred_url'))
            ->whereNotNull('raw_ingestion_object_id')->select(['id', 'url', 'html_hash', 'observed_at'])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY url ORDER BY observed_at DESC, id DESC) AS snapshot_order'), 'latest_snapshots')
            ->where('snapshot_order', 1)->orderBy('url')->get()->keyBy('url');
        $owners = SearchDemandPageOwnership::query()->where('digital_asset_id', $website->id)
            ->where('brand_id', $website->brand_id)->where('status', 'verified_owner')
            ->whereHas('cluster', fn ($query) => $query->where('status', 'active'))->get();
        $coverage = $this->coverage->assess($website, $profiles);
        $siteEvidence = $this->siteEvidence($website);
        $comparisonFingerprint = $this->comparisonFingerprint($website);
        $fingerprint = hash('sha256', json_encode([
            'version' => WebsiteStandardCatalog::VERSION,
            'freshness' => $profiles->map(fn ($profile) => collect(['http', 'document_head', 'headings', 'structured_data', 'html'])
                ->map(fn ($key) => $this->isStale(data_get($profile->source_states, 'website.'.$key.'.observed_at')))->all())->all(),
            'snapshot_freshness' => $snapshots->map(fn ($snapshot) => $this->isStale($snapshot->observed_at))->all(),
            'tls_expired' => ($validTo = data_get($siteEvidence, 'tls_info.payload.valid_to')) !== null && strtotime($validTo) < now()->getTimestamp(),
            'website' => [$website->id, $website->brand_id, $website->primary_url, $website->seo_market_language_code],
            'snapshots' => $snapshots->toArray(),
            'standards' => $standards, 'profiles' => $profiles->toArray(), 'coverage' => $coverage,
            'owners' => $owners->toArray(), 'site_evidence' => $siteEvidence,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $previous = SearchDemandImprovementRun::query()->where('digital_asset_id', $website->id)
            ->where('brand_id', $website->brand_id)
            ->where('input_fingerprint', $fingerprint)->whereIn('status', ['completed', 'partial'])->where('id', '!=', $run->id)->latest('id')->first();
        if ($previous !== null) {
            $sourceId = data_get($previous->response_payload, 'cached_run_id') ?: $previous->id;
            $run->update(['status' => $previous->status, 'input_fingerprint' => $fingerprint,
                'response_payload' => ['cached_run_id' => $sourceId], 'completed_at' => now()]);
            $async->markFinished($activity, $previous->status, 'Aynı saklı kanıt yeniden kullanıldı', ['cached_run_id' => $sourceId, 'ai_calls' => 0]);

            return;
        }
        $storedPages = [];
        $storedErrors = [];
        $assessmentIndex = [];
        $indexComplete = $total <= self::PAGE_LIMIT;
        foreach ($profiles as $profile) {
            try {
                $storedPages[$profile->id] = $this->html->read($website, $profile);
            } catch (Throwable $error) {
                $storedPages[$profile->id] = null;
                $storedErrors[$profile->id] = class_basename($error);
            }
            $stored = $storedPages[$profile->id];
            $fresh = is_array($stored) && ! empty($stored['observed_at']) && ! $this->isStale($stored['observed_at']);
            $httpAt = data_get($profile->source_states, 'website.http.observed_at');
            $assessmentIndex[$profile->preferred_url] = [
                'status_code' => $httpAt && ! $this->isStale($httpAt) ? data_get($profile->source_states, 'website.http.status_code') : null,
                'title' => $fresh ? trim((string) ($stored['title'] ?? '')) : null,
                'meta_description' => $fresh ? trim((string) ($stored['meta_description'] ?? '')) : null,
                'content_fingerprint' => $fresh ? ($stored['content_fingerprint'] ?? null) : null,
                'noindex' => $fresh && ($stored['head_complete'] ?? false)
                    ? preg_match('/(?:^|[\\s,;:])(?:noindex|none)(?:$|[\\s,;])/i', implode(',', [
                        ...($stored['head']['robots_directives'] ?? []), ...($stored['head']['googlebot_directives'] ?? []),
                    ])) === 1 : null,
                'hreflang' => $fresh ? ($stored['seo_inspection']['hreflang'] ?? []) : [],
                'hreflang_complete' => $fresh && ($stored['head_complete'] ?? false)
                    && is_array($stored['seo_inspection'] ?? null)
                    && ! ($stored['seo_inspection']['hreflang_truncated'] ?? true),
            ];
            $indexComplete = $indexComplete && $fresh;
        }
        $pages = [];
        $summary = [];
        $issues = [];
        $unreadable = 0;
        foreach ($standards as $id => $standard) {
            $summary[$id] = ['id' => $id, 'title' => $standard['title'], 'classification' => $standard['classification'],
                'pass' => 0, 'fail' => 0, 'review' => 0, 'unknown' => 0, 'not_applicable' => 0];
        }
        foreach ($profiles as $profile) {
            $facts = (array) data_get($profile->source_states, 'website', []);
            $kind = $this->families->classify($profile->preferred_url, data_get($profile->source_states, 'wordpress.object.type'));
            $system = preg_match('#/(?:wp-admin|wp-json|wp-login\.php|feed|xmlrpc\.php)(?:/|$)#i', (string) parse_url($profile->preferred_url, PHP_URL_PATH)) === 1;
            $stored = $storedPages[$profile->id];
            $readError = $storedErrors[$profile->id] ?? null;
            if ($stored === null) {
                $unreadable++;
            }
            $page = [
                'page_profile_id' => $profile->id, 'url' => $profile->preferred_url,
                'facts' => $facts, 'stored_html' => $stored,
                'assessment_index' => $assessmentIndex, 'assessment_index_complete' => $indexComplete, 'excluded_kind' => $system || in_array($kind['kind'], ['media', 'pagination'], true),
                'search_target' => $owners->contains('website_page_profile_id', $profile->id),
                'observed_at' => $profile->last_observed_at?->toIso8601String(),
                'evaluated_at' => now()->toIso8601String(),
            ];
            $checks = [];
            foreach ($standards as $id => $standard) {
                if ($standard['applicability'] === 'site') {
                    continue;
                }
                $check = $this->evaluator->evaluate($standard, $page);
                $summary[$id][$check['state']]++;
                $checks[$id] = $check;
                if (in_array($check['state'], ['fail', 'review'], true)) {
                    $issues[$id][] = [
                        'url' => $profile->preferred_url, 'page_profile_id' => $profile->id,
                        'state' => $check['state'], 'observed' => $check['observed'],
                        'search_target' => $page['search_target'], 'observed_at' => $check['observed_at'] ?? $page['observed_at'],
                        'source_records' => $facts['source_records'] ?? [],
                        'profile_fingerprint' => $this->profileFingerprint($profile),
                        'comparison_fingerprint' => $comparisonFingerprint,
                        'latest_snapshot_id' => $snapshots->get($profile->preferred_url)?->id,
                        'html_snapshot_id' => $stored['snapshot_id'] ?? null, 'html_hash' => $stored['html_hash'] ?? null,
                    ];
                }
            }
            $pages[] = [
                'page_profile_id' => $profile->id, 'url' => $profile->preferred_url, 'observed_at' => $page['observed_at'],
                'html_read_error' => $readError, 'checks' => $checks,
            ];
        }
        $siteChecks = [];
        foreach ($standards as $id => $standard) {
            if ($standard['applicability'] !== 'site') {
                continue;
            }
            $check = $this->evaluator->evaluate($standard, ['url' => $website->primary_url ?: ('https://'.$website->domain),
                'site_evidence' => $siteEvidence, 'evaluated_at' => now()->toIso8601String()]);
            $sourceKey = match ($standard['method']) {
                'tls' => 'tls_info', 'https_redirect' => 'redirects', 'robots_file' => 'robots', 'sitemap' => 'sitemap',
                default => 'wordpress',
            };
            $check['observed_at'] = $standard['method'] === 'wp_event_delivery'
                ? data_get($siteEvidence, 'wordpress.last_event_received_at') : data_get($siteEvidence, $sourceKey.'.observed_at');
            $summary[$id][$check['state']]++;
            $siteChecks[$id] = $check;
            if (in_array($check['state'], ['fail', 'review'], true)) {
                $issues[$id][] = ['url' => $website->primary_url, 'state' => $check['state'],
                    'observed' => $check['observed'], 'site_evidence' => $siteEvidence, 'search_target' => true];
            }
        }
        $partial = $total > self::PAGE_LIMIT || $unreadable > 0 || $profiles->isEmpty()
            || $coverage['query_limit_reached'] || $coverage['cluster_limit_reached'];
        DB::transaction(function () use ($run, $fingerprint, $summary, $pages, $coverage, $issues, $standards, $total, $partial, $unreadable, $siteChecks): void {
            foreach ($issues as $id => $affected) {
                $standard = $standards[$id];
                $hasFailure = collect($affected)->contains('state', 'fail');
                $blocksTarget = $standard['group'] === 'access' && collect($affected)
                    ->contains(fn ($page) => $page['state'] === 'fail' && $page['search_target']);
                $tier = $blocksTarget ? 1 : ($hasFailure ? 2 : 4);
                $reason = match ($tier) {
                    1 => 'Doğrulanmış arama hedefinin erişim veya indeksleme engeli.',
                    2 => 'Saklı gözlemle doğrulanmış teknik eksik.',
                    default => 'Uzman incelemesi gerektiren iyileştirme adayı; sıralama zorunluluğu değildir.',
                };
                SearchDemandImprovementProposal::query()->create([
                    'search_demand_improvement_run_id' => $run->id,
                    'stable_key' => 'standard:'.$id, 'origin' => 'deterministic',
                    'severity' => $tier === 1 ? 'high' : $standard['severity'],
                    'title' => $standard['title'], 'summary' => $standard['applicability'] === 'site'
                        ? 'Site genelinde: '.$standard['title'] : count($affected).' URL üzerinde '.$standard['title'],
                    'action_type' => $standard['method'] === 'internal_links' ? 'internal_linking' : 'improve_existing',
                    'recommendation_title' => $standard['title'].($standard['applicability'] === 'site' ? ' — site ayarlarını incele' : ' — etkilenen sayfaları düzenle'),
                    'recommendation_action' => $standard['action'], 'rationale' => $reason,
                    'content_brief' => ['affected_urls' => array_column($affected, 'url'), 'objective' => $standard['action']],
                    'evidence_refs' => ['standard_id' => $id, 'standard_version' => $standard['version'],
                        'standard_snapshot' => $standard, 'affected_pages' => $affected,
                        'priority_tier' => $tier, 'priority_reason' => $reason, 'assessment_state' => $hasFailure ? 'fail' : 'review'],
                    'verification_steps' => [$standard['verification']], 'confidence' => $hasFailure ? 95 : 60,
                    'abstained' => false, 'review_status' => 'pending',
                ]);
            }
            $run->update([
                'input_fingerprint' => $fingerprint, 'status' => $partial ? 'partial' : 'completed',
                'response_payload' => ['standards' => array_values($summary), 'pages' => $pages, 'site_checks' => $siteChecks, 'coverage' => $coverage,
                    'total_pages' => $total, 'evaluated_pages' => count($pages), 'page_limit' => self::PAGE_LIMIT,
                    'unreadable_html_count' => $unreadable, 'provider_calls' => 0, 'ai_calls' => 0],
                'proposal_count' => $run->proposals()->count(), 'completed_at' => now(),
            ]);
        });
        $async->markFinished($activity->fresh(), $partial ? 'partial' : 'completed', $partial ? 'Kapsam sınırlarıyla değerlendirildi' : 'Değerlendirme tamamlandı', [
            'result_summary' => count($pages).' sayfa değerlendirildi; '.count($issues).' standart için inceleme grubu oluşturuldu.',
            'ai_calls' => 0, 'provider_calls' => 0,
        ]);
    }

    public function assertEnabled(DigitalAsset $website): void
    {
        if ($website->type !== 'website' || ! ModuleRegistry::isEnabled('website')) {
            throw ValidationException::withMessages(['assessment' => 'Etkin Website modülü ve web sitesi varlığı gerekir.']);
        }
    }

    public function assertCurrentProposal(SearchDemandImprovementProposal $proposal): void
    {
        $website = $proposal->run->website;
        $comparisonFingerprint = $this->comparisonFingerprint($website);
        foreach ((array) data_get($proposal->evidence_refs, 'affected_pages', []) as $page) {
            if (isset($page['comparison_fingerprint']) && ! hash_equals($page['comparison_fingerprint'], $comparisonFingerprint)) {
                throw ValidationException::withMessages(['proposal' => 'Karşılaştırmada kullanılan sayfa verileri değişti. Standartları yeniden değerlendirin.']);
            }
            if (isset($page['page_profile_id'])) {
                $profile = WebsitePageProfile::query()->where('website_asset_id', $website->id)->find($page['page_profile_id']);
                $currentSnapshotId = DB::table('website_html_snapshot')->where('digital_asset_id', $website->id)
                    ->where('url', $page['url'])->whereNotNull('raw_ingestion_object_id')
                    ->latest('observed_at')->latest('id')->value('id');
                $isTarget = SearchDemandPageOwnership::query()->where('digital_asset_id', $website->id)
                    ->where('brand_id', $website->brand_id)->where('website_page_profile_id', $page['page_profile_id'])
                    ->where('status', 'verified_owner')->whereHas('cluster', fn ($query) => $query->where('status', 'active'))->exists();
                $changed = $profile === null || $this->profileFingerprint($profile) !== ($page['profile_fingerprint'] ?? null)
                    || (int) $currentSnapshotId !== (int) ($page['latest_snapshot_id'] ?? null)
                    || (isset($page['observed_at']) && CarbonImmutable::parse($page['observed_at'])->lessThan(now()->subDays(30)))
                    || $isTarget !== ($page['search_target'] ?? false);
            } else {
                $changed = ($page['site_evidence'] ?? []) != $this->siteEvidence($website);
            }
            if ($changed) {
                throw ValidationException::withMessages(['proposal' => 'Sayfa kanıtı veya URL sahipliği değişmiş. Güncel değerlendirmeyi başlatıp yeni öneriyi inceleyin.']);
            }
        }
    }

    private function comparisonFingerprint(DigitalAsset $website): string
    {
        $profiles = WebsitePageProfile::query()->where('website_asset_id', $website->id)
            ->orderBy('id')->limit(self::PAGE_LIMIT)->get(['id', 'preferred_url', 'source_states']);
        $snapshots = DB::table('website_html_snapshot')->where('digital_asset_id', $website->id)
            ->whereIn('url', $profiles->pluck('preferred_url'))->select('url')
            ->selectRaw('MAX(id) AS latest_id')->groupBy('url')->orderBy('url')->get();
        return hash('sha256', json_encode([$profiles->toArray(), $snapshots->toArray()], JSON_THROW_ON_ERROR));
    }

    private function profileFingerprint(WebsitePageProfile $profile): string
    {
        return hash('sha256', json_encode([
            'url' => $profile->preferred_url, 'facts' => data_get($profile->source_states, 'website'),
            'wordpress_type' => data_get($profile->source_states, 'wordpress.object.type'),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function freshWithin(mixed $value, int $seconds): bool
    {
        $timestamp = is_string($value) ? strtotime($value) : false;
        return $timestamp !== false && $timestamp <= now()->getTimestamp() + 300
            && $timestamp >= now()->getTimestamp() - $seconds;
    }

    private function isStale(?string $observedAt): bool
    {
        return $observedAt !== null && (strtotime($observedAt) === false || strtotime($observedAt) < now()->subDays(30)->getTimestamp());
    }

    /** @return array<string, mixed> */
    private function siteEvidence(DigitalAsset $website): array
    {
        $evidence = Evidence::query()->where('digital_asset_id', $website->id)->where('source_module', 'website-diagnosis')
            ->whereIn('type', ['tls_info', 'redirects', 'robots', 'sitemap'])
            ->where('observed_at', '>=', now()->subDays(30))->latest('observed_at')->latest('id')->limit(100)->get()->unique('type');

        $result = $evidence->mapWithKeys(fn (Evidence $row) => [$row->type => [
            'evidence_id' => $row->id, 'observed_at' => $row->observed_at?->toIso8601String(),
            'payload' => collect((array) $row->payload)->except(['body', 'hops'])->all(),
        ]])->all();
        // Read completed collection facts; no provider requests are made here.
        $infra = DB::table('website_infra_snapshot as s')
            ->join('collection_dataset_runs as d', 'd.id', '=', 's.last_dataset_run_id')
            ->where('s.digital_asset_id', $website->id)->where('d.status', 'completed')
            ->where('s.observed_at', '>=', now()->subDays(30))
            ->select('s.*')->orderByDesc('s.observed_at')->orderByDesc('s.id')->first();
        if ($infra !== null && (! isset($result['tls_info'])
            || strtotime($infra->observed_at) >= strtotime($result['tls_info']['observed_at']))) {
            $metadata = is_string($infra->metadata) ? json_decode($infra->metadata, true) : (array) $infra->metadata;
            $result['tls_info'] = ['source_record_id' => $infra->id, 'dataset_run_id' => $infra->last_dataset_run_id,
                'observed_at' => $infra->observed_at, 'payload' => $metadata['tls'] ?? []];
        }
        $siteUrl = $website->primary_url ?: ('https://'.$website->domain);
        $parts = parse_url($siteUrl);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');
        $documentUrls = [$origin.'/robots.txt'];
        $documents = DB::table('website_http_snapshot as s')
            ->join('collection_dataset_runs as d', 'd.id', '=', 's.last_dataset_run_id')
            ->where('s.digital_asset_id', $website->id)->where('d.status', 'completed')
            ->whereIn('s.url', $documentUrls)->where('s.observed_at', '>=', now()->subDays(30))
            ->select('s.*')->orderByDesc('s.observed_at')->orderByDesc('s.id')->limit(20)->get()->unique('url');
        foreach ($documents as $document) {
            $metadata = is_string($document->metadata) ? json_decode($document->metadata, true) : (array) $document->metadata;
            if (! isset($result['robots']) || strtotime($document->observed_at) >= strtotime($result['robots']['observed_at'])) {
                $result['robots'] = ['source_record_id' => $document->id, 'dataset_run_id' => $document->last_dataset_run_id,
                    'observed_at' => $document->observed_at, 'payload' => ['status_code' => $metadata['status_code'] ?? null]];
            }
        }
        $connection = \App\Models\CoreConnection::query()->where('digital_asset_id', $website->id)
            ->where('type', 'wordpress_connector')->first();
        $result['wordpress_expected'] = $connection !== null || str_contains(strtolower((string) $website->cms), 'wordpress');
        if ($connection?->enabled && data_get($connection->config, 'pairing_state') === 'paired') {
            $site = DB::table('website_cms_site_snapshot as s')
                ->join('collection_dataset_runs as d', 'd.id', '=', 's.last_dataset_run_id')
                ->where('s.digital_asset_id', $website->id)->where('d.status', 'completed')
                ->select('s.*')->orderByDesc('s.observed_at')->orderByDesc('s.id')->first();
            if ($site !== null) {
                $delivery = DB::table('website_connector_delivery')->where('connection_id', $connection->id)->first();
                $extensions = DB::table('website_cms_extension_snapshot as s')
                    ->join('collection_dataset_runs as d', 'd.id', '=', 's.last_dataset_run_id')
                    ->where('s.digital_asset_id', $website->id)->where('d.status', 'completed')
                    ->select('s.*')->orderBy('s.extension_id')->limit(500)->get();
                $result['wordpress'] = [
                    'observed_at' => $site->observed_at, 'source_record_id' => $site->id,
                    'dataset_run_id' => $site->last_dataset_run_id,
                    'payload' => is_string($site->metadata) ? json_decode($site->metadata, true) : (array) $site->metadata,
                    'last_event_received_at' => $delivery?->last_received_at,
                    'extensions_truncated' => $extensions->count() >= 500,
                    'last_inventory_at' => $delivery?->last_inventory_at,
                    'extensions' => $extensions->map(function ($row): array {
                        $meta = is_string($row->metadata) ? json_decode($row->metadata, true) : (array) $row->metadata;
                        return ['id' => $row->extension_id, 'name' => $row->name,
                            'update_available' => (bool) $row->update_available,
                            'available_version' => $row->available_version,
                            'checked_at' => $meta['update_checked_at'] ?? null];
                    })->all(),
                ];
                if ($delivery !== null && $delivery->plugin_version !== null) {
                    $result['wordpress']['delivery_gap_at'] = $delivery->gap_at;
                }
                $wp = $result['wordpress'];
                $result['wordpress']['freshness'] = [
                    'site' => $this->freshWithin($wp['observed_at'] ?? null, 345600),
                    'core_updates' => $this->freshWithin(data_get($wp, 'payload.core_update_checked_at'), 172800),
                    'delivery' => $this->freshWithin($wp['last_event_received_at'] ?? null, 1800),
                    'extensions' => array_map(fn ($row) => $this->freshWithin($row['checked_at'], 172800), $wp['extensions']),
                ];
            }
        }
        return $result;
    }
}
