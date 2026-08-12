<?php

namespace MoxDop\MetaAds\History;

use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Run;
use Illuminate\Support\Collection;
use MoxDop\MetaAds\Models\MetaAdsAccountImportState;
use MoxDop\MetaAds\Models\MetaAdsDailyFact;
use MoxDop\MetaAds\Models\MetaAdsEntity;

/**
 * Authoritative account-level progress for Meta history imports.
 *
 * Backed by {@see MetaAdsAccountImportState} rows — the single source of truth for
 * "how many accounts are ready" and the parent Run's derived status. Run metadata
 * counters have historically drifted (accounts_total vs accounts_done contradictions);
 * every count exposed here is computed from persisted state rows scoped to the
 * accounts that are actually discovered for the Integration, never invented.
 *
 * Public API is consumed by the TailAdmin workspace UI (accountRows / overallSummary)
 * and by the import jobs (mark* + derive*). Keep it stable.
 */
final class MetaHistoricalImportProgress
{
    public const string RESOURCE_TYPE = 'meta_ads';

    /**
     * Number of Meta Ad Accounts available for import under this Integration ONLY.
     * This is the authoritative denominator for every "N / M accounts" claim.
     */
    public function authoritativeDiscoveredCount(CoreIntegration $integration): int
    {
        return $this->discoveredResourceIds($integration)->count();
    }

    /**
     * Ensures one state row exists per discovered available account. Never invents
     * accounts: rows are only created for currently-available meta_ads resources.
     * New rows start as `waiting`; existing rows are left untouched (a new run resets
     * them explicitly via markQueued).
     */
    public function ensureStatesForDiscovered(CoreIntegration $integration, ?Run $run = null): void
    {
        $resources = $this->discoveredResources($integration);

        foreach ($resources as $resource) {
            MetaAdsAccountImportState::query()->firstOrCreate(
                ['core_external_resource_id' => $resource->id],
                [
                    'core_integration_id' => $integration->id,
                    'status' => MetaAdsAccountImportState::STATUS_WAITING,
                    'phase_label' => 'Waiting to start',
                    'last_import_run_id' => $run?->id,
                ],
            );
        }
    }

    /**
     * Resets an account to `queued` at the start of a run and clears prior error state.
     */
    public function markQueued(CoreExternalResource $resource, ?Run $run = null): MetaAdsAccountImportState
    {
        return $this->persist($resource, [
            'status' => MetaAdsAccountImportState::STATUS_QUEUED,
            'phase_label' => 'Queued',
            'chunks_done' => 0,
            'last_error_category' => null,
            'last_error_summary' => null,
            'last_import_run_id' => $run?->id,
        ]);
    }

    /**
     * Updates an in-flight account with a status/phase and optional progress counters.
     *
     * @param  array{
     *     phase_label?: ?string,
     *     chunks_total?: ?int,
     *     chunks_done?: ?int,
     *     campaigns_total?: ?int,
     *     campaigns_done?: ?int,
     *     adsets_total?: ?int,
     *     adsets_done?: ?int,
     *     ads_total?: ?int,
     *     ads_done?: ?int,
     * }  $progress
     */
    public function markPhase(
        CoreExternalResource $resource,
        string $status,
        ?string $phaseLabel = null,
        array $progress = [],
        ?Run $run = null,
    ): MetaAdsAccountImportState {
        $attributes = array_filter(
            [
                'chunks_total' => $progress['chunks_total'] ?? null,
                'chunks_done' => $progress['chunks_done'] ?? null,
                'campaigns_total' => $progress['campaigns_total'] ?? null,
                'campaigns_done' => $progress['campaigns_done'] ?? null,
                'adsets_total' => $progress['adsets_total'] ?? null,
                'adsets_done' => $progress['adsets_done'] ?? null,
                'ads_total' => $progress['ads_total'] ?? null,
                'ads_done' => $progress['ads_done'] ?? null,
            ],
            fn (mixed $value): bool => $value !== null,
        );

        $attributes['status'] = $status;
        $attributes['phase_label'] = $phaseLabel ?? $progress['phase_label'] ?? $this->defaultPhaseLabel($status);
        if ($run !== null) {
            $attributes['last_import_run_id'] = $run->id;
        }

        return $this->persist($resource, $attributes);
    }

