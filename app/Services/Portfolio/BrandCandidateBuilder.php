<?php

namespace App\Services\Portfolio;

use App\Ai\Agents\BrandCandidateAgent;
use App\Models\Brand;
use App\Models\BrandCandidate;
use App\Models\BrandCandidateResource;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Models\ServiceCategory;
use App\Services\Ai\AiCancellation;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\BrandSetup\BrandSetupMatcher;
use App\Support\Ai\AiRouteKeys;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Faz 2 "Keşfedilen varlıklar → marka adayları". Every discovered website (connector sites included: they are website
 * assets) and account that has no brand and is in no candidate yet is grouped:
 *  1. deterministic: shared host — website ↔ Search Console property ↔ GA4 stream URL ↔ Business Profile website ↔
 *     Google Ads final URLs ↔ Meta creative links; then an account name that clearly matches a group;
 *  2. ONE AI call per batch groups what is left and proposes every new candidate's sector from its most reliable
 *     signal (Business Profile primary category > site title > ads). Validated: unknown keys / sectors are dropped.
 * Runs daily for new resources. Approved and dismissed candidates are never changed; proposed ones only gain new
 * members or lose members that got a brand elsewhere. Delegated to Claude (MCP queue): every batch is asked at once,
 * the leftovers stay unplaced meanwhile and the job runs again with the answers.
 */
final class BrandCandidateBuilder
{
    public const int BATCH = 80;

    /** Message of the last failed AI call of this run (shown by the command). */
    private ?string $lastAiError = null;

    private const array TYPE_LABELS = [
        'website' => 'Web sitesi', 'search_console' => 'Search Console', 'ga4' => 'GA4',
        'google_business_profile' => 'İşletme Profili', 'google_ads' => 'Google Ads', 'meta_ads' => 'Meta',
    ];

    public function __construct(
        private readonly PortfolioDiscoveryGrouper $grouper,
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
        private readonly AiTaskQueue $tasks,
    ) {}

    public static function typeLabel(string $type): string
    {
        return self::TYPE_LABELS[$type] ?? $type;
    }

