<?php

namespace App\Services\GoogleAds;

use App\Ai\Agents\GoogleAdsAdTextsAgent;
use App\Ai\Agents\GoogleAdsSearchTermsAgent;
use App\Ai\Agents\GoogleAdsStructureAgent;
use App\Jobs\GoogleAds\RunGoogleAdsAssistantJob;
use App\Models\Brand;
use App\Models\BrandClusterPage;
use App\Models\BrandOffering;
use App\Models\BrandQuery;
use App\Models\BrandServiceArea;
use App\Models\Cluster;
use App\Models\DigitalAsset;
use App\Models\GoogleAdsBudgetPlan;
use App\Models\Page;
use App\Models\Query;
use App\Models\Suggestion;
use App\Services\Advisor\GoogleAds\GoogleAdsAdvisorInputCollector;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Services\Analyst\AnalystNumbers;
use App\Services\Archive\ProductionArchive;
use App\Services\Compliance\ComplianceAuditor;
use App\Services\Compliance\SectorPackRegistry;
use App\Services\ExternalWrites\GoogleAdsNegativeListWriter;
use App\Services\SeoTasks\SeoText;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Faz 5 AI operations of the Google Ads screen, each one queued call on an operator click, operational brands only:
 *  - `google_ads.search_terms`: intent + service fit per term (Arama Terimleri verdict column) and negatives with
 *    match type, scope and the useful queries each could block → suggestions;
 *  - `google_ads.structure`: campaign / ad group structure and budget split by the main services + experiment plan;
 *  - `google_ads.ad_texts`: one responsive search ad (headlines ≤ 30, descriptions ≤ 90) + landing page for one ad group.
 * Every term, name, URL and number of the output is checked against the data pack before it is stored; texts pass
 * the sector compliance gate; nothing proposes pausing a campaign without enough data. A failed check stores nothing.
 */
final class GoogleAdsAssistant
{
    public const string OP_TERMS = 'search_terms';

    public const string OP_STRUCTURE = 'structure';

    public const string OP_ADS = 'ad_texts';

    public const array OPERATIONS = [
        self::OP_TERMS => GoogleAdsSearchTermsAgent::class,
        self::OP_STRUCTURE => GoogleAdsStructureAgent::class,
        self::OP_ADS => GoogleAdsAdTextsAgent::class,
    ];

    public const int HEADLINE_MAX = 30;

    public const int DESCRIPTION_MAX = 90;

    public const int PATH_MAX = 15;

    public const int MAX_NEGATIVES = 100;

    /** A shared negative rests on at least this many clicks (or on two terms or more). */
    public const int SHARED_MIN_CLICKS = 5;

    private const string CONTACT_PATTERN = '~https?://|www\.|[\w.+-]+@[\w-]+\.[\w.]+|\+?\d[\d\s().-]{8,}\d~iu';

    private const string PAUSE_PATTERN = '/\b(kapat|durdur|duraklat|pause)/iu';

    public function __construct(
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
        private readonly GoogleAdsScreen $screen,
        private readonly GoogleAdsSuggestions $suggestions,
        private readonly GoogleAdsAdvisorInputCollector $collector,
        private readonly ProductionArchive $archive,
    ) {}

    /**
     * @param  array{terms?: list<string>, ad_group?: string}  $params
     *
     * @throws ValidationException
     */
    public function queue(DigitalAsset $asset, string $operation, array $params = []): void
    {
        if (! isset(self::OPERATIONS[$operation])) {
            throw ValidationException::withMessages(['ads' => 'Bilinmeyen işlem.']);
        }
        $asset->loadMissing('brand.customer');
        if ($asset->brand === null || ! $asset->brand->isOperational()) {
            throw ValidationException::withMessages(['ads' => 'Marka operasyonel değil; AI çalışmaz.']);
        }
        if ($this->screen->context($asset) === null) {
            throw ValidationException::withMessages(['ads' => 'Google Ads hesabı bağlı değil.']);
        }
        if ($this->routes->resolve(self::OPERATIONS[$operation]::OPERATION)->isEmpty()) {
            throw ValidationException::withMessages(['ads' => 'Uygun AI sağlayıcısı yok ya da aylık AI bütçesi doldu (Ayarlar → AI).']);
        }
        if ($operation === self::OP_ADS && $this->adGroupTarget($asset, (string) ($params['ad_group'] ?? '')) === null) {
            throw ValidationException::withMessages(['ads' => 'Reklam grubu seçin.']);
        }
        Cache::put(self::stateKey((int) $asset->id, $operation), ['status' => 'running'], now()->addMinutes(15));
        RunGoogleAdsAssistantJob::dispatch((int) $asset->id, $operation, $params);
    }

    /** Executed by the job; the outcome is kept for the screen (running → ready | failed with a Turkish message). */
    public function run(int $assetId, string $operation, array $params = []): void
    {
        try {
            $asset = DigitalAsset::query()->with('brand.customer')->find($assetId);
            if ($asset === null || $asset->brand === null || ! $asset->brand->isOperational()) {
                throw new RuntimeException('Marka operasyonel değil; AI çalışmaz.');
            }
            $message = match ($operation) {
                self::OP_TERMS => $this->reviewTerms($asset, array_values(array_map('strval', (array) ($params['terms'] ?? [])))),
                self::OP_STRUCTURE => $this->proposeStructure($asset),
                self::OP_ADS => $this->writeAdTexts($asset, (string) ($params['ad_group'] ?? '')),
                default => throw new RuntimeException('Bilinmeyen işlem.'),
            };
            Cache::put(self::stateKey($assetId, $operation), ['status' => 'ready', 'message' => $message], now()->addDay());
        } catch (Throwable $exception) {
            Cache::put(self::stateKey($assetId, $operation), ['status' => 'failed', 'message' => mb_substr($exception->getMessage(), 0, 300)], now()->addDay());
        }
    }

    /** @return array{status: string, message?: string}|null */
    public function state(int $assetId, string $operation): ?array
    {
        $state = Cache::get(self::stateKey($assetId, $operation));

        return is_array($state) ? $state : null;
    }

    public static function stateKey(int $assetId, string $operation): string
    {
        return 'google-ads-assistant:'.$assetId.':'.$operation;
    }

    /* ------------------------------------------------------------------ search terms */

