<?php

namespace App\Services\Meta;

use App\Ai\Agents\MetaCampaignServicesAgent;
use App\Models\AdCampaignService;
use App\Models\Brand;
use App\Models\BrandOffering;
use App\Models\BrandOfferingName;
use App\Models\DigitalAsset;
use App\Models\OfferingPage;
use App\Models\Page;
use App\Models\User;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Queries\QueryServiceMatcher;
use App\Services\SeoTasks\SeoText;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Kampanya → hizmet (Meta): the brand services each campaign of a bound ad account serves, kept in
 * ad_campaign_services. Evidence order: the site page the ads open (its service, OfferingPage) → the ad texts (title,
 * body) → the campaign / ad set / ad names; the first level that finds a service wins. Campaigns no rule matches go
 * to AI in one call per account (Claude queue). Rule and AI rows are suggestions; the operator's confirm / remove /
 * "hizmet dışı" (decided_by set) is never touched again by a pass. A campaign with several services splits its ads'
 * spend and results by the ads that name one of them (adShares()).
 *
 * @phpstan-type Offering array{id: int, name: string, names: list<string>, service_id: ?int, main: bool}
 * @phpstan-type Assigned array{id: int, name: string, status: string, source: string, reason: string}
 * @phpstan-type CampaignMap array{state: string, services: list<Assigned>}
 */
final class MetaCampaignServices
{
    public const string CHANNEL = 'meta';

    /** States of a campaign: an operator-confirmed service, only suggestions, "hizmet dışı", nothing. */
    public const string STATE_CONFIRMED = 'confirmed';

    public const string STATE_SUGGESTED = 'suggested';

    public const string STATE_EXCLUDED = 'excluded';

    public const string STATE_NONE = 'none';

    /** At most this many campaigns go to AI in one call. */
    public const int AI_BATCH = 60;

    /** @var array<int, list<Offering>> brand id => offerings */
    private array $offerings = [];

    public function __construct(
        private readonly MetaScreen $screen,
        private readonly QueryServiceMatcher $matcher,
        private readonly AiTaskQueue $tasks,
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
    ) {}

    public static function stateKey(int $assetId): string
    {
        return 'meta-campaign-services-ai:'.$assetId;
    }

    /**
     * The brand's active services with every active name (the brand's own names and the catalog's primary name).
     *
     * @return list<Offering> main services first
     */
    public function offerings(Brand $brand): array
    {
        if (isset($this->offerings[(int) $brand->id])) {
            return $this->offerings[(int) $brand->id];
        }
        $rows = BrandOffering::query()->with(['primaryName', 'catalogItem.primaryName'])->where('brand_id', $brand->id)->where('status', 'active')
            ->orderByRaw("CASE WHEN priority = 'main' THEN 0 ELSE 1 END")->orderBy('id')->get();
        $names = BrandOfferingName::query()->whereIn('brand_offering_id', $rows->pluck('id'))->where('is_active', true)->get(['brand_offering_id', 'raw_label'])
            ->groupBy('brand_offering_id');
        $out = [];
        foreach ($rows as $offering) {
            $all = array_merge([$offering->displayName(), (string) ($offering->catalogItem?->primaryName?->raw_label ?? '')],
                ($names[$offering->id] ?? collect())->pluck('raw_label')->map(fn ($n): string => (string) $n)->all());
            $out[] = ['id' => (int) $offering->id, 'name' => $offering->displayName(), 'names' => array_values(array_unique(array_filter(array_map('trim', $all)))),
                'service_id' => $offering->service_catalog_item_id !== null ? (int) $offering->service_catalog_item_id : null, 'main' => $offering->isMain()];
        }

        return $this->offerings[(int) $brand->id] = $out;
    }