    /** @return array{new_subjects: int, candidates_created: int, ai_calls: int, ai_status: string, ai_error: ?string} */
    public function refresh(): array
    {
        $this->prune();
        $subjects = $this->newSubjects();
        $summary = ['new_subjects' => count($subjects), 'candidates_created' => 0, 'ai_calls' => 0, 'ai_status' => 'skipped', 'ai_error' => null];
        $this->lastAiError = null;

        /** @var array<string, array{candidate: ?BrandCandidate, existing_brand_id: ?int, hosts: array<string, true>, members: list<array{0: array<string, mixed>, 1: string}>}> $groups */
        $groups = [];
        $hostIndex = [];
        foreach (BrandCandidate::query()->where('status', BrandCandidate::PROPOSED)->get() as $candidate) {
            $key = 'c:'.$candidate->id;
            $groups[$key] = ['candidate' => $candidate, 'existing_brand_id' => data_get($candidate->signals, 'existing_brand_id'), 'hosts' => array_fill_keys($candidate->hosts(), true), 'members' => []];
            foreach ($candidate->hosts() as $host) {
                $hostIndex[$host] ??= $key;
            }
        }
        $brandSites = $this->brandWebsiteHosts();

        // 1) Shared host.
        $unplaced = [];
        foreach ($subjects as $subject) {
            if ($subject['hosts'] === []) {
                $unplaced[] = $subject;

                continue;
            }
            $keys = array_values(array_unique(array_filter(array_map(fn (string $h): ?string => $hostIndex[$h] ?? null, $subject['hosts']))));
            $target = $keys[0] ?? null;
            if ($target === null) {
                $existingBrand = null;
                foreach ($subject['hosts'] as $host) {
                    $existingBrand ??= $brandSites[$host] ?? null;
                }
                $target = $existingBrand !== null ? 'b:'.$existingBrand : 'h:'.$subject['hosts'][0];
                $groups[$target] ??= ['candidate' => null, 'existing_brand_id' => $existingBrand, 'hosts' => [], 'members' => []];
            }
            // A subject whose hosts join two new groups merges them (a GA4 stream pointing at two sites, …).
            foreach (array_slice($keys, 1) as $other) {
                if ($other !== $target && isset($groups[$other]) && $groups[$other]['candidate'] === null) {
                    $groups[$target]['members'] = [...$groups[$target]['members'], ...$groups[$other]['members']];
                    $groups[$target]['hosts'] += $groups[$other]['hosts'];
                    foreach (array_keys($groups[$other]['hosts']) as $h) {
                        $hostIndex[$h] = $target;
                    }
                    unset($groups[$other]);
                }
            }
            $groups[$target]['members'][] = [$subject, 'Aynı alan adı: '.$subject['hosts'][0]];
            foreach ($subject['hosts'] as $host) {
                $groups[$target]['hosts'][$host] = true;
                $hostIndex[$host] ??= $target;
            }
        }

        // 2) Account name clearly matching a group's name or domain.
        $leftovers = [];
        foreach ($unplaced as $subject) {
            $best = null;
            $bestScore = 0.0;
            foreach ($groups as $key => $group) {
                $score = BrandSetupMatcher::nameScore($subject['name'], $this->groupName($group), (string) array_key_first($group['hosts']));
                if ($score > $bestScore) {
                    [$best, $bestScore] = [$key, $score];
                }
            }
            if ($best !== null && $bestScore >= 0.8) {
                $groups[$best]['members'][] = [$subject, sprintf('Hesap adı benziyor (%%%d)', (int) round($bestScore * 100))];

                continue;
            }
            $leftovers[] = $subject;
        }

        // Persist deterministic groups (new candidates + new members of proposed ones).
        $created = [];
        foreach ($groups as $group) {
            if ($group['members'] === []) {
                continue;
            }
            $candidate = $group['candidate'] ?? $this->createCandidate($this->groupName($group), $group['existing_brand_id'] !== null ? (int) $group['existing_brand_id'] : null, 0.9, 'deterministic');
            if ($group['candidate'] === null) {
                $created[] = $candidate;
            }
            foreach ($group['members'] as [$subject, $reason]) {
                $this->attach($candidate, $subject, $reason);
            }
            $this->refreshSignals($candidate);
        }

        // 3) One AI call per batch: leftovers + sectors of candidates that were never checked.
        $needSector = BrandCandidate::query()->where('status', BrandCandidate::PROPOSED)->whereNull('sector_id')
            ->whereNull('sector_signal')->get()
            ->reject(fn (BrandCandidate $c): bool => (bool) data_get($c->signals, 'sector_checked', false))->values();
        if ($leftovers !== [] || $needSector->isNotEmpty()) {
            $batches = max((int) ceil(count($leftovers) / self::BATCH), (int) ceil($needSector->count() / self::BATCH));
            for ($i = 0; $i < $batches; $i++) {
                $status = $this->aiBatch(array_slice($leftovers, $i * self::BATCH, self::BATCH), $needSector->slice($i * self::BATCH, self::BATCH)->values()->all(), $created, $i);
                $summary['ai_status'] = $status;
                if ($status === 'queued') {
                    continue; // waiting for Claude: the other batches are asked too
                }
                if ($status === 'called') {
                    $summary['ai_calls']++;
                }
                if ($status === 'error') {
                    // A failed call leaves the leftovers unplaced: the next run retries them instead of turning every
                    // account into its own candidate.
                    $summary['ai_error'] = $this->lastAiError;
                    break;
                }
                if ($status === 'no_provider') {
                    // Without AI every leftover is its own candidate (by name); sectors stay for the operator.
                    foreach (array_slice($leftovers, $i * self::BATCH) as $subject) {
                        if (! $this->isPlaced($subject)) {
                            $candidate = $this->createCandidate(PortfolioDiscoveryGrouper::cleanName($subject['name'], ''), null, 0.3, 'deterministic');
                            $created[] = $candidate;
                            $this->attach($candidate, $subject, 'Eşleşme yok; hesap adına göre.');
                            $this->refreshSignals($candidate);
                        }
                    }
                    break;
                }
            }
        }
        $summary['candidates_created'] = count($created);

        return $summary;
    }