    /** @param  list<string>  $focus */
    public function reviewTerms(DigitalAsset $asset, array $focus = []): string
    {
        $all = $this->screen->searchTerms($asset, 30, 1000);
        if ($all === []) {
            throw new RuntimeException('Arama terimi verisi yok; önce Google Ads verisini çekin.');
        }
        $focusKeys = array_flip(array_map(fn (string $t): string => mb_strtolower(trim($t)), $focus));
        $terms = $focusKeys !== [] ? array_values(array_filter($all, fn (array $t): bool => isset($focusKeys[mb_strtolower($t['term'])]))) : array_slice($all, 0, 200);
        if ($terms === []) {
            throw new RuntimeException('Seçilen terimler veride yok.');
        }
        $pack = $this->termPack($asset, $all, $terms);
        // A term that converted in the last 90 days, anywhere in the list, is never blocked (not only the 30-day top 1000).
        $pack['_converting'] = array_values(array_unique([...$pack['_converting'], ...array_map(fn (array $t): string => $t['term'],
            array_filter($this->screen->searchTerms($asset, 90, 5000), fn (array $t): bool => $t['conversions'] > 0))]));
        [$raw, $versionId] = $this->call(self::OP_TERMS, $pack + ['focus' => array_column($terms, 'term')]);
        $valid = self::validateTermReview($raw, $pack);

        $previous = $focusKeys !== [] ? $this->screen->verdicts($asset) : [];
        $this->archive->record(GoogleAdsScreen::VERDICT_KIND, $asset, ['verdicts' => array_merge($previous, $valid['verdicts']), 'prompt_version_id' => $versionId,
            'prompt_version' => 'google-ads-search-terms', 'reviewed_at' => now()->toIso8601String()],
            ['brand_id' => $asset->brand_id, 'digital_asset_id' => $asset->id, 'title' => 'Arama terimi incelemesi']);

        $items = [];
        foreach ($valid['negatives'] as $n) {
            $scopeLabel = ['shared' => 'paylaşılan liste', 'campaign' => 'kampanya: '.$n['campaign'], 'ad_group' => 'reklam grubu: '.$n['ad_group']][$n['scope']];
            $evidence = array_map(fn (array $t): array => ['terim' => $t['term'], 'maliyet' => $t['cost'], 'tık' => $t['clicks'], 'dönüşüm' => $t['conversions']], array_slice($n['covers'], 0, 10));
            $evidence[] = ['kapsam' => $scopeLabel, 'engelleyebileceği_faydalı_sorgular' => $n['blocks'] === [] ? 'yok' : implode(', ', array_slice($n['blocks'], 0, 10))];
            $items[] = ['key' => 'negative:'.$n['scope'].':'.SeoText::fold($n['campaign'].' '.$n['ad_group'].' '.$n['text']).':'.$n['match_type'],
                'title' => 'Negatif: «'.$n['text'].'» ('.self::matchLabel($n['match_type']).')', 'reason' => $n['reason'] !== '' ? $n['reason'] : 'Hizmetle ilgisiz arama terimi.',
                'priority' => $n['cost'] >= 100 ? 1 : 2, 'evidence' => $evidence, 'action_type' => 'ads_negative', 'prompt_version_id' => $versionId,
                'action' => ['text' => $n['text'], 'match_type' => $n['match_type'], 'scope' => $n['scope'], 'campaign' => $n['campaign'], 'ad_group' => $n['ad_group'],
                    'blocks' => $n['blocks'], 'cost' => $n['cost']]];
        }
        $this->suggestions->replaceGroup($asset, 'negative', $items, sweep: $focusKeys === []);

        return count($valid['verdicts']).' terim incelendi, '.count($items).' negatif önerisi.';
    }

    /**
     * @param  list<array<string, mixed>>  $all
     * @param  list<array<string, mixed>>  $terms
     * @return array<string, mixed>
     */
    private function termPack(DigitalAsset $asset, array $all, array $terms): array
    {
        $brand = $asset->brand;
        $ctx = $this->screen->context($asset);
        $names = $this->screen->names($ctx['scope']);
        $campaigns = [];
        foreach ($names['campaigns'] as $id => $campaign) {
            if (($campaign['status'] ?? null) !== 'REMOVED') {
                $campaigns[$campaign['name']] = [];
                foreach ($names['ad_groups'] as $group) {
                    if ($group['campaign_id'] === (string) $id && ($group['status'] ?? null) !== 'REMOVED') {
                        $campaigns[$campaign['name']][] = $group['name'];
                    }
                }
            }
        }
        $keywords = array_values(array_unique(array_filter(array_map(fn (array $k): string => $k['status'] === 'ENABLED' ? $k['text'] : '', $this->screen->keywordTexts($ctx['scope'])))));
        $organic = BrandQuery::query()->where('brand_id', $brand->id)->where('clicks_28d', '>', 0)->orderByDesc('clicks_28d')->limit(100)->pluck('query_id');
        $organicTexts = $organic->isEmpty() ? [] : Query::query()->whereIn('id', $organic)->pluck('text')->all();
        $converting = array_values(array_map(fn (array $t): string => $t['term'], array_filter($all, fn (array $t): bool => $t['conversions'] > 0)));
        $negatives = array_map(fn (array $n): array => ['text' => $n['text'], 'match_type' => $n['match_type'], 'level' => $n['level']],
            $this->collector->collect($asset)['negatives'] ?? []);

        return [
            'brand' => ['name' => $brand->name, 'sector' => $brand->sectorCategory?->name ?? $brand->sector],
            'offerings' => $this->offerings($brand),
            'areas' => $this->areas($brand),
            'languages' => array_values((array) ($brand->languages ?? [])),
            'terms' => array_map(fn (array $t): array => ['term' => $t['term'], 'campaign' => implode(', ', $t['campaigns']), 'ad_group' => implode(', ', $t['ad_groups']),
                'cost' => $t['cost'], 'clicks' => $t['clicks'], 'conversions' => $t['conversions'], 'service' => $t['service'] ?? ''], $terms),
            'campaigns' => $campaigns,
            'negatives' => $negatives,
            'useful_queries' => array_values(array_unique(array_merge($converting, array_slice($keywords, 0, 200), $organicTexts))),
            '_converting' => $converting,
            '_all_terms' => array_map(fn (array $t): array => ['term' => $t['term'], 'cost' => $t['cost'], 'clicks' => $t['clicks'], 'conversions' => $t['conversions']], $all),
        ];
    }