    /**
     * Rule pass over every campaign of the account: undecided campaigns get the suggestions of the first evidence level
     * that matches (earlier rule suggestions are replaced; an AI suggestion stays only while no rule matches).
     *
     * @return array{campaigns: int, matched: int, unmatched: list<string>}
     */
    public function sync(DigitalAsset $asset): array
    {
        $account = $this->screen->account($asset);
        $brand = $asset->brand;
        if ($account === null || $brand === null) {
            return ['campaigns' => 0, 'matched' => 0, 'unmatched' => []];
        }
        $entities = $this->screen->entities($account);
        $offerings = $this->offerings($brand);
        $rows = $this->rows($asset)->groupBy('campaign_id');
        $pages = $this->pageIndex($brand);
        $matched = 0;
        $unmatched = [];
        foreach ($this->campaignTexts($entities) as $campaignId => $texts) {
            $existing = $rows[$campaignId] ?? collect();
            if ($existing->contains(fn (AdCampaignService $row): bool => $row->isOperators())) {
                continue;
            }
            $match = $offerings === [] ? null : $this->ruleMatch($texts, $offerings, $pages, $brand->sector_id !== null ? (int) $brand->sector_id : null);
            DB::transaction(function () use ($asset, $campaignId, $existing, $match): void {
                if ($match === null) {
                    AdCampaignService::query()->whereKey($existing->where('source', '!=', 'ai')->pluck('id'))->delete();

                    return;
                }
                AdCampaignService::query()->whereKey($existing->pluck('id'))->delete();
                foreach ($match['offerings'] as $offeringId => $reason) {
                    AdCampaignService::query()->create(['channel' => self::CHANNEL, 'digital_asset_id' => $asset->id, 'brand_id' => $asset->brand_id,
                        'campaign_id' => (string) $campaignId, 'brand_offering_id' => $offeringId, 'status' => AdCampaignService::SUGGESTED,
                        'source' => $match['source'], 'reason' => mb_substr($reason, 0, 500)]);
                }
            });
            if ($match !== null) {
                $matched++;
            } elseif (! $existing->contains(fn (AdCampaignService $row): bool => $row->source === 'ai')) {
                $unmatched[] = (string) $campaignId;
            }
        }

        return ['campaigns' => count($entities['campaigns']), 'matched' => $matched, 'unmatched' => $unmatched];
    }

    /**
     * Every campaign's services: confirmed ones when the operator decided, else the suggestions.
     *
     * @return array<string, CampaignMap> campaign id => state and services
     */
    public function map(DigitalAsset $asset): array
    {
        $names = [];
        if ($asset->brand !== null) {
            foreach ($this->offerings($asset->brand) as $offering) {
                $names[$offering['id']] = $offering['name'];
            }
        }
        $out = [];
        foreach ($this->rows($asset)->groupBy('campaign_id') as $campaignId => $rows) {
            if ($rows->contains('status', AdCampaignService::EXCLUDED)) {
                $out[(string) $campaignId] = ['state' => self::STATE_EXCLUDED, 'services' => []];

                continue;
            }
            // A decided campaign shows its confirmed services and the suggestions the operator has not answered yet.
            $keep = $rows->whereIn('status', [AdCampaignService::CONFIRMED, AdCampaignService::SUGGESTED])
                ->filter(fn (AdCampaignService $row): bool => isset($names[(int) $row->brand_offering_id]))
                ->sortBy(fn (AdCampaignService $row): int => $row->status === AdCampaignService::CONFIRMED ? 0 : 1);
            $services = $keep->map(fn (AdCampaignService $row): array => ['id' => (int) $row->brand_offering_id, 'name' => $names[(int) $row->brand_offering_id],
                'status' => (string) $row->status, 'source' => (string) $row->source, 'reason' => (string) $row->reason])->values()->all();
            $confirmed = $keep->contains('status', AdCampaignService::CONFIRMED);
            $out[(string) $campaignId] = ['state' => $services === [] ? self::STATE_NONE : ($confirmed ? self::STATE_CONFIRMED : self::STATE_SUGGESTED), 'services' => $services];
        }

        return $out;
    }

    /**
     * The operator confirms a service for the campaign (a suggestion or a new one); the campaign is decided from now on.
     * $replace ("Başka hizmet") drops the campaign's other suggestions; otherwise they stay open beside it.
     */
    public function confirm(DigitalAsset $asset, string $campaignId, int $offeringId, User $user, bool $replace = false): void
    {
        $offering = $this->ownOffering($asset, $offeringId);
        DB::transaction(function () use ($asset, $campaignId, $offering, $user, $replace): void {
            $rows = $this->rows($asset)->where('campaign_id', $campaignId);
            $drop = $rows->where('status', AdCampaignService::EXCLUDED);
            if ($replace) {
                $drop = $drop->merge($rows->filter(fn (AdCampaignService $row): bool => ! $row->isOperators() && (int) $row->brand_offering_id !== $offering->id));
            }
            AdCampaignService::query()->whereKey($drop->pluck('id'))->delete();
            $this->decide($asset, $campaignId, $offering->id, AdCampaignService::CONFIRMED, $user);
        });
    }