    /**
     * @param  list<array<string, mixed>>  $leftovers
     * @param  list<BrandCandidate>  $candidates
     * @param  list<BrandCandidate>  $created
     * @return string called | queued | error | no_provider
     */
    private function aiBatch(array $leftovers, array $candidates, array &$created, int $index = 0): string
    {
        AiCancellation::throwIfRequested();
        $delegated = $this->tasks->delegated(AiRouteKeys::BRAND_CANDIDATES);
        $route = null;
        if (! $delegated) {
            try {
                $route = $this->routes->resolve(AiRouteKeys::BRAND_CANDIDATES);
                if ($route->isEmpty()) {
                    return 'no_provider';
                }
            } catch (Throwable $exception) {
                report($exception);

                return 'no_provider';
            }
        }
        $sectors = ServiceCategory::query()->orderBy('name')->get(['id', 'code', 'name']);
        $unplaced = [];
        foreach ($leftovers as $subject) {
            $unplaced[$subject['key']] = $subject;
        }
        $known = collect($candidates)->keyBy(fn (BrandCandidate $c): string => 'c:'.$c->id);
        $data = [
            'candidates' => $known->map(fn (BrandCandidate $c, string $key): array => ['key' => $key, 'name' => $c->name] + $this->signalPayload($c))->values()->all(),
            'unplaced' => collect($unplaced)->map(fn (array $s): array => [
                'key' => $s['key'], 'type' => $s['type'], 'name' => $s['name'], 'parent_name' => $s['parent'], 'hosts' => $s['hosts'],
            ])->values()->all(),
            'sectors' => $sectors->map(fn (ServiceCategory $s): array => ['code' => $s->code, 'name' => $s->name])->all(),
        ];
        // Candidates in this batch may also receive unplaced accounts (existing proposed ones are joinable too).
        foreach (BrandCandidate::query()->where('status', BrandCandidate::PROPOSED)->whereNotIn('id', $known->pluck('id'))->limit(self::BATCH)->get() as $other) {
            if ($leftovers !== []) {
                $data['candidates'][] = ['key' => 'c:'.$other->id, 'name' => $other->name, 'sector_known' => $other->sector_id !== null] + $this->signalPayload($other);
                $known->put('c:'.$other->id, $other);
            }
        }

        $structured = $delegated ? $this->tasks->delegatedCall(new BrandCandidateAgent, $data, 'batch-'.$index) : null;
        if ($structured === 'queued') {
            return 'queued';
        }
        if ($structured === 'error') {
            $this->lastAiError = 'Claude bu gruplamayı yapamadı.';

            return 'error';
        }
        try {
            if (! is_array($structured)) {
                // Not delegated, or no resumable run open (inline call): the provider route.
                $route ??= $this->routes->resolve(AiRouteKeys::BRAND_CANDIDATES);
                if ($route->isEmpty()) {
                    return 'no_provider';
                }
                $this->runtime->prepare(array_keys($route->providerModels));
                $structured = (new BrandCandidateAgent)->prompt(
                    "DATA_JSON\n".json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    provider: $route->providerModels,
                    timeout: 180,
                )->toArray();
            }
        } catch (Throwable $exception) {
            Log::warning('Brand candidate AI call failed.', ['error' => $exception->getMessage()]);
            $this->lastAiError = mb_substr($exception->getMessage(), 0, 300);

            return 'error';
        }

        // (a) Groups — only keys from this batch, each account once.
        $newGroups = [];
        foreach (is_array($structured['groups'] ?? null) ? $structured['groups'] : [] as $index => $group) {
            if (! is_array($group)) {
                continue;
            }
            $members = array_values(array_filter((array) ($group['account_keys'] ?? []), fn ($k): bool => is_string($k) && isset($unplaced[$k]) && ! $this->isPlaced($unplaced[$k])));
            if ($members === []) {
                continue;
            }
            $targetKey = is_string($group['candidate_key'] ?? null) ? $group['candidate_key'] : null;
            $candidate = $targetKey !== null ? $known->get($targetKey) : null;
            if ($candidate === null) {
                $name = trim((string) ($group['name'] ?? ''));
                $name = mb_strlen($name) >= 2 && mb_strlen($name) <= 120 ? $name : PortfolioDiscoveryGrouper::cleanName($unplaced[$members[0]]['name'], '');
                $candidate = $this->createCandidate($name, null, 0.6, 'ai');
                $created[] = $candidate;
                $newGroups['new:'.$index] = $candidate;
            }
            foreach ($members as $key) {
                $this->attach($candidate, $unplaced[$key], 'AI gruplaması');
            }
            $this->refreshSignals($candidate);
        }
        // Accounts the AI did not place become their own candidate.
        foreach ($unplaced as $subject) {
            if (! $this->isPlaced($subject)) {
                $candidate = $this->createCandidate(PortfolioDiscoveryGrouper::cleanName($subject['name'], ''), null, 0.3, 'ai');
                $created[] = $candidate;
                $this->attach($candidate, $subject, 'Eşleşme yok; hesap adına göre.');
                $this->refreshSignals($candidate);
            }
        }

        // (b) Sectors — only catalog codes, only candidates of this batch without a sector.
        $byCode = $sectors->keyBy('code');
        $targets = $known->merge($newGroups);
        foreach (is_array($structured['sectors'] ?? null) ? $structured['sectors'] : [] as $row) {
            $candidate = is_array($row) && is_string($row['key'] ?? null) ? $targets->get($row['key']) : null;
            if (! $candidate instanceof BrandCandidate || $candidate->sector_id !== null || $candidate->sector_signal === 'manual') {
                continue;
            }
            $sector = is_string($row['sector_code'] ?? null) ? $byCode->get($row['sector_code']) : null;
            $signal = in_array($row['signal'] ?? null, ['gbp_category', 'site', 'ads'], true) ? $row['signal'] : null;
            if ($sector === null || $signal === null) {
                continue;
            }
            $candidate->forceFill([
                'sector_id' => $sector->id,
                'sector_signal' => $signal,
                'sector_reason' => mb_substr(trim((string) ($row['reason'] ?? '')), 0, 255) ?: null,
                'signals' => array_merge((array) $candidate->signals, ['sector_confidence' => round(max(0.0, min(1.0, (float) ($row['confidence'] ?? 0))), 2)]),
            ])->save();
        }
        foreach ($targets as $candidate) {
            $candidate->refresh();
            $candidate->forceFill(['signals' => array_merge((array) $candidate->signals, ['sector_checked' => true])])->save();
        }

        return 'called';
    }