    /**
     * Keeps only what the data supports: verdicts for terms of the pack with services copied from the offerings; negatives
     * that are valid keywords, not already in use, cover at least one observed term, never block a converting term, and
     * whose campaign / ad group exists; reasons may quote only numbers of the pack. Each negative carries the terms it
     * covers, their cost and the useful queries it could block (computed, not taken from the AI).
     *
     * @param  array<mixed>  $raw
     * @param  array<string, mixed>  $pack
     * @return array{verdicts: array<string, array{intent: string, service: string, fit: string, reason: string}>, negatives: list<array{text: string, match_type: string, scope: string, campaign: string, ad_group: string, reason: string, covers: list<array<string, mixed>>, blocks: list<string>, cost: float}>}
     */
    public static function validateTermReview(array $raw, array $pack): array
    {
        $packTerms = [];
        foreach ($pack['terms'] as $t) {
            $packTerms[mb_strtolower($t['term'])] = $t;
        }
        $offerings = array_column($pack['offerings'], 'name');
        $numbers = self::packNumbers($pack);
        $verdicts = [];
        foreach ((array) ($raw['terms'] ?? []) as $row) {
            $key = is_array($row) ? mb_strtolower(trim((string) ($row['term'] ?? ''))) : '';
            $intent = (string) ($row['intent'] ?? '');
            $fit = (string) ($row['fit'] ?? '');
            $service = trim((string) ($row['service'] ?? ''));
            if (! isset($packTerms[$key]) || ! in_array($intent, ['ticari', 'bilgi', 'marka', 'rakip', 'alakasiz'], true) || ! in_array($fit, ['uygun', 'kismen', 'uygunsuz'], true)) {
                continue;
            }
            $reason = self::line((string) ($row['reason'] ?? ''));
            $verdicts[$key] = ['intent' => $intent, 'service' => in_array($service, $offerings, true) ? $service : '', 'fit' => $fit,
                'reason' => self::numbersOk($reason, $numbers) ? $reason : ''];
        }

        $campaigns = (array) $pack['campaigns'];
        $existing = [];
        foreach ((array) $pack['negatives'] as $n) {
            $existing[strtoupper((string) $n['match_type']).'|'.SeoText::fold((string) $n['text'])] = true;
        }
        $useful = array_values(array_diff((array) $pack['useful_queries'], (array) $pack['_converting']));
        $negatives = [];
        foreach ((array) ($raw['negatives'] ?? []) as $row) {
            if (! is_array($row) || count($negatives) >= self::MAX_NEGATIVES) {
                continue;
            }
            $match = strtoupper((string) ($row['match_type'] ?? ''));
            $scope = (string) ($row['scope'] ?? '');
            $parsed = GoogleAdsNegativeListWriter::parse('['.trim((string) ($row['text'] ?? '')).']')['keywords'][0] ?? null;
            if ($parsed === null || ! in_array($match, ['EXACT', 'PHRASE', 'BROAD'], true) || ! in_array($scope, ['shared', 'campaign', 'ad_group'], true)) {
                continue;
            }
            $text = $parsed['text'];
            $campaign = trim((string) ($row['campaign'] ?? ''));
            $adGroup = trim((string) ($row['ad_group'] ?? ''));
            if ($scope === 'shared') {
                [$campaign, $adGroup] = ['', ''];
                $match = $match === 'BROAD' ? 'PHRASE' : $match;
            } elseif ($scope === 'campaign') {
                if (! array_key_exists($campaign, $campaigns)) {
                    continue;
                }
                $adGroup = '';
            } else {
                $owner = null;
                foreach ($campaigns as $name => $groups) {
                    if (in_array($adGroup, (array) $groups, true) && ($owner === null || $name === $campaign)) {
                        $owner = (string) $name;
                    }
                }
                if ($owner === null) {
                    continue;
                }
                $campaign = $owner;
            }
            if (isset($existing[$match.'|'.SeoText::fold($text)])) {
                continue;
            }
            $blocksConverting = false;
            foreach ((array) $pack['_converting'] as $term) {
                if (GoogleAdsChecks::blocks($text, $match, $term)) {
                    $blocksConverting = true;
                    break;
                }
            }
            $covers = array_values(array_filter((array) $pack['_all_terms'], fn (array $t): bool => GoogleAdsChecks::blocks($text, $match, $t['term'])));
            if ($blocksConverting || $covers === []) {
                continue;
            }
            $blocks = array_values(array_filter($useful, fn (string $q): bool => GoogleAdsChecks::blocks($text, $match, $q)));
            // The shared list runs on every Search campaign (brand campaigns too): it never blocks an enabled keyword or an
            // organic query, the brand's name, its services or its places, and it rests on more than one 1-click term.
            if ($scope === 'shared' && ($blocks !== [] || self::blocksOwn($text, $match, $pack)
                || (array_sum(array_column($covers, 'clicks')) < self::SHARED_MIN_CLICKS && count($covers) < 2))) {
                continue;
            }
            $cost = round(array_sum(array_column($covers, 'cost')), 2);
            $reason = self::line((string) ($row['reason'] ?? ''));
            if (! self::numbersOk($reason, [...$numbers, $cost, (float) array_sum(array_column($covers, 'clicks')), (float) count($covers)])) {
                continue;
            }
            $key = $scope.'|'.$campaign.'|'.$adGroup.'|'.$match.'|'.$text;
            $negatives[$key] = ['text' => $text, 'match_type' => $match, 'scope' => $scope, 'campaign' => $campaign, 'ad_group' => $adGroup, 'reason' => $reason,
                'covers' => $covers, 'cost' => $cost, 'blocks' => $blocks];
        }
        $negatives = array_values($negatives);
        usort($negatives, fn (array $a, array $b): int => $b['cost'] <=> $a['cost']);

        return ['verdicts' => $verdicts, 'negatives' => $negatives];
    }