    /** The operator removes a service from the campaign (kept as "removed" so no pass adds it back). */
    public function remove(DigitalAsset $asset, string $campaignId, int $offeringId, User $user): void
    {
        $offering = $this->ownOffering($asset, $offeringId);
        $this->decide($asset, $campaignId, $offering->id, AdCampaignService::REMOVED, $user);
    }

    /** "Hizmet dışı": the campaign advertises no single service (awareness, the whole brand…). */
    public function exclude(DigitalAsset $asset, string $campaignId, User $user): void
    {
        DB::transaction(function () use ($asset, $campaignId, $user): void {
            AdCampaignService::query()->whereKey($this->rows($asset)->where('campaign_id', $campaignId)->pluck('id'))->delete();
            AdCampaignService::query()->create(['channel' => self::CHANNEL, 'digital_asset_id' => $asset->id, 'brand_id' => $asset->brand_id, 'campaign_id' => $campaignId,
                'brand_offering_id' => null, 'status' => AdCampaignService::EXCLUDED, 'source' => 'operator', 'reason' => null, 'decided_by' => $user->id, 'decided_at' => now()]);
        });
    }

    /** Undo: the operator's decision is dropped and the rules run again for the account. */
    public function reopen(DigitalAsset $asset, string $campaignId): void
    {
        AdCampaignService::query()->whereKey($this->rows($asset)->where('campaign_id', $campaignId)->pluck('id'))->delete();
        $this->sync($asset);
    }

    /**
     * Bulk "öneriyi onayla": every suggestion of the given undecided campaigns becomes confirmed.
     *
     * @param  list<string>  $campaignIds
     * @return int campaigns confirmed
     */
    public function confirmSuggestions(DigitalAsset $asset, array $campaignIds, User $user): int
    {
        $done = 0;
        foreach ($this->map($asset) as $campaignId => $entry) {
            if ($entry['state'] !== self::STATE_SUGGESTED || ! in_array((string) $campaignId, $campaignIds, true)) {
                continue;
            }
            DB::transaction(function () use ($asset, $campaignId, $user): void {
                $this->settleSuggestions($asset, (string) $campaignId, $user);
            });
            $done++;
        }

        return $done;
    }

    /**
     * How each ad's spend and results split over its campaign's services: an ad whose page, text or name names some of
     * them goes to those, else evenly to all of them. Campaigns without services are left out.
     *
     * @param  array<string, CampaignMap>  $map
     * @return array<string, array<int, float>> ad id => offering id => share (sums to 1)
     */
    public function adShares(DigitalAsset $asset, array $entities, array $map): array
    {
        $brand = $asset->brand;
        $offerings = $brand !== null ? array_column($this->offerings($brand), null, 'id') : [];
        $pages = $brand !== null ? $this->pageIndex($brand) : [];
        $sector = $brand?->sector_id !== null ? (int) $brand->sector_id : null;
        $out = [];
        foreach ($entities['ads'] as $adId => $ad) {
            $services = array_column($map[$ad['campaign_id']]['services'] ?? [], 'id');
            if ($services === []) {
                continue;
            }
            $named = [];
            if (count($services) > 1) {
                $creative = $entities['creatives'][$ad['creative_id']] ?? [];
                $own = array_values(array_intersect_key($offerings, array_flip($services)));
                $match = $this->ruleMatch(['links' => [(string) ($creative['link_url'] ?? '')], 'texts' => [(string) ($creative['title'] ?? '').' . '.(string) ($creative['body'] ?? '')],
                    'names' => [(string) $ad['name']]], $own, $pages, $sector);
                $named = array_keys($match['offerings'] ?? []);
            }
            $targets = $named !== [] ? $named : $services;
            foreach ($targets as $offeringId) {
                $out[(string) $adId][(int) $offeringId] = 1 / count($targets);
            }
        }

        return $out;
    }

    /* ---------------- AI (campaigns no rule matched) ---------------- */

