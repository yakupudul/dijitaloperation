<?php

namespace App\Services\IntelligenceProjection\Website;

use App\Models\DigitalAsset;
use App\Models\IntelligenceCore\IntelligenceBusinessActionIdentity;
use App\Models\IntelligenceCore\IntelligenceEntityIdentity;
use App\Models\IntelligenceCore\IntelligencePageIdentity;
use App\Models\IntelligenceCore\IntelligenceSearchTermIdentity;
use App\Models\IntelligenceProjection\WebsiteEntityProfile;
use App\Models\IntelligenceProjection\WebsiteIntelligenceProjectionRun;
use App\Models\IntelligenceProjection\WebsiteOutcomeProfile;
use App\Models\IntelligenceProjection\WebsitePageProfile;
use App\Models\IntelligenceProjection\WebsiteSearchTermProfile;
use App\Services\IntelligenceCore\IntelligenceCoreRegistryLoader;
use App\Support\IntelligenceProjection\WebsiteProjectionContext;
use App\Support\IntelligenceProjection\WebsiteProjectionContribution;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class WebsiteProjectionRebuilder
{
    public const int PROFILE_VERSION = 1;

    public const int SCHEMA_VERSION = 1;

    public const int DEFAULT_WINDOW_DAYS = 90;

    public function __construct(
        private readonly WebsiteProjectionSourceAdapterRegistry $adapters,
        private readonly IntelligenceCoreRegistryLoader $registry,
        private readonly WebsiteProjectionAdapterSupport $support,
    ) {}

    public function rebuild(
        DigitalAsset $asset,
        string $trigger = 'manual',
        ?int $triggerCollectionRunId = null,
        ?CarbonImmutable $periodStart = null,
        ?CarbonImmutable $periodEnd = null,
    ): WebsiteIntelligenceProjectionRun {
        if ($asset->getKey() === null || $asset->type !== 'website') {
            throw new InvalidArgumentException('Website Projection can only rebuild a persisted Website Digital Asset.');
        }

        return Cache::lock('website-projection-rebuild:'.$asset->getKey(), 1200)->block(
            60,
            fn (): WebsiteIntelligenceProjectionRun => $this->rebuildUnlocked(
                asset: $asset,
                trigger: $trigger,
                triggerCollectionRunId: $triggerCollectionRunId,
                periodStart: $periodStart,
                periodEnd: $periodEnd,
            ),
        );
    }

    private function rebuildUnlocked(
        DigitalAsset $asset,
        string $trigger,
        ?int $triggerCollectionRunId,
        ?CarbonImmutable $periodStart,
        ?CarbonImmutable $periodEnd,
    ): WebsiteIntelligenceProjectionRun {
        if ($asset->getKey() === null || $asset->type !== 'website') {
            throw new InvalidArgumentException('Website Projection can only rebuild a persisted Website Digital Asset.');
        }
        $asset->loadMissing('brand');
        if ($asset->brand === null) {
            throw new InvalidArgumentException('Website Projection requires the Website Brand.');
        }

        $periodEnd ??= CarbonImmutable::today('UTC')->subDay();
        $periodStart ??= $periodEnd->subDays(self::DEFAULT_WINDOW_DAYS - 1);
        if ($periodStart->isAfter($periodEnd)) {
            throw new InvalidArgumentException('Website Projection period start must not be after period end.');
        }

        $run = WebsiteIntelligenceProjectionRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'website_asset_id' => (int) $asset->getKey(),
            'trigger_collection_run_id' => $triggerCollectionRunId,
            'trigger' => trim($trigger) !== '' ? trim($trigger) : 'manual',
            'status' => WebsiteIntelligenceProjectionRun::STATUS_RUNNING,
            'schema_version' => self::SCHEMA_VERSION,
            'intelligence_registry_version' => $this->registry->version(),
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'started_at' => now(),
        ]);

        try {
            $context = new WebsiteProjectionContext($asset, $run, $periodStart, $periodEnd);
            $registered = $this->adapters->all();
            if ($registered === []) {
                throw new RuntimeException('No Website Projection source adapters are registered.');
            }

            $contributions = [];
            $errors = [];
            foreach ($registered as $sourceId => $adapter) {
                try {
                    $contribution = $adapter->project($context);
                    if ($contribution->sourceId !== $sourceId) {
                        throw new RuntimeException("Website Projection adapter source mismatch [{$sourceId}].");
                    }
                    $contributions[$sourceId] = $contribution;
                } catch (Throwable $exception) {
                    report($exception);
                    $errors[$sourceId] = [
                        'code' => class_basename($exception),
                        'summary' => Str::limit($exception->getMessage(), 500),
                    ];
                }
            }

            $merged = $this->merge($contributions);
            $isPartial = $errors !== [];
            $summary = DB::transaction(function () use ($asset, $run, $merged, $isPartial): array {
                $counts = [
                    'pages' => $this->persistPages($asset, $run, $merged['pages'], $isPartial),
                    'search_terms' => $this->persistSearchTerms($asset, $run, $merged['search_terms'], $isPartial),
                    'entities' => $this->persistEntities($asset, $run, $merged['entities'], $isPartial),
                    'outcomes' => $this->persistOutcomes($asset, $run, $merged['outcomes'], $isPartial),
                ];

                if (! $isPartial) {
                    $this->pruneAbsentProfiles($asset, $merged);
                }

                return $counts;
            });

            $coverage = [];
            $watermarks = [];
            foreach ($contributions as $sourceId => $contribution) {
                $coverage[$sourceId] = $contribution->coverage;
                $watermarks[$sourceId] = $contribution->watermark;
            }
            foreach ($errors as $sourceId => $error) {
                $coverage[$sourceId] = ['state' => 'projection_failed', 'error_code' => $error['code']];
                $watermarks[$sourceId] = null;
            }

            $run->fill([
                'status' => $isPartial
                    ? WebsiteIntelligenceProjectionRun::STATUS_PARTIAL
                    : WebsiteIntelligenceProjectionRun::STATUS_COMPLETED,
                'source_watermarks' => $watermarks,
                'coverage_state' => $coverage,
                'summary' => [
                    'profile_counts' => $summary,
                    'source_counts' => array_map(
                        static fn (WebsiteProjectionContribution $contribution): array => $contribution->counts(),
                        $contributions,
                    ),
                    'source_errors' => $errors,
                    'provider_fact_tables_remain_canonical' => true,
                    'projection_is_rebuildable' => true,
                ],
                'completed_at' => now(),
                'error_code' => $isPartial ? 'SOURCE_ADAPTER_PARTIAL' : null,
                'error_summary' => $isPartial ? implode('; ', array_map(
                    static fn (string $source, array $error): string => $source.': '.$error['summary'],
                    array_keys($errors),
                    $errors,
                )) : null,
            ])->save();

            return $run->refresh();
        } catch (Throwable $exception) {
            $run->fill([
                'status' => WebsiteIntelligenceProjectionRun::STATUS_FAILED,
                'completed_at' => now(),
                'error_code' => class_basename($exception),
                'error_summary' => Str::limit($exception->getMessage(), 1000),
            ])->save();

            throw $exception;
        }
    }

    /**
     * @param array<string, WebsiteProjectionContribution> $contributions
     * @return array{pages:array<int,array<string,mixed>>,search_terms:array<int,array<string,mixed>>,entities:array<int,array<string,mixed>>,outcomes:array<int,array<string,mixed>>}
     */
    private function merge(array $contributions): array
    {
        $merged = ['pages' => [], 'search_terms' => [], 'entities' => [], 'outcomes' => []];
        foreach ($contributions as $sourceId => $contribution) {
            foreach ([
                'pages' => $contribution->pages,
                'search_terms' => $contribution->searchTerms,
                'entities' => $contribution->entities,
                'outcomes' => $contribution->outcomes,
            ] as $profile => $rows) {
                foreach ($rows as $row) {
                    $identityId = (int) ($row['identity_id'] ?? 0);
                    if ($identityId < 1 || ! is_array($row['source_state'] ?? null)) {
                        throw new RuntimeException("Invalid [{$profile}] contribution from [{$sourceId}].");
                    }
                    $current = $merged[$profile][$identityId] ?? [
                        'source_states' => [],
                        'coverage_state' => [],
                        'last_observed_at' => null,
                    ];
                    $current['source_states'][$sourceId] = $row['source_state'];
                    $current['coverage_state'][$sourceId] = ['state' => $row['source_state']['state'] ?? 'unknown'];
                    $current['last_observed_at'] = $this->support->latestTimestamp(
                        $current['last_observed_at'],
                        $row['observed_at'] ?? null,
                    );
                    $merged[$profile][$identityId] = $current;
                }
            }
        }

        return $merged;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function persistPages(
        DigitalAsset $asset,
        WebsiteIntelligenceProjectionRun $run,
        array $rows,
        bool $partial,
    ): int {
        $identities = IntelligencePageIdentity::query()->whereIn('id', array_keys($rows))->get()->keyBy('id');
        foreach ($rows as $identityId => $state) {
            $identity = $identities->get($identityId);
            if (! $identity instanceof IntelligencePageIdentity || (int) $identity->website_asset_id !== (int) $asset->getKey()) {
                throw new RuntimeException("Page identity [{$identityId}] is outside Website Projection scope.");
            }
            $profile = WebsitePageProfile::query()->firstOrNew([
                'website_asset_id' => (int) $asset->getKey(),
                'page_identity_id' => $identityId,
            ]);
            $profile->fill([
                'projection_run_id' => $run->getKey(),
                'preferred_url' => $identity->preferred_url,
                'source_states' => $this->statesForWrite($profile->source_states, $state['source_states'], $partial),
                'coverage_state' => $this->statesForWrite($profile->coverage_state, $state['coverage_state'], $partial),
                'profile_version' => self::PROFILE_VERSION,
                'last_observed_at' => $this->support->latestTimestamp($partial ? $profile->last_observed_at : null, $state['last_observed_at']),
                'projected_at' => now(),
            ])->save();
        }

        return count($rows);
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function persistSearchTerms(
        DigitalAsset $asset,
        WebsiteIntelligenceProjectionRun $run,
        array $rows,
        bool $partial,
    ): int {
        $identities = IntelligenceSearchTermIdentity::query()->whereIn('id', array_keys($rows))->get()->keyBy('id');
        foreach ($rows as $identityId => $state) {
            $identity = $identities->get($identityId);
            if (! $identity instanceof IntelligenceSearchTermIdentity || (int) $identity->brand_id !== (int) $asset->brand_id) {
                throw new RuntimeException("Search-term identity [{$identityId}] is outside Website Projection Brand scope.");
            }
            $profile = WebsiteSearchTermProfile::query()->firstOrNew([
                'website_asset_id' => (int) $asset->getKey(),
                'search_term_identity_id' => $identityId,
            ]);
            $profile->fill([
                'projection_run_id' => $run->getKey(),
                'canonical_text' => $identity->canonical_text,
                'source_states' => $this->statesForWrite($profile->source_states, $state['source_states'], $partial),
                'coverage_state' => $this->statesForWrite($profile->coverage_state, $state['coverage_state'], $partial),
                'profile_version' => self::PROFILE_VERSION,
                'last_observed_at' => $this->support->latestTimestamp($partial ? $profile->last_observed_at : null, $state['last_observed_at']),
                'projected_at' => now(),
            ])->save();
        }

        return count($rows);
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function persistEntities(
        DigitalAsset $asset,
        WebsiteIntelligenceProjectionRun $run,
        array $rows,
        bool $partial,
    ): int {
        $identities = IntelligenceEntityIdentity::query()->whereIn('id', array_keys($rows))->get()->keyBy('id');
        foreach ($rows as $identityId => $state) {
            $identity = $identities->get($identityId);
            if (! $identity instanceof IntelligenceEntityIdentity || (int) $identity->brand_id !== (int) $asset->brand_id) {
                throw new RuntimeException("Entity identity [{$identityId}] is outside Website Projection Brand scope.");
            }
            $profile = WebsiteEntityProfile::query()->firstOrNew([
                'website_asset_id' => (int) $asset->getKey(),
                'entity_identity_id' => $identityId,
            ]);
            $profile->fill([
                'projection_run_id' => $run->getKey(),
                'entity_type' => $identity->entity_type,
                'canonical_name' => $identity->canonical_name,
                'source_states' => $this->statesForWrite($profile->source_states, $state['source_states'], $partial),
                'coverage_state' => $this->statesForWrite($profile->coverage_state, $state['coverage_state'], $partial),
                'profile_version' => self::PROFILE_VERSION,
                'last_observed_at' => $this->support->latestTimestamp($partial ? $profile->last_observed_at : null, $state['last_observed_at']),
                'projected_at' => now(),
            ])->save();
        }

        return count($rows);
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function persistOutcomes(
        DigitalAsset $asset,
        WebsiteIntelligenceProjectionRun $run,
        array $rows,
        bool $partial,
    ): int {
        $identities = IntelligenceBusinessActionIdentity::query()->whereIn('id', array_keys($rows))->get()->keyBy('id');
        foreach ($rows as $identityId => $state) {
            $identity = $identities->get($identityId);
            if (! $identity instanceof IntelligenceBusinessActionIdentity || (int) $identity->brand_id !== (int) $asset->brand_id) {
                throw new RuntimeException("Business-action identity [{$identityId}] is outside Website Projection Brand scope.");
            }
            $profile = WebsiteOutcomeProfile::query()->firstOrNew([
                'website_asset_id' => (int) $asset->getKey(),
                'business_action_identity_id' => $identityId,
            ]);
            $profile->fill([
                'projection_run_id' => $run->getKey(),
                'action_key' => $identity->action_key,
                'display_name' => $identity->display_name,
                'source_states' => $this->statesForWrite($profile->source_states, $state['source_states'], $partial),
                'coverage_state' => $this->statesForWrite($profile->coverage_state, $state['coverage_state'], $partial),
                'profile_version' => self::PROFILE_VERSION,
                'last_observed_at' => $this->support->latestTimestamp($partial ? $profile->last_observed_at : null, $state['last_observed_at']),
                'projected_at' => now(),
            ])->save();
        }

        return count($rows);
    }

    /** @param array<string,mixed>|null $current @param array<string,mixed> $projected @return array<string,mixed> */
    private function statesForWrite(?array $current, array $projected, bool $partial): array
    {
        return $partial ? array_replace($current ?? [], $projected) : $projected;
    }

    /** @param array<string,array<int,array<string,mixed>>> $merged */
    private function pruneAbsentProfiles(DigitalAsset $asset, array $merged): void
    {
        $assetId = (int) $asset->getKey();
        $this->prune(WebsitePageProfile::query()->where('website_asset_id', $assetId), 'page_identity_id', array_keys($merged['pages']));
        $this->prune(WebsiteSearchTermProfile::query()->where('website_asset_id', $assetId), 'search_term_identity_id', array_keys($merged['search_terms']));
        $this->prune(WebsiteEntityProfile::query()->where('website_asset_id', $assetId), 'entity_identity_id', array_keys($merged['entities']));
        $this->prune(WebsiteOutcomeProfile::query()->where('website_asset_id', $assetId), 'business_action_identity_id', array_keys($merged['outcomes']));
    }

    /** @param \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model> $query @param list<int> $ids */
    private function prune(\Illuminate\Database\Eloquent\Builder $query, string $column, array $ids): void
    {
        if ($ids === []) {
            $query->delete();

            return;
        }
        $query->whereNotIn($column, $ids)->delete();
    }
}