    /** Drops members of proposed candidates that got a brand (or vanished); empty proposed candidates are removed. */
    private function prune(): void
    {
        $bound = CoreAssetBinding::query()->where('status', CoreAssetBinding::STATUS_ACTIVE)->pluck('external_resource_id')->all();
        BrandCandidateResource::query()->whereHas('candidate', fn ($q) => $q->where('status', BrandCandidate::PROPOSED))
            ->with(['resource', 'website'])->get()
            ->each(function (BrandCandidateResource $member) use ($bound): void {
                $gone = $member->external_resource_id !== null
                    ? ($member->resource === null || in_array($member->external_resource_id, $bound, false) || $member->resource->status !== CoreExternalResource::STATUS_AVAILABLE)
                    : ($member->website === null || $member->website->brand_id !== null);
                if ($gone) {
                    $member->delete();
                }
            });
        BrandCandidate::query()->where('status', BrandCandidate::PROPOSED)->whereDoesntHave('members')->delete();
    }

    /**
     * Brandless websites and unbound accounts that are in no candidate yet.
     *
     * @return list<array{key: string, type: string, name: string, parent: string, hosts: list<string>, resource_id: ?int, website_id: ?int}>
     */
    public function newSubjects(): array
    {
        $inCandidates = BrandCandidateResource::query()->get(['external_resource_id', 'website_asset_id']);
        $takenResources = $inCandidates->pluck('external_resource_id')->filter()->map(fn ($id): int => (int) $id)->all();
        $takenSites = $inCandidates->pluck('website_asset_id')->filter()->map(fn ($id): int => (int) $id)->all();
        $bound = CoreAssetBinding::query()->where('status', CoreAssetBinding::STATUS_ACTIVE)->pluck('external_resource_id')->all();

        $subjects = [];
        DigitalAsset::query()->whereNull('brand_id')->where('type', 'website')->whereNotIn('id', $takenSites)->orderBy('id')->get()
            ->each(function (DigitalAsset $site) use (&$subjects): void {
                $host = BrandSetupMatcher::host((string) ($site->primary_url ?: $site->domain));
                $subjects[] = [
                    'key' => 'w:'.$site->id, 'type' => 'website', 'name' => (string) ($site->domain ?: $site->name), 'parent' => '',
                    'hosts' => $host !== '' ? [$host] : [], 'resource_id' => null, 'website_id' => (int) $site->id,
                ];
            });
        CoreExternalResource::query()
            ->whereIn('resource_type', PortfolioDiscoveryGrouper::TYPES)
            ->where('status', CoreExternalResource::STATUS_AVAILABLE)
            ->whereHas('integration', fn ($query) => $query->where('status', CoreIntegration::STATUS_ACTIVE))
            ->whereNotIn('id', $bound)->whereNotIn('id', $takenResources)
            ->orderBy('id')->get()
            ->reject(function (CoreExternalResource $resource): bool {
                $meta = is_array($resource->metadata) ? $resource->metadata : [];

                return ($meta['is_manager'] ?? false) === true || ($meta['selectable'] ?? true) === false || ($meta['bindable'] ?? true) === false;
            })
            ->each(function (CoreExternalResource $resource) use (&$subjects): void {
                $meta = is_array($resource->metadata) ? $resource->metadata : [];
                $subjects[] = [
                    'key' => 'r:'.$resource->id, 'type' => (string) $resource->resource_type,
                    'name' => trim((string) ($resource->display_name ?: $resource->external_id)),
                    'parent' => trim((string) ($meta['business_name'] ?? $meta['account_display_name'] ?? '')),
                    'hosts' => $this->resourceHosts($resource), 'resource_id' => (int) $resource->id, 'website_id' => null,
                ];
            });

        return $subjects;
    }