    /** Whether a negative would block the brand's name, one of its services or one of its places. @param  array<string, mixed>  $pack */
    private static function blocksOwn(string $text, string $match, array $pack): bool
    {
        $own = [(string) data_get($pack, 'brand.name'), ...array_column((array) ($pack['offerings'] ?? []), 'name')];
        foreach ((array) ($pack['areas'] ?? []) as $area) {
            foreach (explode(',', (string) ($area['name'] ?? '')) as $part) {
                $own[] = trim($part);
            }
        }
        foreach ($own as $phrase) {
            if (mb_strlen($phrase) >= 3 && strtoupper($phrase) !== 'TR' && GoogleAdsChecks::blocks($text, $match, $phrase)) {
                return true;
            }
        }

        return false;
    }

    /* ------------------------------------------------------------------ structure */

    public function proposeStructure(DigitalAsset $asset): string
    {
        $brand = $asset->brand;
        $offerings = $this->offerings($brand);
        if ($offerings === []) {
            throw new RuntimeException('Markanın onaylı hizmeti yok (Marka › Ayarlar › Hizmetler).');
        }
        $input = $this->collector->collect($asset);
        $budget = $this->totalDailyBudget($asset);
        if ($budget === null) {
            throw new RuntimeException('Günlük bütçe verisi yok; önce Google Ads verisini çekin.');
        }
        $pages = $this->pages($brand);
        // Performance of the 30 days before the conversion-lag window (its conversions are still arriving).
        $lag = (int) ($input['conversion_lag_days']['days'] ?? GoogleAdsAdvisorInputCollector::DEFAULT_CONVERSION_LAG_DAYS);
        $periodEnd = isset($input['period']['end']) ? CarbonImmutable::parse($input['period']['end'])->subDays($lag) : null;
        $campaigns = [];
        foreach ($input['campaigns'] ?? [] as $id => $c) {
            $t = ['cost' => 0.0, 'clicks' => 0, 'conversions' => 0.0];
            foreach ($input['campaign_daily'][$id] ?? [] as $date => $m) {
                $age = $periodEnd === null ? -1 : (int) ((strtotime($periodEnd->toDateString()) - strtotime((string) $date)) / 86400);
                if ($age >= 0 && $age < 30) {
                    $t['cost'] += $m['cost'];
                    $t['clicks'] += $m['clicks'];
                    $t['conversions'] += $m['conversions'];
                }
            }
            $campaigns[] = ['name' => $c['name'], 'status' => $c['status'], 'daily_budget' => $c['budget_amount'], 'cost' => round($t['cost'], 2), 'clicks' => $t['clicks'],
                'conversions' => round($t['conversions'], 2), 'enough_data' => $t['conversions'] >= GoogleAdsChecks::MIN_CONVERSIONS && $t['clicks'] >= GoogleAdsChecks::MIN_CLICKS,
                'ad_groups' => array_values(array_unique(array_map(fn (array $k): string => (string) ($input['ads']['ad_groups'][$k['ad_group_id']] ?? $k['ad_group_id']),
                    array_filter($input['keywords'] ?? [], fn (array $k): bool => $k['campaign_id'] === (string) $id))))];
        }
        $pack = [
            'offerings' => $offerings, 'areas' => $this->areas($brand), 'languages' => array_values((array) ($brand->languages ?? [])),
            'clusters' => $this->clusters($brand), 'campaigns' => $campaigns, 'total_daily_budget' => round($budget, 2),
            'currency' => $input['currency'] ?? null, 'pages' => array_map(fn (array $p): array => ['url' => $p['url'], 'title' => $p['title'], 'category' => $p['category']], $pages),
            'performance_period' => $periodEnd === null ? null : ['start' => $periodEnd->subDays(29)->toDateString(), 'end' => $periodEnd->toDateString(), 'excluded_recent_days' => $lag],
            // The operator's CRM numbers (form / uygun / randevu / satış), separate from Google Ads conversions.
            'lead_quality' => app(GoogleAdsLeadQuality::class)->recent($asset),
        ];
        [$raw, $versionId] = $this->call(self::OP_STRUCTURE, $pack);
        $valid = self::validateStructure($raw, $pack);
        if ($valid['campaigns'] === []) {
            throw new RuntimeException('AI veriyle uyumlu bir kampanya yapısı döndürmedi; tekrar deneyin.');
        }
        $items = [];
        foreach ($valid['campaigns'] as $i => $c) {
            $keywordCount = array_sum(array_map(fn (array $g): int => count($g['keywords']), $c['ad_groups']));
            $items[] = ['key' => 'structure:'.SeoText::fold($c['name']), 'title' => 'Kampanya: '.$c['name'], 'reason' => $c['reason'] !== '' ? $c['reason'] : $c['service'].' için arama kampanyası.',
                'priority' => $i < 2 ? 1 : 2, 'prompt_version_id' => $versionId, 'action_type' => 'ads_campaign',
                'evidence' => [['hizmet' => $c['service'], 'reklam_grubu' => count($c['ad_groups']), 'anahtar_kelime' => $keywordCount, 'günlük_bütçe' => $c['daily_budget'], 'toplam_günlük_bütçe' => $pack['total_daily_budget']]],
                'action' => ['name' => $c['name'], 'service' => $c['service'], 'daily_budget' => $c['daily_budget'], 'ad_groups' => $c['ad_groups']]];
        }
        $this->suggestions->replaceGroup($asset, 'structure', $items);
        $this->suggestions->replaceGroup($asset, 'budget', $valid['budget_split'] === [] ? [] : [[
            'key' => 'budget:split', 'title' => 'Bütçe dağılımı (hizmetlere göre)', 'reason' => 'Toplam günlük '.$pack['total_daily_budget'].' bütçenin ana hizmetlere dağılımı.',
            'priority' => 2, 'prompt_version_id' => $versionId, 'action_type' => 'ads_budget_split',
            'evidence' => array_map(fn (array $r): array => ['hizmet' => $r['service'], 'günlük_bütçe' => $r['daily_budget'], 'neden' => $r['reason']], $valid['budget_split']),
            'action' => ['total' => $pack['total_daily_budget'], 'rows' => $valid['budget_split']],
        ]]);
        $this->suggestions->replaceGroup($asset, 'experiment', $valid['experiments'] === [] ? [] : [[
            'key' => 'experiment:plan', 'title' => 'Deney planı', 'reason' => count($valid['experiments']).' deney: '.$valid['experiments'][0]['title'].'.',
            'priority' => 3, 'prompt_version_id' => $versionId, 'action_type' => 'ads_experiments',
            'evidence' => array_map(fn (array $e): array => ['deney' => $e['title'], 'hipotez' => $e['hypothesis'], 'ölçüt' => $e['metric'], 'gün' => $e['duration_days']], $valid['experiments']),
            'action' => ['rows' => $valid['experiments']],
        ]]);

        return count($valid['campaigns']).' kampanya, '.count($valid['budget_split']).' hizmet bütçesi, '.count($valid['experiments']).' deney.';
    }