    /**
     * Asks AI for the undecided campaigns that have no suggestion yet (one call per account, Claude queue). Returns null
     * while the call waits for Claude.
     *
     * @return array{asked: int, suggested: int}|null
     */
    public function runAi(DigitalAsset $asset): ?array
    {
        $brand = $asset->brand;
        $account = $this->screen->account($asset);
        if ($brand === null || $account === null) {
            return ['asked' => 0, 'suggested' => 0];
        }
        $offerings = $this->offerings($brand);
        $map = $this->map($asset);
        $entities = $this->screen->entities($account);
        $texts = $this->campaignTexts($entities);
        $campaigns = [];
        foreach ($entities['campaigns'] as $id => $campaign) {
            if (isset($map[$id]) || count($campaigns) >= self::AI_BATCH) {
                continue;
            }
            $ads = [];
            foreach ($entities['ads'] as $ad) {
                if ($ad['campaign_id'] !== (string) $id || count($ads) >= 4) {
                    continue;
                }
                $creative = $entities['creatives'][$ad['creative_id']] ?? [];
                $ads[] = ['name' => $ad['name'], 'title' => (string) ($creative['title'] ?? ''), 'body' => mb_substr((string) ($creative['body'] ?? ''), 0, 400),
                    'link_path' => ($creative['link_url'] ?? '') !== '' ? SeoText::urlPath((string) $creative['link_url']) : ''];
            }
            $campaigns[] = ['campaign_id' => (string) $id, 'name' => $campaign['name'], 'objective' => MetaScreen::objectiveLabel($campaign['objective']),
                'adsets' => array_values(array_unique(array_filter(array_slice($texts[$id]['adsets'] ?? [], 0, 6)))), 'ads' => $ads];
        }
        if ($campaigns === [] || $offerings === []) {
            return ['asked' => count($campaigns), 'suggested' => 0];
        }
        $answer = $this->ask(['offerings' => array_map(fn (array $o): array => ['id' => $o['id'], 'name' => $o['name']], $offerings), 'campaigns' => $campaigns]);
        if ($answer === null) {
            return null;
        }
        $known = array_column($offerings, 'name', 'id');
        $asked = array_flip(array_column($campaigns, 'campaign_id'));
        $suggested = 0;
        foreach ((array) ($answer['matches'] ?? []) as $row) {
            $campaignId = (string) ($row['campaign_id'] ?? '');
            $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($row['offering_ids'] ?? [])), fn (int $id): bool => isset($known[$id]))));
            if (! isset($asked[$campaignId]) || $ids === []) {
                continue;
            }
            unset($asked[$campaignId]);
            foreach ($ids as $offeringId) {
                AdCampaignService::query()->create(['channel' => self::CHANNEL, 'digital_asset_id' => $asset->id, 'brand_id' => $asset->brand_id, 'campaign_id' => $campaignId,
                    'brand_offering_id' => $offeringId, 'status' => AdCampaignService::SUGGESTED, 'source' => 'ai', 'reason' => mb_substr(trim((string) ($row['reason'] ?? '')), 0, 500) ?: null]);
            }
            $suggested++;
        }

        return ['asked' => count($campaigns), 'suggested' => $suggested];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null null while Claude has not answered
     */
    private function ask(array $data): ?array
    {
        $agent = new MetaCampaignServicesAgent;
        $answer = $this->tasks->delegatedCall($agent, $data);
        if ($answer === 'queued') {
            return null;
        }
        if ($answer === 'error') {
            throw new RuntimeException('Claude kampanyaları eşleştiremedi.');
        }
        if (is_array($answer)) {
            return $answer;
        }
        $route = $this->routes->resolve(MetaCampaignServicesAgent::OPERATION);
        if ($route->isEmpty()) {
            throw new RuntimeException('Uygun AI sağlayıcısı yok ya da aylık AI bütçesi doldu.');
        }
        $this->runtime->prepare(array_keys($route->providerModels));

        return (array) $agent->prompt("DATA_JSON\n".json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
            provider: $route->providerModels, timeout: 180)->toArray();
    }

    /* ---------------- rules ---------------- */

    /**
     * Texts of every campaign by evidence level.
     *
     * @return array<string, array{links: list<string>, texts: list<string>, names: list<string>, adsets: list<string>}>
     */
    private function campaignTexts(array $entities): array
    {
        $out = [];
        foreach ($entities['campaigns'] as $id => $campaign) {
            $out[(string) $id] = ['links' => [], 'texts' => [], 'names' => [(string) $campaign['name']], 'adsets' => []];
        }
        foreach ($entities['adsets'] as $adset) {
            if (isset($out[$adset['campaign_id']])) {
                $out[$adset['campaign_id']]['names'][] = (string) $adset['name'];
                $out[$adset['campaign_id']]['adsets'][] = (string) $adset['name'];
            }
        }
        foreach ($entities['ads'] as $ad) {
            if (! isset($out[$ad['campaign_id']])) {
                continue;
            }
            $creative = $entities['creatives'][$ad['creative_id']] ?? [];
            $out[$ad['campaign_id']]['names'][] = (string) $ad['name'];
            $out[$ad['campaign_id']]['links'][] = (string) ($creative['link_url'] ?? '');
            $out[$ad['campaign_id']]['texts'][] = trim((string) ($creative['title'] ?? '').' . '.(string) ($creative['body'] ?? ''), ' .');
        }

        return $out;
    }

    /**
     * The first evidence level that names a service: page → text → names.
     *
     * @param  array{links: list<string>, texts: list<string>, names: list<string>}  $texts
     * @param  list<Offering>  $offerings
     * @param  array{hosts: array<string, true>, paths: array<string, array{path: string, offerings: list<int>}>}  $pages
     * @return array{source: string, offerings: array<int, string>}|null offering id => reason
     */
    private function ruleMatch(array $texts, array $offerings, array $pages, ?int $sectorId): ?array
    {
        if ($offerings === []) {
            return null;
        }
        $byId = array_column($offerings, null, 'id');
        $found = [];
        foreach (array_unique(array_filter($texts['links'])) as $link) {
            $host = preg_replace('/^www\./', '', mb_strtolower((string) parse_url($link, PHP_URL_HOST)));
            $key = self::pathKey($link);
            if (! isset($pages['hosts'][$host]) || ! isset($pages['paths'][$key])) {
                continue;
            }
            foreach ($pages['paths'][$key]['offerings'] as $offeringId) {
                if (isset($byId[$offeringId])) {
                    $found[$offeringId] ??= 'Reklam '.$pages['paths'][$key]['path'].' sayfasına gidiyor; sayfanın hizmeti '.$byId[$offeringId]['name'].'.';
                }
            }
        }
        if ($found !== []) {
            return ['source' => 'page', 'offerings' => $found];
        }
        foreach (['text' => ['texts', 'Reklam metninde'], 'name' => ['names', 'Kampanya / reklam adında']] as $source => [$field, $where]) {
            foreach (array_unique(array_filter($texts[$field])) as $text) {
                foreach ($offerings as $offering) {
                    foreach ($offering['names'] as $name) {
                        if (mb_strlen($name) >= 3 && SeoText::containsPhrase($text, $name)) {
                            $found[$offering['id']] ??= $where.' “'.$name.'” geçiyor.';

                            break;
                        }
                    }
                }
                $hit = $this->matcher->matchWithKeyword($text, $sectorId);
                if ($hit['service'] !== null) {
                    foreach ($offerings as $offering) {
                        if ($offering['service_id'] === $hit['service']) {
                            $found[$offering['id']] ??= $where.' “'.$hit['keyword'].'” geçiyor.';
                        }
                    }
                }
            }
            if ($found === [] && $source === 'name') {
                // Short ad naming ("GURBETÇİ İMPLANT RS"): the distinctive word of a service name ("İmplant" of
                // "İmplant Tedavisi") when only one service has it.
                $cores = [];
                foreach ($offerings as $offering) {
                    foreach ($offering['names'] as $name) {
                        $core = self::core($name);
                        if ($core !== null) {
                            $cores[$core][$offering['id']] = $name;
                        }
                    }
                }
                foreach (array_unique(array_filter($texts['names'])) as $text) {
                    foreach ($cores as $core => $owners) {
                        if (count($owners) === 1 && SeoText::containsPhrase($text, $core)) {
                            $found[(int) array_key_first($owners)] ??= 'Kampanya / reklam adında “'.$core.'” geçiyor ('.reset($owners).').';
                        }
                    }
                }
            }
            if ($found !== []) {
                return ['source' => $source, 'offerings' => $found];
            }
        }

        return null;
    }

    /** Generic last words of a service name ("tedavisi", "uygulaması", "treatment"); what is left names the service. */
    private const array GENERIC_WORDS = ['tedavisi', 'tedavileri', 'tedavi', 'uygulamasi', 'uygulamalari', 'uygulama', 'operasyonu', 'operasyonlari', 'ameliyati', 'hizmeti',
        'treatment', 'treatments', 'surgery', 'surgeries', 'application', 'applications'];

    /** The distinctive part of a service name, or null when nothing generic is dropped or what is left is too short. */
    private static function core(string $name): ?string
    {
        $words = explode(' ', SeoText::fold($name));
        $kept = array_values(array_filter($words, fn (string $w): bool => $w !== '' && ! in_array($w, self::GENERIC_WORDS, true)));
        if ($kept === [] || count($kept) === count($words) || count($kept) > 2) {
            return null;
        }
        $core = implode(' ', $kept);

        return mb_strlen($core) >= 6 ? $core : null;
    }

    /**
     * The brand's site pages that carry a service (OfferingPage), by host and path.
     *
     * @return array{hosts: array<string, true>, paths: array<string, array{path: string, offerings: list<int>}>}
     */
    private function pageIndex(Brand $brand): array
    {
        $sites = DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'website')->get(['id', 'domain', 'primary_url']);
        $hosts = [];
        foreach ($sites as $site) {
            foreach ([(string) $site->domain, (string) parse_url((string) $site->primary_url, PHP_URL_HOST)] as $host) {
                $host = preg_replace('/^www\./', '', mb_strtolower(trim($host)));
                if ($host !== '') {
                    $hosts[$host] = true;
                }
            }
        }
        $paths = [];
        if ($sites->isNotEmpty()) {
            $links = OfferingPage::query()->join('pages', 'pages.id', '=', 'offering_pages.page_id')->whereIn('pages.website_asset_id', $sites->pluck('id'))
                ->get(['offering_pages.brand_offering_id', 'pages.url', 'pages.path']);
            foreach ($links as $link) {
                $key = self::pathKey((string) $link->url);
                $paths[$key]['path'] = (string) $link->path;
                $paths[$key]['offerings'][] = (int) $link->brand_offering_id;
            }
        }

        return ['hosts' => $hosts, 'paths' => $paths];
    }

    private static function pathKey(string $url): string
    {
        return rtrim(mb_strtolower(rawurldecode(SeoText::urlPath($url))), '/');
    }

    /* ---------------- storage ---------------- */

    /** @return Collection<int, AdCampaignService> */
    private function rows(DigitalAsset $asset): Collection
    {
        return AdCampaignService::query()->where('channel', self::CHANNEL)->where('digital_asset_id', $asset->id)->orderBy('id')->get();
    }

    private function ownOffering(DigitalAsset $asset, int $offeringId): BrandOffering
    {
        return BrandOffering::query()->whereKey($offeringId)->where('brand_id', $asset->brand_id)->firstOrFail();
    }

    /** One operator row per campaign and service (confirmed or removed). */
    private function decide(DigitalAsset $asset, string $campaignId, int $offeringId, string $status, User $user): void
    {
        $rows = AdCampaignService::query()->where('channel', self::CHANNEL)->where('digital_asset_id', $asset->id)->where('campaign_id', $campaignId)
            ->where('brand_offering_id', $offeringId)->orderBy('id')->get();
        $row = $rows->first() ?? new AdCampaignService(['channel' => self::CHANNEL, 'digital_asset_id' => $asset->id, 'brand_id' => $asset->brand_id,
            'campaign_id' => $campaignId, 'brand_offering_id' => $offeringId, 'source' => 'operator']);
        $row->forceFill(['status' => $status, 'decided_by' => $user->id, 'decided_at' => now()])->save();
        AdCampaignService::query()->whereKey($rows->slice(1)->pluck('id'))->delete();
    }

    /** The campaign's open suggestions become the operator's (confirmed), keeping their source and reason. */
    private function settleSuggestions(DigitalAsset $asset, string $campaignId, User $user): void
    {
        AdCampaignService::query()->where('channel', self::CHANNEL)->where('digital_asset_id', $asset->id)->where('campaign_id', $campaignId)
            ->whereIn('status', [AdCampaignService::SUGGESTED, AdCampaignService::CONFIRMED])->whereNull('decided_by')
            ->update(['status' => AdCampaignService::CONFIRMED, 'decided_by' => $user->id, 'decided_at' => now()]);
    }
}