    /**
     * Marks an account fully imported. Date bounds and entity/fact counts are computed
     * from the persisted historical store — never from the caller's loop bookkeeping.
     */
    public function markReady(CoreExternalResource $resource, ?Run $run = null): MetaAdsAccountImportState
    {
        return $this->persist($resource, array_merge($this->hydrateFromStore($resource), [
            'status' => MetaAdsAccountImportState::STATUS_READY,
            'phase_label' => 'Ready',
            'last_error_category' => null,
            'last_error_summary' => null,
            'last_successful_at' => now(),
            'last_import_run_id' => $run?->id,
        ]));
    }

    /**
     * Marks an account imported with gaps. Whatever data landed is still surfaced.
     */
    public function markPartial(CoreExternalResource $resource, ?string $summary = null, ?Run $run = null): MetaAdsAccountImportState
    {
        return $this->persist($resource, array_merge($this->hydrateFromStore($resource), [
            'status' => MetaAdsAccountImportState::STATUS_PARTIAL,
            'phase_label' => 'Imported with gaps',
            'last_error_summary' => $summary,
            'last_successful_at' => now(),
            'last_import_run_id' => $run?->id,
        ]));
    }

    /**
     * Marks a single account failed (or needs_attention) without touching the others.
     */
    public function markFailed(
        CoreExternalResource $resource,
        string $category,
        string $summary,
        bool $needsAttention = false,
        ?Run $run = null,
    ): MetaAdsAccountImportState {
        return $this->persist($resource, [
            'status' => $needsAttention
                ? MetaAdsAccountImportState::STATUS_NEEDS_ATTENTION
                : MetaAdsAccountImportState::STATUS_FAILED,
            'phase_label' => $needsAttention ? 'Needs attention' : 'Failed',
            'last_error_category' => $category,
            'last_error_summary' => $summary,
            'last_import_run_id' => $run?->id,
        ]);
    }

    /**
     * Authoritative overall summary for an Integration's import. Every count is scoped
     * to discovered accounts; the total is ALWAYS `discovered`.
     *
     * @return array{
     *     discovered: int,
     *     ready: int,
     *     partial: int,
     *     failed: int,
     *     queued: int,
     *     running: int,
     *     accounts_ready_label: string,
     * }
     */
    public function overallSummary(CoreIntegration $integration): array
    {
        $discovered = $this->authoritativeDiscoveredCount($integration);
        $states = $this->discoveredStates($integration);

        $countByStatus = fn (string $status): int => $states
            ->where('status', $status)
            ->count();

        $running = $states
            ->whereIn('status', MetaAdsAccountImportState::RUNNING_STATUSES)
            ->count();

        $ready = $countByStatus(MetaAdsAccountImportState::STATUS_READY);

        return [
            'discovered' => $discovered,
            'ready' => $ready,
            'partial' => $countByStatus(MetaAdsAccountImportState::STATUS_PARTIAL),
            'failed' => $countByStatus(MetaAdsAccountImportState::STATUS_FAILED)
                + $countByStatus(MetaAdsAccountImportState::STATUS_NEEDS_ATTENTION),
            'queued' => $countByStatus(MetaAdsAccountImportState::STATUS_QUEUED)
                + $countByStatus(MetaAdsAccountImportState::STATUS_WAITING),
            'running' => $running,
            'accounts_ready_label' => sprintf(
                '%d / %d account%s ready',
                $ready,
                $discovered,
                $discovered === 1 ? '' : 's',
            ),
        ];
    }