    /**
     * Services must be the brand's offerings; landing URLs must be the brand's pages; keywords must be valid; campaign
     * budgets and the budget split are scaled to the total daily budget; nothing proposes pausing without enough data;
     * reasons may quote only numbers of the pack.
     *
     * @param  array<mixed>  $raw
     * @param  array<string, mixed>  $pack
     * @return array{campaigns: list<array{name: string, service: string, daily_budget: float, reason: string, ad_groups: list<array{name: string, landing_url: string, keywords: list<array{text: string, match_type: string}>}>}>, budget_split: list<array{service: string, daily_budget: float, reason: string}>, experiments: list<array{title: string, hypothesis: string, metric: string, duration_days: int}>}
     */
    public static function validateStructure(array $raw, array $pack): array
    {
        $offerings = array_column($pack['offerings'], 'name');
        $pages = [];
        foreach ($pack['pages'] as $page) {
            $pages[GoogleAdsChecks::normalizeUrl($page['url'])] = $page['url'];
        }
        $total = (float) $pack['total_daily_budget'];
        $lowData = array_column(array_filter($pack['campaigns'], fn (array $c): bool => ! $c['enough_data']), 'name');
        $anyEnough = count($lowData) < count($pack['campaigns']);
        $numbers = self::packNumbers($pack);
        $campaigns = [];
        foreach ((array) ($raw['campaigns'] ?? []) as $row) {
            $name = is_array($row) ? self::line((string) ($row['name'] ?? ''), 100) : '';
            $service = is_array($row) ? trim((string) ($row['service'] ?? '')) : '';
            if ($name === '' || ! in_array($service, $offerings, true) || isset($campaigns[$name]) || count($campaigns) >= 10) {
                continue;
            }
            $groups = [];
            foreach ((array) ($row['ad_groups'] ?? []) as $group) {
                $groupName = is_array($group) ? self::line((string) ($group['name'] ?? ''), 100) : '';
                $keywords = [];
                foreach ((array) ($group['keywords'] ?? []) as $keyword) {
                    $parsed = GoogleAdsNegativeListWriter::parse('['.trim((string) ($keyword['text'] ?? '')).']')['keywords'][0] ?? null;
                    $match = strtoupper((string) ($keyword['match_type'] ?? ''));
                    if ($parsed !== null && in_array($match, ['EXACT', 'PHRASE', 'BROAD'], true) && count($keywords) < 20) {
                        $keywords[$match.'|'.$parsed['text']] = ['text' => $parsed['text'], 'match_type' => $match];
                    }
                }
                if ($groupName === '' || $keywords === [] || count($groups) >= 10) {
                    continue;
                }
                $url = $pages[GoogleAdsChecks::normalizeUrl((string) ($group['landing_url'] ?? ''))] ?? '';
                $groups[] = ['name' => $groupName, 'landing_url' => $url, 'keywords' => array_values($keywords)];
            }
            $reason = self::line((string) ($row['reason'] ?? ''));
            if ($groups === [] || self::pausesLowData($reason, $lowData, $anyEnough)) {
                continue;
            }
            $campaigns[$name] = ['name' => $name, 'service' => $service, 'daily_budget' => max(0.0, (float) ($row['daily_budget'] ?? 0)),
                'reason' => self::numbersOk($reason, $numbers) ? $reason : '', 'ad_groups' => $groups];
        }
        $campaigns = self::scaleBudgets(array_values($campaigns), $total);

        $split = [];
        foreach ((array) ($raw['budget_split'] ?? []) as $row) {
            $service = is_array($row) ? trim((string) ($row['service'] ?? '')) : '';
            $amount = (float) ($row['daily_budget'] ?? 0);
            if (! in_array($service, $offerings, true) || $amount <= 0 || isset($split[$service])) {
                continue;
            }
            $reason = self::line((string) ($row['reason'] ?? ''));
            $split[$service] = ['service' => $service, 'daily_budget' => $amount, 'reason' => self::numbersOk($reason, $numbers) ? $reason : ''];
        }
        $split = self::scaleBudgets(array_values($split), $total);

        $experiments = [];
        foreach ((array) ($raw['experiments'] ?? []) as $row) {
            $title = is_array($row) ? self::line((string) ($row['title'] ?? ''), 120) : '';
            $hypothesis = is_array($row) ? self::line((string) ($row['hypothesis'] ?? '')) : '';
            if ($title === '' || count($experiments) >= 3 || self::pausesLowData($title.' '.$hypothesis, $lowData, $anyEnough) || ! self::numbersOk($title.' '.$hypothesis, $numbers)) {
                continue;
            }
            $experiments[] = ['title' => $title, 'hypothesis' => $hypothesis, 'metric' => self::line((string) ($row['metric'] ?? ''), 120),
                'duration_days' => max(14, min(56, (int) ($row['duration_days'] ?? 28)))];
        }

        return ['campaigns' => $campaigns, 'budget_split' => $split, 'experiments' => $experiments];
    }

    /**
     * Scales the rows' daily budgets down to the total when they exceed it (never up); two decimals.
     *
     * @template T of array{daily_budget: float}
     *
     * @param  list<T>  $rows
     * @return list<T>
     */
    private static function scaleBudgets(array $rows, float $total): array
    {
        $sum = array_sum(array_column($rows, 'daily_budget'));
        $factor = $sum > $total * 1.05 && $sum > 0 ? $total / $sum : 1.0;

        return array_map(function (array $row) use ($factor): array {
            $row['daily_budget'] = round($row['daily_budget'] * $factor, 2);

            return $row;
        }, $rows);
    }

    /* ------------------------------------------------------------------ ad texts */

