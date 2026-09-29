<?php

namespace App\Services\Meta;

use App\Ai\Agents\MetaCreativesAgent;
use App\Ai\Agents\MetaLandingAgent;
use App\Ai\Agents\MetaStructureAgent;
use App\Jobs\Meta\RunMetaAssistantJob;
use App\Models\Brand;
use App\Models\BrandServiceArea;
use App\Models\DigitalAsset;
use App\Models\MetaLead;
use App\Models\Page;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Services\Compliance\ComplianceAuditor;
use App\Services\Compliance\SectorPackRegistry;
use App\Services\SeoTasks\SeoText;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Faz 6 AI operations of the Meta screen, each one queued call on an operator click, operational brands only:
 *  - `meta.creatives`: per main service ad texts, headline, description, video hook, test variant;
 *  - `meta.structure`: campaign / ad set structure and remarketing;
 *  - `meta.landing`: lead form / landing page improvements.
 * Every item is validated against the data pack before it is stored as a suggestion: services / campaigns / ad sets /
 * targets must exist, every number (except small counts ≤ 10) and URL must be in the pack, and the sector compliance
 * gate must pass. Invalid items are dropped; nothing valid = the run fails and stores nothing. Nothing goes to Meta.
 */
final class MetaAssistant
{
    public const string OP_CREATIVES = 'creatives';

    public const string OP_STRUCTURE = 'structure';

    public const string OP_LANDING = 'landing';

    public const array OPERATIONS = [
        self::OP_CREATIVES => MetaCreativesAgent::class,
        self::OP_STRUCTURE => MetaStructureAgent::class,
        self::OP_LANDING => MetaLandingAgent::class,
    ];

    public const array LABELS = [self::OP_CREATIVES => 'Kreatif öner', self::OP_STRUCTURE => 'Kampanya yapısı öner', self::OP_LANDING => 'Form / açılış sayfası öner'];

    private const array GROUPS = [self::OP_CREATIVES => 'creative', self::OP_STRUCTURE => 'structure', self::OP_LANDING => 'landing'];

    public const int PRIMARY_MAX = 500;

    public const int HEADLINE_MAX = 40;

    public const int DESCRIPTION_MAX = 30;

    public function __construct(
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
        private readonly MetaScreen $screen,
        private readonly MetaSuggestions $suggestions,
        private readonly MetaLeads $leads,
    ) {}

    /** @throws ValidationException */
    public function queue(DigitalAsset $asset, string $operation): void
    {
        if (! isset(self::OPERATIONS[$operation])) {
            throw ValidationException::withMessages(['meta' => 'Bilinmeyen işlem.']);
        }
        $asset->loadMissing('brand.customer');
        if ($asset->brand === null || ! $asset->brand->isOperational()) {
            throw ValidationException::withMessages(['meta' => 'Marka operasyonel değil; AI çalışmaz.']);
        }
        if ($this->screen->account($asset) === null) {
            throw ValidationException::withMessages(['meta' => 'Meta reklam hesabı bağlı değil.']);
        }
        if ($this->routes->resolve(self::OPERATIONS[$operation]::OPERATION)->isEmpty()) {
            throw ValidationException::withMessages(['meta' => 'Uygun AI sağlayıcısı yok ya da aylık AI bütçesi doldu (Ayarlar → AI).']);
        }
        Cache::put(self::stateKey((int) $asset->id, $operation), ['status' => 'running'], now()->addMinutes(15));
        RunMetaAssistantJob::dispatch((int) $asset->id, $operation);
    }