    /** @return list<string> the account's own web address, else the most common ad destination host */
    private function resourceHosts(CoreExternalResource $resource): array
    {
        $host = $this->grouper->hostOf($resource);
        if ($host === null && $resource->resource_type === 'google_business_profile' && Schema::hasTable('gbp_location_snapshots')) {
            $uri = DB::table('gbp_location_snapshots')->where('external_resource_id', $resource->id)->orderByDesc('captured_at')->orderByDesc('id')->value('website_uri');
            $host = $this->usableHost((string) $uri);
        }
        if ($host === null && in_array($resource->resource_type, ['google_ads', 'meta_ads'], true)) {
            $host = $this->adHost($resource);
        }

        return $host !== null ? [$host] : [];
    }

    private function adHost(CoreExternalResource $resource): ?string
    {
        [$table, $path] = $resource->resource_type === 'google_ads' ? ['google_ads_ad_snapshot', 'final_urls'] : ['meta_creative_snapshot', 'link_url'];
        if (! Schema::hasTable($table)) {
            return null;
        }
        $counts = [];
        foreach (DB::table($table)->where('external_resource_id', $resource->id)->limit(300)->pluck('metadata') as $raw) {
            $meta = is_string($raw) ? (json_decode($raw, true) ?: []) : (array) $raw;
            foreach ((array) ($meta[$path] ?? $meta['finalUrls'] ?? []) as $url) {
                $host = is_string($url) ? $this->usableHost($url) : null;
                if ($host !== null) {
                    $counts[$host] = ($counts[$host] ?? 0) + 1;
                }
            }
        }
        arsort($counts);

        return array_key_first($counts);
    }

    private function usableHost(string $url): ?string
    {
        $host = BrandSetupMatcher::host($url);

        return $host !== '' && str_contains($host, '.') && ! in_array($host, PortfolioDiscoveryGrouper::SHARED_HOSTS, true) ? $host : null;
    }

    /** @return array<string, int> host => brand id of website assets that already belong to a brand */
    private function brandWebsiteHosts(): array
    {
        $out = [];
        DigitalAsset::query()->whereNotNull('brand_id')->where('type', 'website')->get(['id', 'brand_id', 'primary_url', 'domain'])
            ->each(function (DigitalAsset $site) use (&$out): void {
                $host = BrandSetupMatcher::host((string) ($site->primary_url ?: $site->domain));
                if ($host !== '') {
                    $out[$host] ??= (int) $site->brand_id;
                }
            });

        return $out;
    }

    /** @param  array<string, mixed>  $group */
    private function groupName(array $group): string
    {
        if ($group['candidate'] instanceof BrandCandidate) {
            return (string) $group['candidate']->name;
        }
        if ($group['existing_brand_id'] !== null) {
            return (string) Brand::query()->whereKey($group['existing_brand_id'])->value('name');
        }
        $host = (string) array_key_first($group['hosts']);
        foreach (['google_business_profile', 'ga4', 'google_ads', 'meta_ads'] as $type) {
            foreach ($group['members'] as [$subject]) {
                if ($subject['type'] === $type) {
                    $name = PortfolioDiscoveryGrouper::cleanName($subject['name'], $host);
                    if ($name !== '' && ! ctype_digit(str_replace(['-', ' '], '', $name))) {
                        return $name;
                    }
                }
            }
        }

        return mb_convert_case(BrandSetupMatcher::domainRoot($host), MB_CASE_TITLE);
    }