    /**
     * Ad groups the ad text operation can target: existing ones (costliest first) and ad groups of open / approved
     * campaign drafts (`s<suggestion>:<index>`).
     *
     * @return list<array{key: string, label: string}>
     */
    public function adGroupOptions(DigitalAsset $asset): array
    {
        $ctx = $this->screen->context($asset);
        if ($ctx === null) {
            return [];
        }
        $names = $this->screen->names($ctx['scope']);
        $out = [];
        foreach ($names['ad_groups'] as $id => $group) {
            if (($group['status'] ?? null) !== 'REMOVED') {
                $out[] = ['key' => (string) $id, 'label' => ($names['campaigns'][$group['campaign_id']]['name'] ?? '—').' › '.$group['name']];
            }
        }
        foreach ($this->campaignDrafts($asset) as $draft) {
            foreach ((array) ($draft->action['ad_groups'] ?? []) as $i => $group) {
                $out[] = ['key' => 's'.$draft->id.':'.$i, 'label' => 'Taslak: '.$draft->action['name'].' › '.$group['name']];
            }
        }

        return $out;
    }

    public function writeAdTexts(DigitalAsset $asset, string $groupKey): string
    {
        $target = $this->adGroupTarget($asset, $groupKey) ?? throw new RuntimeException('Reklam grubu bulunamadı.');
        $brand = $asset->brand;
        $pages = $this->pages($brand);
        if ($pages === []) {
            throw new RuntimeException('Markanın site sayfası yok; açılış sayfası seçilemez.');
        }
        $services = $this->screen->services($asset, $target['keywords']);
        $pack = [
            'business' => $brand->name,
            'ad_group' => $target + ['service' => collect($services)->countBy()->sortDesc()->keys()->first() ?? ''],
            'areas' => $this->areas($brand),
            'pages' => $pages,
            'compliance' => $this->complianceRules($brand),
        ];
        [$raw, $versionId] = $this->call(self::OP_ADS, $pack);
        $ad = self::validateAdTexts($raw, $pack, fn (string $text): array => self::blockingHits($brand, $text));
        $this->suggestions->replaceGroup($asset, 'rsa:'.$groupKey, [[
            'key' => 'rsa:'.$groupKey, 'title' => 'Reklam metni: '.$target['name'], 'reason' => $ad['landing_reason'] !== '' ? $ad['landing_reason'] : 'Açılış sayfası: '.$ad['final_url'],
            'priority' => 2, 'prompt_version_id' => $versionId, 'action_type' => 'ads_rsa',
            'evidence' => [['kampanya' => $target['campaign'], 'reklam_grubu' => $target['name'], 'anahtar_kelimeler' => implode(', ', array_slice($target['keywords'], 0, 5)) ?: 'veri yok',
                'mevcut_url' => implode(', ', $target['final_urls']) ?: 'veri yok', 'yeni_url' => $ad['final_url']]],
            'action' => ['campaign' => $target['campaign'], 'ad_group' => $target['name'], 'headlines' => $ad['headlines'], 'descriptions' => $ad['descriptions'],
                'path1' => $ad['path1'], 'path2' => $ad['path2'], 'final_url' => $ad['final_url']],
        ]]);

        return count($ad['headlines']).' başlık, '.count($ad['descriptions']).' açıklama hazır.';
    }