    /** Executed by the job; the outcome is kept for the screen (running → ready | failed with a Turkish message). */
    public function run(int $assetId, string $operation): void
    {
        try {
            $asset = DigitalAsset::query()->with('brand.customer')->find($assetId);
            if ($asset === null || $asset->brand === null || ! $asset->brand->isOperational()) {
                throw new RuntimeException('Marka operasyonel değil; AI çalışmaz.');
            }
            $message = match ($operation) {
                self::OP_CREATIVES => $this->creatives($asset),
                self::OP_STRUCTURE => $this->structure($asset),
                self::OP_LANDING => $this->landing($asset),
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
        return 'meta-assistant:'.$assetId.':'.$operation;
    }

    /* ---------------- creatives ---------------- */

    public function creatives(DigitalAsset $asset): string
    {
        $pack = $this->creativesPack($asset);
        [$raw, $versionId] = $this->call(self::OP_CREATIVES, $pack);
        $items = self::validateCreatives($raw, $pack, $asset->brand);
        if ($items === []) {
            throw new RuntimeException('AI önerileri veriyle doğrulanamadı; tekrar deneyin.');
        }
        $rows = [];
        $perService = [];
        foreach ($items as $item) {
            $n = $perService[$item['service']] = ($perService[$item['service']] ?? 0) + 1;
            $creatives = array_filter($pack['creatives'], fn (array $c): bool => $c['service'] === $item['service']);
            $rows[] = ['key' => SeoText::fold($item['service']).':'.$n, 'title' => 'Kreatif: '.$item['service'].' · '.$item['angle'],
                'reason' => $item['test'] !== '' ? $item['test'] : 'Yeni kreatif varyantı.', 'priority' => $item['main'] ? 2 : 3,
                'evidence' => [['hizmet' => $item['service'], 'mevcut_reklam' => count($creatives), 'yorgun' => count(array_filter($creatives, fn (array $c): bool => $c['fatigued']))]],
                'action_type' => 'meta_creative', 'prompt_version_id' => $versionId,
                'action' => ['service' => $item['service'], 'angle' => $item['angle'], 'primary_text' => $item['primary_text'], 'headline' => $item['headline'],
                    'description' => $item['description'], 'video_hook' => $item['video_hook'], 'test' => $item['test'],
                    'text' => "Reklam metni: {$item['primary_text']}\nBaşlık: {$item['headline']}\nAçıklama: {$item['description']}\nVideo kancası: {$item['video_hook']}"]];
        }
        $this->suggestions->replaceGroup($asset, self::GROUPS[self::OP_CREATIVES], $rows);

        return count($rows).' kreatif önerisi.';
    }

    /** @return array<string, mixed> */
    public function creativesPack(DigitalAsset $asset): array
    {
        $brand = $asset->brand;
        $account = $this->account($asset);
        $offerings = $this->screen->offeringIndex($brand);
        if (array_filter($offerings, fn (array $o): bool => $o['priority'] === 'main') === []) {
            throw new RuntimeException('Markanın onaylı ana hizmeti yok (Marka › Ayarlar › Hizmetler).');
        }
        $services = $this->screen->campaignServices($brand, $this->screen->entities($account));
        $creatives = [];
        foreach (array_slice($this->screen->creatives($asset, 28), 0, 30) as $row) {
            if ($row['spend'] <= 0) {
                continue;
            }
            $campaignId = (string) $row['campaign_id'];
            $creatives[] = ['ad' => $row['name'], 'campaign' => $row['campaign'], 'service' => $services[$campaignId] ?? '', 'title' => $row['title'],
                'text' => mb_substr($row['body'], 0, 300), 'video' => $row['video'], 'spend' => $row['spend'], 'results' => $row['results'], 'ctr' => $row['ctr'],
                'frequency' => $row['frequency'], 'fatigued' => $row['fatigue'] !== null];
        }

        return [
            'brand' => $brand->name, 'services' => array_map(fn (array $o): array => ['name' => $o['name'], 'priority' => $o['priority']], $offerings),
            'areas' => $this->areas($brand), 'languages' => array_values(array_filter((array) ($brand->languages ?? []))),
            'creatives' => $creatives, 'pages' => $this->pages($brand), 'compliance' => $this->complianceRules($brand),
        ];
    }

    /**
     * @param  array<mixed>  $raw
     * @param  array<string, mixed>  $pack
     * @return list<array{service: string, main: bool, angle: string, primary_text: string, headline: string, description: string, video_hook: string, test: string}>
     */
    public static function validateCreatives(array $raw, array $pack, ?Brand $brand): array
    {
        $services = [];
        foreach ($pack['services'] as $s) {
            $services[SeoText::fold($s['name'])] = $s;
        }
        $known = self::packNumbers($pack);
        $urls = array_column($pack['pages'], 'url');
        $out = [];
        $perService = [];
        foreach ((array) ($raw['items'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $service = $services[SeoText::fold((string) ($row['service'] ?? ''))] ?? null;
            if ($service === null || ($perService[$service['name']] ?? 0) >= 2) {
                continue;
            }
            $item = [
                'service' => $service['name'], 'main' => $service['priority'] === 'main', 'angle' => self::line((string) ($row['angle'] ?? ''), 60),
                'primary_text' => self::cut(self::plain((string) ($row['primary_text'] ?? '')), self::PRIMARY_MAX),
                'headline' => self::cut(self::line((string) ($row['headline'] ?? ''), 200), self::HEADLINE_MAX),
                'description' => self::cut(self::line((string) ($row['description'] ?? ''), 200), self::DESCRIPTION_MAX),
                'video_hook' => self::line((string) ($row['video_hook'] ?? ''), 240), 'test' => self::line((string) ($row['test'] ?? ''), 240),
            ];
            if (mb_strlen($item['primary_text']) < 20 || $item['headline'] === '' || ! self::grounded(implode("\n", $item), $known, $urls) || self::blockingHits($brand, implode("\n", $item)) !== []) {
                continue;
            }
            $perService[$service['name']] = ($perService[$service['name']] ?? 0) + 1;
            $out[] = $item;
        }

        return array_slice($out, 0, 10);
    }

    /* ---------------- structure ---------------- */

    public function structure(DigitalAsset $asset): string
    {
        $pack = $this->structurePack($asset);
        [$raw, $versionId] = $this->call(self::OP_STRUCTURE, $pack);
        $items = self::validateStructure($raw, $pack);
        if ($items === []) {
            throw new RuntimeException('AI önerileri veriyle doğrulanamadı; tekrar deneyin.');
        }
        $rows = [];
        foreach ($items as $item) {
            $rows[] = ['key' => $item['kind'].':'.substr(hash('sha256', SeoText::fold($item['campaign'].'|'.$item['title'])), 0, 16), 'title' => $item['title'], 'reason' => $item['reason'],
                'priority' => $item['kind'] === 'remarketing' ? 3 : 2,
                'evidence' => [array_filter(['kampanya' => $item['campaign'], 'hizmet' => $item['service'], 'reklam_setleri' => implode(', ', $item['adsets'])])],
                'action_type' => 'meta_structure', 'prompt_version_id' => $versionId,
                'action' => $item + ['text' => $item['title']."\nKampanya: ".$item['campaign'].($item['adsets'] !== [] ? "\nReklam setleri: ".implode(', ', $item['adsets']) : '')."\n".$item['steps']]];
        }
        $this->suggestions->replaceGroup($asset, self::GROUPS[self::OP_STRUCTURE], $rows);

        return count($rows).' yapı önerisi.';
    }

    /** @return array<string, mixed> */
    public function structurePack(DigitalAsset $asset): array
    {
        $brand = $asset->brand;
        $account = $this->account($asset);
        $w = $this->screen->window($account, 28);
        $e = $this->screen->entities($account);
        $cur = $this->screen->adPerformance($account, $w['from'], $w['to'], $e);
        $prev = MetaScreen::rollup($this->screen->adPerformance($account, $w['prev_from'], $w['prev_to'], $e), 'campaign_id');
        $services = $this->screen->campaignServices($brand, $e);
        $campaigns = [];
        foreach (MetaScreen::rollup($cur, 'campaign_id') as $id => $row) {
            $c = MetaScreen::derive($row);
            $p = MetaScreen::derive($prev[$id] ?? ['spend' => 0.0, 'impressions' => 0, 'clicks' => 0, 'results' => 0.0]);
            $campaigns[] = ['name' => (string) ($e['campaigns'][$id]['name'] ?? $id), 'objective' => (string) ($e['campaigns'][$id]['objective'] ?? ''),
                'daily_budget' => $e['campaigns'][$id]['daily_budget'] ?? null, 'service' => $services[$id] ?? '', 'spend' => $c['spend'], 'results' => $c['results'],
                'cost_per_result' => $c['cpr'], 'ctr' => $c['ctr'], 'previous_spend' => $p['spend'], 'previous_results' => $p['results'],
                'spend_change_pct' => MetaScreen::change($c['spend'], $p['spend']), 'results_change_pct' => MetaScreen::change($c['results'], $p['results'])];
        }
        if ($campaigns === []) {
            throw new RuntimeException('Son 28 günde kampanya verisi yok.');
        }
        $total = MetaScreen::totals($cur);
        $adsets = [];
        foreach (MetaScreen::rollup($cur, 'adset_id') as $id => $row) {
            $a = $e['adsets'][$id] ?? null;
            $geo = (array) ($a['targeting']['geo_locations'] ?? []);
            $adsets[] = ['name' => (string) ($a['name'] ?? $id), 'campaign' => (string) ($e['campaigns'][$a['campaign_id'] ?? '']['name'] ?? ''),
                'optimization_goal' => (string) ($a['optimization_goal'] ?? ''), 'places' => collect(array_merge((array) ($geo['cities'] ?? []), (array) ($geo['regions'] ?? [])))->pluck('name')->filter()->take(10)->values()->all(),
                'countries' => array_values((array) ($geo['countries'] ?? [])), 'spend' => round($row['spend'], 2), 'results' => $row['results']];
        }
        usort($adsets, fn (array $x, array $y): int => $y['spend'] <=> $x['spend']);

        return [
            'brand' => $brand->name, 'window' => $w['from'].' – '.$w['to'], 'services' => array_map(fn (array $o): array => ['name' => $o['name'], 'priority' => $o['priority']], $this->screen->offeringIndex($brand)),
            'areas' => $this->areas($brand), 'account' => ['spend' => $total['spend'], 'results' => $total['results'], 'cost_per_result' => $total['cpr']], 'campaigns' => $campaigns, 'adsets' => array_slice($adsets, 0, 40), 'pixel' => $this->screen->pixel($account)['label'],
            'lead_marks' => $this->leads->byCampaign($asset),
        ];
    }

    /**
     * @param  array<mixed>  $raw
     * @param  array<string, mixed>  $pack
     * @return list<array{title: string, kind: string, service: string, campaign: string, adsets: list<string>, reason: string, steps: string}>
     */
    public static function validateStructure(array $raw, array $pack): array
    {
        $campaigns = collect($pack['campaigns'])->keyBy('name');
        $adsets = array_column($pack['adsets'], 'name');
        $services = array_column($pack['services'], 'name');
        $known = self::packNumbers($pack);
        $out = [];
        foreach ((array) ($raw['items'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $campaign = trim((string) ($row['campaign'] ?? ''));
            $names = array_values(array_filter(array_map(fn ($n): string => trim((string) $n), (array) ($row['adsets'] ?? []))));
            $service = trim((string) ($row['service'] ?? ''));
            $item = ['title' => self::line((string) ($row['title'] ?? ''), 120), 'kind' => ($row['kind'] ?? '') === 'remarketing' ? 'remarketing' : 'structure',
                'service' => in_array($service, $services, true) ? $service : '', 'campaign' => $campaign, 'adsets' => array_slice($names, 0, 8),
                'reason' => self::line((string) ($row['reason'] ?? ''), 240), 'steps' => mb_substr(self::plain((string) ($row['steps'] ?? '')), 0, 1200)];
            $isNew = fn (string $name): bool => str_starts_with(SeoText::fold($name), 'yeni ');
            $text = $item['title']."\n".$item['reason']."\n".$item['steps'];
            // Low data → no pause: the account needs enough results and the campaign ≥ 2× the account cost per result.
            $account = (array) ($pack['account'] ?? []);
            $pausesLowData = preg_match('/(kapat|durdur|duraklat)/iu', $text) === 1 && ((float) ($account['results'] ?? 0) < MetaChecks::MIN_ACCOUNT_RESULTS
                || ($campaigns->has($campaign) && (float) $campaigns[$campaign]['spend'] < 2 * (float) ($account['cost_per_result'] ?? 0)));
            if ($item['title'] === '' || $item['reason'] === '' || $item['steps'] === '' || $pausesLowData
                || ($campaign === '' || (! $campaigns->has($campaign) && ! $isNew($campaign)))
                || collect($names)->contains(fn (string $n): bool => ! in_array($n, $adsets, true) && ! $isNew($n))
                || ! self::grounded($text, $known, [])) {
                continue;
            }
            $out[] = $item;
        }

        return array_slice($out, 0, 6);
    }

    /* ---------------- landing ---------------- */

    public function landing(DigitalAsset $asset): string
    {
        $pack = $this->landingPack($asset);
        [$raw, $versionId] = $this->call(self::OP_LANDING, $pack);
        $items = self::validateLanding($raw, $pack, $asset->brand);
        if ($items === []) {
            throw new RuntimeException('AI önerileri veriyle doğrulanamadı; tekrar deneyin.');
        }
        $rows = [];
        foreach ($items as $item) {
            $isForm = str_starts_with($item['target'], 'form:');
            $rows[] = ['key' => substr(hash('sha256', $item['target'].'|'.SeoText::fold($item['problem'])), 0, 20),
                'title' => ($isForm ? 'Form: ' : 'Sayfa: ').($isForm ? substr($item['target'], 5) : (parse_url($item['target'], PHP_URL_PATH) ?: $item['target'])),
                'reason' => $item['problem'], 'priority' => 2, 'evidence' => [['hedef' => $item['target'], 'neden' => $item['reason']]],
                'action_type' => 'meta_landing', 'prompt_version_id' => $versionId,
                'action' => $item + ['text' => $item['target']."\n".$item['change']]];
        }
        $this->suggestions->replaceGroup($asset, self::GROUPS[self::OP_LANDING], $rows);

        return count($rows).' form / sayfa önerisi.';
    }

    /** @return array<string, mixed> */
    public function landingPack(DigitalAsset $asset): array
    {
        $brand = $asset->brand;
        $account = $this->account($asset);
        $w = $this->screen->window($account, 28);
        $e = $this->screen->entities($account);
        $landings = [];
        $forms = [];
        foreach ($this->screen->adPerformance($account, $w['from'], $w['to'], $e) as $id => $row) {
            $creative = $e['creatives'][(string) ($e['ads'][$id]['creative_id'] ?? '')] ?? null;
            if ($creative === null || $row['spend'] <= 0) {
                continue;
            }
            if ($creative['lead_gen_form_id'] !== '') {
                $key = $creative['lead_gen_form_id'];
                $forms[$key] ??= ['form' => 'form:'.$key, 'spend' => 0.0, 'results' => 0.0];
                $forms[$key]['spend'] += $row['spend'];
                $forms[$key]['results'] += $row['results'];
            } elseif (str_starts_with($creative['link_url'], 'http')) {
                $key = MetaChecks::normalizeUrl($creative['link_url']);
                $landings[$key] ??= ['url' => explode('?', $creative['link_url'])[0], 'spend' => 0.0, 'results' => 0.0];
                $landings[$key]['spend'] += $row['spend'];
                $landings[$key]['results'] += $row['results'];
            }
        }
        if ($landings === [] && $forms === []) {
            throw new RuntimeException('Son 28 günde form ya da açılış sayfasına giden reklam yok.');
        }
        $sites = DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'website')->pluck('id');
        $pages = Page::query()->whereIn('website_asset_id', $sites)->get(['url', 'title', 'h1', 'content_summary', 'content_text'])
            ->keyBy(fn (Page $p): string => MetaChecks::normalizeUrl((string) $p->url));
        $ga4 = collect($this->screen->ga4($brand, $w['from'], $w['to'])['landing'])->keyBy(fn (array $r): string => rtrim((string) parse_url($r['page'], PHP_URL_PATH), '/') ?: '/');
        foreach ($landings as $key => $landing) {
            $page = $pages->get($key);
            $path = rtrim((string) parse_url($landing['url'], PHP_URL_PATH), '/') ?: '/';
            $landings[$key] += [
                'page_title' => $page !== null ? (string) ($page->title ?: $page->h1) : 'veri yok',
                'page_summary' => $page !== null ? mb_substr((string) ($page->content_summary ?: $page->content_text), 0, 1200) : 'veri yok',
                'ga4_sessions' => $ga4[$path]['sessions'] ?? null, 'ga4_key_events' => $ga4[$path]['key_events'] ?? null,
            ];
            $landings[$key]['spend'] = round($landings[$key]['spend'], 2);
        }
        foreach ($forms as $key => $form) {
            $marks = MetaLead::query()->where('digital_asset_id', $asset->id)->where('form_id', $key)->selectRaw('mark, count(*) as n')->groupBy('mark')->pluck('n', 'mark');
            $forms[$key]['spend'] = round($form['spend'], 2);
            $forms[$key]['leads_imported'] = (int) $marks->sum();
            foreach (array_keys(MetaLead::MARKS) as $mark) {
                $forms[$key][$mark] = (int) ($marks[$mark] ?? 0);
            }
        }

        return ['brand' => $brand->name, 'window' => $w['from'].' – '.$w['to'], 'landings' => array_values($landings), 'forms' => array_values($forms), 'compliance' => $this->complianceRules($brand)];
    }

    /**
     * @param  array<mixed>  $raw
     * @param  array<string, mixed>  $pack
     * @return list<array{target: string, problem: string, change: string, reason: string}>
     */
    public static function validateLanding(array $raw, array $pack, ?Brand $brand): array
    {
        $targets = array_merge(array_column($pack['landings'], 'url'), array_column($pack['forms'], 'form'));
        $known = self::packNumbers($pack);
        $out = [];
        foreach ((array) ($raw['items'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $item = ['target' => trim((string) ($row['target'] ?? '')), 'problem' => self::line((string) ($row['problem'] ?? ''), 240),
                'change' => mb_substr(self::plain((string) ($row['change'] ?? '')), 0, 800), 'reason' => self::line((string) ($row['reason'] ?? ''), 240)];
            $text = $item['problem']."\n".$item['change']."\n".$item['reason'];
            if (! in_array($item['target'], $targets, true) || $item['problem'] === '' || $item['change'] === ''
                || ! self::grounded($text, $known, $targets) || self::blockingHits($brand, $item['change']) !== []) {
                continue;
            }
            $out[] = $item;
        }

        return array_slice($out, 0, 6);
    }

    /* ---------------- validation helpers ---------------- */

    /**
     * Every URL must be one of `$urls` and every number (small counts ≤ 10 aside) must occur in the data pack.
     *
     * @param  list<float>  $known
     * @param  list<string>  $urls
     */
    public static function grounded(string $text, array $known, array $urls): bool
    {
        preg_match_all('~https?://[^\s,;)"\']+~iu', $text, $found);
        foreach ($found[0] as $url) {
            if (! in_array(rtrim($url, '.!?:'), $urls, true)) {
                return false;
            }
        }
        $text = preg_replace('~https?://[^\s,;)"\']+~iu', ' ', $text) ?? '';
        preg_match_all('/\d+(?:[.,]\d+)*/u', $text, $numbers);
        foreach ($numbers[0] as $token) {
            $value = self::number($token);
            if ($value === null || ($value <= 10 && floor($value) === $value)) {
                continue;
            }
            $match = false;
            foreach ($known as $k) {
                if (abs($k - $value) < 0.01 || abs(round($k) - $value) < 0.01 || abs(round($k, 1) - $value) < 0.01 || abs(abs($k) - $value) < 0.01) {
                    $match = true;
                    break;
                }
            }
            if (! $match) {
                return false;
            }
        }

        return true;
    }

    /** @return list<float> every number in the data pack (values and numbers inside names) */
    public static function packNumbers(array $pack): array
    {
        preg_match_all('/-?\d+(?:\.\d+)?/', json_encode($pack, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '', $m);

        return array_values(array_unique(array_map('floatval', $m[0])));
    }

    /** Turkish number text ("1.250", "12,5", "3.4") → float. */
    private static function number(string $token): ?float
    {
        if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $token) === 1) {
            return (float) str_replace(['.', ','], ['', '.'], $token);
        }
        if (substr_count($token, ',') === 1 && ! str_contains($token, '.')) {
            return (float) str_replace(',', '.', $token);
        }

        return is_numeric($token) ? (float) $token : null;
    }

    /** @return list<string> «phrase» (rule) of the high / medium hits of the brand's sector rules */
    public static function blockingHits(?Brand $brand, string $text): array
    {
        $out = [];
        foreach (app(ComplianceAuditor::class)->checkForBrand($brand, $text, 'meta_ad') as $hit) {
            if (in_array($hit['rule']->severity, ['high', 'medium'], true)) {
                $out[] = '«'.$hit['matched'].'» ('.$hit['rule']->label.')';
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: array<mixed>, 1: ?int}
     */
    private function call(string $operation, array $data): array
    {
        $class = self::OPERATIONS[$operation];
        $route = $this->routes->resolve($class::OPERATION);
        if ($route->isEmpty()) {
            throw new RuntimeException('Uygun AI sağlayıcısı yok ya da aylık AI bütçesi doldu.');
        }
        $this->runtime->prepare(array_keys($route->providerModels));
        $agent = new $class;
        $response = (array) $agent->prompt("DATA_JSON\n".json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            provider: $route->providerModels, timeout: 180)->toArray();

        return [$response, $agent->promptVersionId()];
    }

    /** @return array<string, mixed> */
    private function account(DigitalAsset $asset): array
    {
        return $this->screen->account($asset) ?? throw new RuntimeException('Meta reklam hesabı bağlı değil.');
    }

    /** @return list<array{name: string, physical_branch: bool}> */
    private function areas(Brand $brand): array
    {
        return BrandServiceArea::query()->where('brand_id', $brand->id)->orderByDesc('physical_branch')->orderBy('id')->limit(30)->get()
            ->map(fn (BrandServiceArea $a): array => ['name' => $a->displayName(), 'physical_branch' => (bool) $a->physical_branch])->all();
    }

    /** @return list<array{title: string, url: string}> service pages of the brand's site(s) */
    private function pages(Brand $brand): array
    {
        $sites = DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'website')->pluck('id');

        return Page::query()->whereIn('website_asset_id', $sites)->where('is_indexable', true)
            ->where(fn ($q) => $q->where('category', 'hizmet')->orWhereNull('category'))->orderBy('id')->limit(40)->get(['title', 'path', 'url'])
            ->map(fn (Page $p): array => ['title' => (string) ($p->title ?: $p->path), 'url' => (string) $p->url])->all();
    }

    /** @return list<string> */
    private function complianceRules(Brand $brand): array
    {
        return app(SectorPackRegistry::class)->rulesForBrand($brand)->pluck('message')->unique()->values()->take(12)->all();
    }

    private static function plain(string $text): string
    {
        return trim(preg_replace("/[ \t]+/u", ' ', strip_tags($text)) ?? '');
    }

    private static function line(string $text, int $max): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? ''), 0, $max);
    }

    /** Shortens to the limit at a word boundary. */
    private static function cut(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space !== false && $space > $max / 2 ? mb_substr($cut, 0, $space) : $cut, ' ,;:-');
    }
}