    private function createCandidate(string $name, ?int $existingBrandId, float $confidence, string $method): BrandCandidate
    {
        return BrandCandidate::query()->create([
            'name' => mb_substr($name !== '' ? $name : 'Adsız', 0, 160),
            'signals' => $existingBrandId !== null ? ['existing_brand_id' => $existingBrandId] : [],
            'confidence' => $confidence,
            'method' => $method,
            'status' => BrandCandidate::PROPOSED,
        ]);
    }

    /** @param  array<string, mixed>  $subject */
    private function attach(BrandCandidate $candidate, array $subject, string $reason): void
    {
        BrandCandidateResource::query()->firstOrCreate(
            $subject['resource_id'] !== null ? ['external_resource_id' => $subject['resource_id']] : ['website_asset_id' => $subject['website_id']],
            ['brand_candidate_id' => $candidate->id, 'reason' => mb_substr($reason, 0, 255)],
        );
    }

    /** @param  array<string, mixed>  $subject */
    private function isPlaced(array $subject): bool
    {
        return $subject['resource_id'] !== null
            ? BrandCandidateResource::query()->where('external_resource_id', $subject['resource_id'])->exists()
            : BrandCandidateResource::query()->where('website_asset_id', $subject['website_id'])->exists();
    }

    /** Recomputes the candidate's signals (hosts, Business Profile category, site title, ad account names). */
    public function refreshSignals(BrandCandidate $candidate): void
    {
        $members = $candidate->members()->with(['resource', 'website'])->get();
        $hosts = [];
        $gbpCategory = null;
        $siteTitle = null;
        $adNames = [];
        foreach ($members as $member) {
            if ($member->website !== null) {
                $host = BrandSetupMatcher::host((string) ($member->website->primary_url ?: $member->website->domain));
                if ($host !== '') {
                    $hosts[$host] = true;
                }
                $siteTitle ??= $this->siteTitle($member->website);

                continue;
            }
            $resource = $member->resource;
            if ($resource === null) {
                continue;
            }
            foreach ($this->resourceHosts($resource) as $host) {
                $hosts[$host] = true;
            }
            if ($resource->resource_type === 'google_business_profile') {
                $gbpCategory ??= $this->gbpCategory($resource);
            }
            if (in_array($resource->resource_type, ['google_ads', 'meta_ads'], true)) {
                $adNames[] = trim((string) ($resource->display_name ?: $resource->external_id));
            }
        }
        $candidate->forceFill(['signals' => array_merge((array) $candidate->signals, [
            'hosts' => array_keys($hosts),
            'gbp_category' => $gbpCategory,
            'site_title' => $siteTitle,
            'ad_names' => array_values(array_unique(array_slice($adNames, 0, 10))),
            'types' => $members->map(fn (BrandCandidateResource $m): string => $m->website !== null ? 'website' : (string) $m->resource?->resource_type)->filter()->countBy()->all(),
        ])])->save();
    }

    /** @return array<string, mixed> */
    private function signalPayload(BrandCandidate $candidate): array
    {
        return [
            'hosts' => $candidate->hosts(),
            'gbp_category' => data_get($candidate->signals, 'gbp_category'),
            'site_title' => data_get($candidate->signals, 'site_title'),
            'ad_names' => (array) data_get($candidate->signals, 'ad_names', []),
        ];
    }

    public static function gbpCategory(CoreExternalResource $resource): ?string
    {
        $meta = is_array($resource->metadata) ? $resource->metadata : [];
        $category = $meta['primary_category'] ?? null;
        if (! is_string($category) && Schema::hasTable('gbp_location_snapshots')) {
            $category = DB::table('gbp_location_snapshots')->where('external_resource_id', $resource->id)->orderByDesc('captured_at')->orderByDesc('id')->value('primary_category');
        }

        return is_string($category) && trim($category) !== '' ? mb_substr(trim($category), 0, 160) : null;
    }

    public static function siteTitle(DigitalAsset $site): ?string
    {
        $title = Page::query()->where('website_asset_id', $site->id)->whereNotNull('title')
            ->orderByRaw("case when path = '/' then 0 else 1 end")->orderByRaw('length(path)')->value('title');

        return is_string($title) && trim($title) !== '' ? mb_substr(trim($title), 0, 200) : null;
    }
}