    /**
     * Enforces the limits (headline ≤ 30, description ≤ 90, path ≤ 15 characters; over-long texts are dropped, never
     * cut), no links / phones, no numbers that are not in the data pack, no blocking sector-compliance hit, a final URL
     * from the brand's pages; at least 3 headlines and 2 descriptions must remain.
     *
     * @param  array<mixed>  $raw
     * @param  array<string, mixed>  $pack
     * @param  callable(string): list<string>  $compliance
     * @return array{headlines: list<string>, descriptions: list<string>, path1: string, path2: string, final_url: string, landing_reason: string}
     */
    public static function validateAdTexts(array $raw, array $pack, callable $compliance): array
    {
        $numbers = self::packNumbers($pack);
        $hits = [];
        $clean = function (array $texts, int $max, int $limit, bool $noBang) use ($numbers, $compliance, &$hits): array {
            $out = [];
            foreach ($texts as $text) {
                $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $text)) ?? '');
                if ($text === '' || mb_strlen($text) > $max || ($noBang && str_contains($text, '!')) || preg_match(self::CONTACT_PATTERN, $text) === 1
                    || ! self::numbersOk($text, $numbers, 0) || isset($out[mb_strtolower($text)])) {
                    continue;
                }
                $blocking = $compliance($text);
                if ($blocking !== []) {
                    $hits = [...$hits, ...$blocking];

                    continue;
                }
                $out[mb_strtolower($text)] = $text;
            }

            return array_slice(array_values($out), 0, $limit);
        };
        $headlines = $clean((array) ($raw['headlines'] ?? []), self::HEADLINE_MAX, GoogleAdsEditorCsv::HEADLINES, true);
        $descriptions = $clean((array) ($raw['descriptions'] ?? []), self::DESCRIPTION_MAX, GoogleAdsEditorCsv::DESCRIPTIONS, false);
        if (count($headlines) < 3 || count($descriptions) < 2) {
            throw new RuntimeException($hits !== []
                ? 'Reklam metni sektör uyum kuralına takıldı: '.implode(', ', array_unique($hits)).'. Tekrar deneyin.'
                : 'AI sınırlara uyan yeterli başlık / açıklama döndürmedi (başlık ≤ '.self::HEADLINE_MAX.', açıklama ≤ '.self::DESCRIPTION_MAX.' karakter); tekrar deneyin.');
        }
        $pages = [];
        foreach ($pack['pages'] as $page) {
            $pages[GoogleAdsChecks::normalizeUrl($page['url'])] = $page['url'];
        }
        $finalUrl = $pages[GoogleAdsChecks::normalizeUrl((string) ($raw['final_url'] ?? ''))] ?? null;
        foreach ((array) ($pack['ad_group']['final_urls'] ?? []) as $current) {
            $finalUrl ??= $pages[GoogleAdsChecks::normalizeUrl((string) $current)] ?? null;
        }
        if ($finalUrl === null) {
            throw new RuntimeException('AI sitede olmayan bir açılış sayfası önerdi; tekrar deneyin.');
        }
        $path = fn (mixed $value): string => ($p = mb_strtolower(preg_replace('/[^\p{L}\p{N}-]+/u', '', (string) $value) ?? '')) !== '' && mb_strlen($p) <= self::PATH_MAX ? $p : '';
        $reason = self::line((string) ($raw['landing_reason'] ?? ''));

        return ['headlines' => $headlines, 'descriptions' => $descriptions, 'path1' => $path($raw['path1'] ?? ''), 'path2' => $path($raw['path2'] ?? ''),
            'final_url' => $finalUrl, 'landing_reason' => self::numbersOk($reason, $numbers) ? $reason : ''];
    }

    /** @return array{key: string, campaign: string, name: string, keywords: list<string>, headlines: list<string>, descriptions: list<string>, final_urls: list<string>}|null */
    public function adGroupTarget(DigitalAsset $asset, string $key): ?array
    {
        if ($key === '') {
            return null;
        }
        if (preg_match('/^s(\d+):(\d+)$/', $key, $m) === 1) {
            $draft = $this->campaignDrafts($asset)->firstWhere('id', (int) $m[1]);
            $group = $draft?->action['ad_groups'][(int) $m[2]] ?? null;

            return is_array($group) ? ['key' => $key, 'campaign' => (string) $draft->action['name'], 'name' => (string) $group['name'],
                'keywords' => array_column((array) $group['keywords'], 'text'), 'headlines' => [], 'descriptions' => [], 'final_urls' => array_filter([(string) ($group['landing_url'] ?? '')])] : null;
        }
        $ctx = $this->screen->context($asset);
        if ($ctx === null || ! ctype_digit($key)) {
            return null;
        }
        $names = $this->screen->names($ctx['scope']);
        $group = $names['ad_groups'][$key] ?? null;
        if ($group === null) {
            return null;
        }
        $keywords = array_values(array_unique(array_filter(array_map(fn (array $k): string => $k['ad_group_id'] === $key && $k['status'] === 'ENABLED' ? $k['text'] : '',
            $this->screen->keywordTexts($ctx['scope'])))));
        $headlines = $descriptions = $urls = [];
        if (Schema::hasTable('google_ads_ad_daily')) {
            foreach ($ctx['scope']->professional('google_ads_ad_daily')->where('ad_group_id', $key)->orderByDesc('reporting_date')->limit(50)->pluck('metadata') as $meta) {
                $meta = GoogleAdsAdvisorInputCollector::decode($meta);
                $headlines = [...$headlines, ...(array) ($meta['headlines'] ?? [])];
                $descriptions = [...$descriptions, ...(array) ($meta['descriptions'] ?? [])];
                $urls = [...$urls, ...array_filter((array) ($meta['final_urls'] ?? []), 'is_string')];
            }
        }

        return ['key' => $key, 'campaign' => (string) ($names['campaigns'][$group['campaign_id']]['name'] ?? ''), 'name' => $group['name'], 'keywords' => array_slice($keywords, 0, 30),
            'headlines' => array_slice(array_values(array_unique($headlines)), 0, 15), 'descriptions' => array_slice(array_values(array_unique($descriptions)), 0, 4),
            'final_urls' => array_slice(array_values(array_unique($urls)), 0, 3)];
    }

    /** @return Collection<int, Suggestion> */
    private function campaignDrafts(DigitalAsset $asset): Collection
    {
        return Suggestion::query()->where('brand_id', (int) $asset->brand_id)->where('channel', GoogleAdsSuggestions::CHANNEL)
            ->where('target_type', GoogleAdsSuggestions::TARGET)->where('target_id', $asset->id)->where('action_type', 'ads_campaign')
            ->whereIn('status', [Suggestion::OPEN, Suggestion::APPROVED])->orderBy('id')->get();
    }

    /* ------------------------------------------------------------------ shared */

    /** @return list<string> «phrase» (rule) of the high / medium hits of the brand's sector rules */
    public static function blockingHits(?Brand $brand, string $text): array
    {
        $out = [];
        foreach (app(ComplianceAuditor::class)->checkForBrand($brand, $text, 'google_ads_ad') as $hit) {
            if (in_array($hit['rule']->severity, ['high', 'medium'], true)) {
                $out[] = '«'.$hit['matched'].'» ('.$hit['rule']->label.')';
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Every number the text quotes must be in the pack (rounding tolerated); small counts (≤ $small) are free.
     *
     * @param  list<float>  $numbers
     */
    public static function numbersOk(string $text, array $numbers, int $small = 10): bool
    {
        foreach (AnalystNumbers::extract($text) as $number) {
            if (abs($number) > $small && ! AnalystNumbers::inPack($number, $numbers)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<float> every number in the pack (values and numbers inside its strings) */
    public static function packNumbers(array $pack): array
    {
        $out = [];
        array_walk_recursive($pack, function (mixed $value) use (&$out): void {
            if (is_int($value) || is_float($value)) {
                $out[] = (float) $value;
            } elseif (is_string($value) && preg_match('/\d/', $value) === 1) {
                $out = [...$out, ...AnalystNumbers::extract($value)];
            }
        });

        return array_values(array_unique($out, SORT_REGULAR));
    }

    /** @param  list<string>  $lowData */
    private static function pausesLowData(string $text, array $lowData, bool $anyEnough): bool
    {
        if (preg_match(self::PAUSE_PATTERN, $text) !== 1) {
            return false;
        }
        if (! $anyEnough) {
            return true;
        }
        foreach ($lowData as $name) {
            if ($name !== '' && SeoText::containsPhrase($text, (string) $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: array<mixed>, 1: ?int} structured response and the prompt version used
     */
    private function call(string $operation, array $data): array
    {
        $class = self::OPERATIONS[$operation];
        $route = $this->routes->resolve($class::OPERATION);
        if ($route->isEmpty()) {
            throw new RuntimeException('Uygun AI sağlayıcısı yok ya da aylık AI bütçesi doldu.');
        }
        $this->runtime->prepare(array_keys($route->providerModels));
        $data = array_filter($data, fn (string $key): bool => ! str_starts_with($key, '_'), ARRAY_FILTER_USE_KEY);
        $agent = new $class;
        $response = (array) $agent->prompt("DATA_JSON\n".json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            provider: $route->providerModels, timeout: 180)->toArray();

        return [$response, $agent->promptVersionId()];
    }

    /** Planned daily budget (current budget plan / days) or the sum of the enabled campaigns' daily budgets (shared budgets once). */
    private function totalDailyBudget(DigitalAsset $asset): ?float
    {
        $plan = GoogleAdsBudgetPlan::query()->where('digital_asset_id', $asset->id)->where('period_start', '<=', now()->toDateString())
            ->where('period_end', '>=', now()->toDateString())->orderByDesc('period_start')->first();
        if ($plan !== null && (float) $plan->planned_budget > 0) {
            $days = max(1, (int) $plan->period_start->diffInDays($plan->period_end) + 1);

            return (float) $plan->planned_budget / $days;
        }
        $ctx = $this->screen->context($asset);
        if ($ctx === null) {
            return null;
        }
        $budgetIds = [];
        foreach ($ctx['scope']->snapshot('google_ads_campaign_snapshot')->pluck('metadata') as $meta) {
            $meta = GoogleAdsAdvisorInputCollector::decode($meta);
            if (($meta['status'] ?? null) === 'ENABLED' && filled($meta['budget_id'] ?? null)) {
                $budgetIds[(string) $meta['budget_id']] = true;
            }
        }
        $sum = 0.0;
        foreach ($ctx['scope']->snapshot('google_ads_campaign_budget_snapshot')->whereIn('budget_id', array_keys($budgetIds) ?: [''])->pluck('metadata') as $meta) {
            $sum += (float) (GoogleAdsAdvisorInputCollector::decode($meta)['amount'] ?? 0);
        }

        return $sum > 0 ? $sum : null;
    }

    /** @return list<array{name: string, priority: string}> approved (active) offerings, main first */
    private function offerings(?Brand $brand): array
    {
        if ($brand === null) {
            return [];
        }

        return BrandOffering::query()->with(['primaryName', 'catalogItem.primaryName'])->where('brand_id', $brand->id)->where('status', 'active')
            ->orderByRaw("CASE WHEN priority = 'main' THEN 0 ELSE 1 END")->orderBy('id')->get()
            ->map(fn (BrandOffering $o): array => ['name' => $o->displayName(), 'priority' => $o->priority === 'main' ? 'main' : 'secondary'])
            ->unique('name')->values()->all();
    }

    /** @return list<array{name: string, physical_branch: bool}> */
    private function areas(Brand $brand): array
    {
        return BrandServiceArea::query()->where('brand_id', $brand->id)->orderByDesc('physical_branch')->orderBy('id')->limit(30)->get()
            ->map(fn (BrandServiceArea $a): array => ['name' => $a->displayName(), 'physical_branch' => (bool) $a->physical_branch])->all();
    }

    /**
     * Faz 3 clusters of the brand's services (sector + service, approved first) with the brand's target URL — an input.
     *
     * @return list<array<string, mixed>>
     */
    private function clusters(Brand $brand): array
    {
        $services = BrandOffering::query()->where('brand_id', $brand->id)->where('status', 'active')->pluck('service_catalog_item_id')->filter()->all();
        if ($services === [] || $brand->sector_id === null) {
            return [];
        }
        $clusters = Cluster::query()->where('sector_id', $brand->sector_id)->whereIn('service_id', $services)
            ->orderByDesc('approved')->orderBy('id')->limit(60)->get();
        $queryIds = $clusters->flatMap(fn (Cluster $c): array => array_filter([$c->main_query_id, ...array_slice((array) $c->representative_query_ids, 0, 4)]))->unique()->all();
        $texts = $queryIds === [] ? [] : Query::query()->whereIn('id', $queryIds)->pluck('text', 'id')->all();
        $urls = BrandClusterPage::query()->where('brand_id', $brand->id)->whereIn('cluster_id', $clusters->pluck('id'))->with('page:id,url')->get()
            ->mapWithKeys(fn (BrandClusterPage $p): array => [(int) $p->cluster_id => (string) $p->page?->url])->all();
        $names = app(GoogleAdsScreen::class)->serviceNames((int) $brand->id, array_values(array_unique($clusters->pluck('service_id')->map(fn ($id): int => (int) $id)->all())));

        return $clusters->map(fn (Cluster $c): array => array_filter([
            'service' => $names[(int) $c->service_id] ?? null, 'need' => $c->name, 'intent' => $c->intent, 'main_query' => $texts[$c->main_query_id] ?? null,
            'queries' => array_values(array_filter(array_map(fn ($id): ?string => $texts[$id] ?? null, array_slice((array) $c->representative_query_ids, 0, 4)))),
            'page_type' => $c->page_type, 'target_url' => $urls[$c->id] ?? null,
        ], fn (mixed $v): bool => $v !== null && $v !== '' && $v !== []))->values()->all();
    }

    /** @return list<array{url: string, title: string, category: ?string, summary: string}> indexable pages of the brand's sites */
    private function pages(Brand $brand): array
    {
        $sites = DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'website')->select('id');

        return Page::query()->whereIn('website_asset_id', $sites)->where('is_indexable', true)
            ->where(fn (Builder $q) => $q->whereNull('category')->orWhereNotIn('category', ['blog']))
            ->orderByRaw("CASE WHEN category = 'hizmet' THEN 0 WHEN category = 'lokasyon' THEN 1 ELSE 2 END")->orderBy('id')->limit(150)->get(['url', 'title', 'path', 'category', 'content_summary'])
            ->map(fn (Page $p): array => ['url' => (string) $p->url, 'title' => (string) ($p->title ?: $p->path), 'category' => $p->category, 'summary' => mb_substr((string) $p->content_summary, 0, 300)])->all();
    }

    /** @return list<string> */
    private function complianceRules(Brand $brand): array
    {
        return app(SectorPackRegistry::class)->rulesForBrand($brand)->pluck('message')->unique()->values()->take(12)->all();
    }

    public static function matchLabel(string $matchType): string
    {
        return ['EXACT' => 'tam', 'PHRASE' => 'sıralı', 'BROAD' => 'geniş'][strtoupper($matchType)] ?? $matchType;
    }

    private static function line(string $text, int $max = 240): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? ''), 0, $max);
    }
}