    /**
     * Rows for the operator-facing account table. One row per discovered account,
     * left-joined with its state (a not-yet-seen account reads as `waiting`).
     *
     * @return list<array{
     *     resource_id: int,
     *     external_id: ?string,
     *     display_name: ?string,
     *     status: string,
     *     phase_label: ?string,
     *     earliest_date: ?string,
     *     latest_date: ?string,
     *     campaigns_total: ?int,
     *     campaigns_done: ?int,
     *     adsets_total: ?int,
     *     adsets_done: ?int,
     *     ads_total: ?int,
     *     ads_done: ?int,
     *     chunks_total: ?int,
     *     chunks_done: ?int,
     *     daily_facts_count: int,
     *     last_error_category: ?string,
     *     last_error_summary: ?string,
     *     last_successful_at: ?string,
     * }>
     */
    public function accountRows(CoreIntegration $integration): array
    {
        $states = $this->discoveredStates($integration)->keyBy('core_external_resource_id');

        return $this->discoveredResources($integration)
            ->map(function (CoreExternalResource $resource) use ($states): array {
                $state = $states->get($resource->id);

                return [
                    'resource_id' => (int) $resource->id,
                    'external_id' => $resource->external_id,
                    'display_name' => $resource->display_name,
                    'status' => $state?->status ?? MetaAdsAccountImportState::STATUS_WAITING,
                    'phase_label' => $state?->phase_label,
                    'earliest_date' => $state?->earliest_date?->toDateString(),
                    'latest_date' => $state?->latest_date?->toDateString(),
                    'campaigns_total' => $state?->campaigns_total,
                    'campaigns_done' => $state?->campaigns_done,
                    'adsets_total' => $state?->adsets_total,
                    'adsets_done' => $state?->adsets_done,
                    'ads_total' => $state?->ads_total,
                    'ads_done' => $state?->ads_done,
                    'chunks_total' => $state?->chunks_total,
                    'chunks_done' => $state?->chunks_done,
                    'daily_facts_count' => (int) ($state?->daily_facts_count ?? 0),
                    'last_error_category' => $state?->last_error_category,
                    'last_error_summary' => $state?->last_error_summary,
                    'last_successful_at' => $state?->last_successful_at?->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Count of discovered accounts that have reached a terminal state
     * (ready|partial|failed|needs_attention). Used to compute accounts_done and to
     * decide when the parent Run can be finalized — never a loop index.
     */
    public function terminalCount(CoreIntegration $integration): int
    {
        return $this->discoveredStates($integration)
            ->whereIn('status', MetaAdsAccountImportState::TERMINAL_STATUSES)
            ->count();
    }

    /**
     * True when every discovered account has reached a terminal state.
     */
    public function allAccountsTerminal(CoreIntegration $integration): bool
    {
        $discovered = $this->authoritativeDiscoveredCount($integration);

        return $discovered > 0 && $this->terminalCount($integration) >= $discovered;
    }

    /**
     * Derives the parent Run's outcome purely from account states — never stale
     * metadata. `ready` / `discovered` here are guaranteed to match the DB.
     *
     * @return array{status: string, label: string, ready: int, discovered: int}
     */
    public function deriveRunOutcome(CoreIntegration $integration): array
    {
        $summary = $this->overallSummary($integration);
        $discovered = $summary['discovered'];
        $ready = $summary['ready'];
        $partial = $summary['partial'];
        $succeeded = $ready + $partial;

        [$status, $label] = match (true) {
            $succeeded === 0 => ['failed', 'Meta history import failed'],
            $ready < $discovered || $partial > 0 || $summary['failed'] > 0 => ['partial', 'Meta history import finished with gaps'],
            default => ['completed', 'Meta history import complete'],
        };

        return [
            'status' => $status,
            'label' => $label,
            'ready' => $ready,
            'discovered' => $discovered,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function persist(CoreExternalResource $resource, array $attributes): MetaAdsAccountImportState
    {
        $state = MetaAdsAccountImportState::query()->firstOrNew(
            ['core_external_resource_id' => $resource->id],
        );

        $state->core_integration_id = (int) $resource->integration_id;
        $state->fill($attributes);
        $state->save();

        return $state;
    }

    /**
     * Computes authoritative date bounds and entity/fact counts for one account from
     * the persisted historical store (facts + entities), not from job bookkeeping.
     *
     * @return array{
     *     earliest_date: ?string,
     *     latest_date: ?string,
     *     campaigns_total: int,
     *     campaigns_done: int,
     *     adsets_total: int,
     *     adsets_done: int,
     *     ads_total: int,
     *     ads_done: int,
     *     daily_facts_count: int,
     * }
     */
    private function hydrateFromStore(CoreExternalResource $resource): array
    {
        $facts = MetaAdsDailyFact::query()->where('core_external_resource_id', $resource->id);

        $campaigns = $this->entityCount($resource, MetaAdsEntity::TYPE_CAMPAIGN);
        $adsets = $this->entityCount($resource, MetaAdsEntity::TYPE_ADSET);
        $ads = $this->entityCount($resource, MetaAdsEntity::TYPE_AD);

        return [
            'earliest_date' => $facts->clone()->min('date'),
            'latest_date' => $facts->clone()->max('date'),
            'campaigns_total' => $campaigns,
            'campaigns_done' => $campaigns,
            'adsets_total' => $adsets,
            'adsets_done' => $adsets,
            'ads_total' => $ads,
            'ads_done' => $ads,
            'daily_facts_count' => (int) $facts->clone()->count(),
        ];
    }

    private function entityCount(CoreExternalResource $resource, string $type): int
    {
        return (int) MetaAdsEntity::query()
            ->where('core_external_resource_id', $resource->id)
            ->where('entity_type', $type)
            ->count();
    }

    /**
     * @return Collection<int, int>
     */
    private function discoveredResourceIds(CoreIntegration $integration): Collection
    {
        return CoreExternalResource::query()
            ->where('integration_id', $integration->id)
            ->where('resource_type', self::RESOURCE_TYPE)
            ->where('status', CoreExternalResource::STATUS_AVAILABLE)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id);
    }

    /**
     * @return Collection<int, CoreExternalResource>
     */
    private function discoveredResources(CoreIntegration $integration): Collection
    {
        return CoreExternalResource::query()
            ->where('integration_id', $integration->id)
            ->where('resource_type', self::RESOURCE_TYPE)
            ->where('status', CoreExternalResource::STATUS_AVAILABLE)
            ->orderBy('display_name')
            ->get();
    }

    /**
     * State rows for currently-discovered accounts only, so counts can never exceed
     * `discovered` (a retired account's stale row is excluded).
     *
     * @return Collection<int, MetaAdsAccountImportState>
     */
    private function discoveredStates(CoreIntegration $integration): Collection
    {
        return MetaAdsAccountImportState::query()
            ->where('core_integration_id', $integration->id)
            ->whereIn('core_external_resource_id', $this->discoveredResourceIds($integration)->all())
            ->get();
    }

    private function defaultPhaseLabel(string $status): string
    {
        return match ($status) {
            MetaAdsAccountImportState::STATUS_QUEUED => 'Queued',
            MetaAdsAccountImportState::STATUS_DISCOVERING => 'Discovering',
            MetaAdsAccountImportState::STATUS_FETCHING_METADATA => 'Fetching metadata',
            MetaAdsAccountImportState::STATUS_PREPARING_INSIGHTS => 'Preparing insights',
            MetaAdsAccountImportState::STATUS_WAITING_REPORT => 'Waiting for report',
            MetaAdsAccountImportState::STATUS_DOWNLOADING => 'Downloading',
            MetaAdsAccountImportState::STATUS_NORMALIZING => 'Normalizing',
            MetaAdsAccountImportState::STATUS_READY => 'Ready',
            MetaAdsAccountImportState::STATUS_PARTIAL => 'Imported with gaps',
            MetaAdsAccountImportState::STATUS_FAILED => 'Failed',
            MetaAdsAccountImportState::STATUS_NEEDS_ATTENTION => 'Needs attention',
            default => 'Waiting to start',
        };
    }
}
