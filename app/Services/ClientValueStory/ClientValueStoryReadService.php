<?php

namespace App\Services\ClientValueStory;

use App\Enums\ClientValueStoryClaimType;
use App\Enums\ClientValueStoryLimitation;
use App\Enums\ClientValueStoryStatus;
use App\Models\AdvisorItem;
use App\Models\Brand;
use App\Models\Finding;
use App\Models\Opportunity;
use App\Models\SeoTask;
use App\Models\Task;
use App\Services\Tasks\TaskReadService;
use App\Support\ClientValueStory\Dto\ClientValueFindingItem;
use App\Support\ClientValueStory\Dto\ClientValueOpportunityItem;
use App\Support\ClientValueStory\Dto\ClientValueStory;
use App\Support\ClientValueStory\Dto\ClientValueStoryClaim;
use App\Support\ClientValueStory\Dto\ClientValueStorySourceManifest;
use App\Support\ClientValueStory\Dto\ClientValueWorkItem;
use App\Support\Tasks\TaskStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Canonical Client Value Story composition boundary (Prompt 58).
 *
 * Read projection only — zero domain writes, zero provider calls, zero AI.
 * No attribution / causality claims.
 */
final class ClientValueStoryReadService
{
    public function __construct(
        private readonly TaskReadService $tasks,
    ) {}

    /**
     * @param  list<int>  $authorizedCustomerIds
     * @param  list<int>  $authorizedBrandIds
     */
    public function forBrand(
        Brand $brand,
        string $periodStart,
        string $periodEnd,
        array $authorizedCustomerIds = [],
        array $authorizedBrandIds = [],
    ): ClientValueStory {
        $this->assertPeriod($periodStart, $periodEnd);
        $this->assertAuthorized($brand, $authorizedCustomerIds, $authorizedBrandIds);

        $start = CarbonImmutable::parse($periodStart)->startOfDay();
        $end = CarbonImmutable::parse($periodEnd)->endOfDay();
        $periodLabel = $start->toDateString().' → '.$end->toDateString();

        $findings = $this->projectFindings($brand, $start, $end);
        $opportunities = $this->projectOpportunities($brand, $start, $end);
        [$completedWork, $activeWork] = $this->projectWork($brand, $start, $end);
        $limitations = $this->resolveLimitations($findings, $opportunities, $completedWork);
        $claims = $this->buildClaims($findings, $opportunities, $completedWork, $activeWork, $limitations);
        $manifest = $this->buildManifest($brand, $start->toDateString(), $end->toDateString(), $findings, $opportunities, $completedWork, $activeWork, $limitations);
        $status = $this->resolveStatus($findings, $opportunities, $completedWork, $limitations);

        return new ClientValueStory(
            customerId: (int) $brand->customer_id,
            brandId: (int) $brand->id,
            periodStart: $start->toDateString(),
            periodEnd: CarbonImmutable::parse($periodEnd)->toDateString(),
            periodLabel: $periodLabel,
            status: $status,
            findings: $findings,
            opportunities: $opportunities,
            completedWork: $completedWork,
            activeWork: $activeWork,
            outcomes: [],
            limitations: $limitations,
            sourceManifest: $manifest,
            claims: $claims,
            generatedAt: now()->toIso8601String(),
            causationDisclaimer: 'Observed / performed during the selected period — causation and marketing attribution are not established.',
            attributionEstablished: false,
            measuredWork: $this->projectMeasuredWork($brand, $start, $end),
        );
    }

    /**
     * Advisor items and SEO tasks marked "Yapıldı" in the period, or measured in the period, with the
     * observed 28-day before/after change of the metric they came from. Observation, not attribution.
     *
     * @return list<array{id: string, text: string, channel: string, result: ?string, measured: bool}>
     */
    private function projectMeasuredWork(Brand $brand, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $inPeriod = static function ($query) use ($start, $end): void {
            $query->whereBetween('resolved_at', [$start, $end])->orWhereBetween('measured_at', [$start, $end]);
        };
        $rows = [];
        $channelLabels = ['google_ads' => 'Google Ads', 'meta_ads' => 'Meta Ads', 'google_business_profile' => 'İşletme Profili', 'cross_channel' => 'Kanallar arası'];
        if (Schema::hasTable('advisor_items') && Schema::hasColumn('advisor_items', 'outcome')) {
            foreach (AdvisorItem::query()->where('brand_id', $brand->id)->where('status', 'done')->where($inPeriod)->orderBy('resolved_at')->limit(50)->get() as $item) {
                $rows[] = ['id' => 'advisor-'.$item->id, 'text' => (string) $item->title, 'channel' => $channelLabels[$item->channel] ?? $item->channel] + $this->outcomeText($item->outcome, $item->measured_at !== null);
            }
        }
        if (Schema::hasTable('seo_tasks') && Schema::hasColumn('seo_tasks', 'outcome')) {
            foreach (SeoTask::query()->where('brand_id', $brand->id)->where('status', 'done')->where($inPeriod)->orderBy('resolved_at')->limit(50)->get() as $task) {
                $rows[] = ['id' => 'seo-'.$task->id, 'text' => (string) $task->title, 'channel' => 'Web / SEO'] + $this->outcomeText($task->outcome, $task->measured_at !== null);
            }
        }

        return $rows;
    }

    /** @return array{result: ?string, measured: bool} */
    private function outcomeText(mixed $outcome, bool $measuredAtSet): array
    {
        $outcome = is_array($outcome) ? $outcome : [];
        if (($outcome['status'] ?? null) !== 'measured') {
            return ['result' => $measuredAtSet ? 'Yapıldı; bu iş için ölçülebilir bir metrik yok.' : 'Yapıldı; etkisi 28 gün sonra ölçülecek.', 'measured' => false];
        }
        $format = static fn (mixed $v): string => ($outcome['unit'] ?? '') === 'money'
            ? number_format((float) $v, 0, ',', '.').' '.($outcome['currency'] ?? '')
            : number_format((float) $v, 0, ',', '.');

        return [
            'result' => sprintf(
                '%s: %s → %s (%d gün önce / sonra%s). Gözlenen değişimdir; tek başına bu işe bağlanamaz.',
                $outcome['metric'] ?? 'Metrik',
                trim($format($outcome['before'] ?? 0)),
                trim($format($outcome['after'] ?? 0)),
                (int) ($outcome['days'] ?? 28),
                isset($outcome['change_pct']) && $outcome['change_pct'] !== null ? sprintf(', %%%+d', (int) $outcome['change_pct']) : '',
            ),
            'measured' => true,
        ];
    }

    /**
     * @return list<ClientValueFindingItem>
     */
    private function projectFindings(Brand $brand, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = Finding::query()
            ->where('brand_id', $brand->id)
            ->where('customer_id', $brand->customer_id)
            ->where(function ($q) use ($start, $end): void {
                $q->whereBetween('first_seen_at', [$start, $end])
                    ->orWhereBetween('last_seen_at', [$start, $end])
                    ->orWhereBetween('resolved_at', [$start, $end])
                    ->orWhere(function ($inner) use ($start, $end): void {
                        $inner->where('first_seen_at', '<=', $end)
                            ->where(function ($open) use ($start): void {
                                $open->whereNull('resolved_at')
                                    ->orWhere('resolved_at', '>=', $start);
                            });
                    });
            })
            ->orderByDesc('last_seen_at')
            ->orderBy('id')
            ->limit(200)
            ->get();

        $items = [];
        foreach ($rows as $finding) {
            $first = $finding->first_seen_at ? CarbonImmutable::parse($finding->first_seen_at) : null;
            $resolved = $finding->resolved_at ? CarbonImmutable::parse($finding->resolved_at) : null;
            $role = 'relevant';
            if ($first !== null && $first->between($start, $end, true)) {
                $role = 'created_in_period';
            }
            if ($resolved !== null && $resolved->between($start, $end, true)) {
                $role = $role === 'created_in_period' ? 'created_and_resolved_in_period' : 'resolved_in_period';
            }

            $items[] = new ClientValueFindingItem(
                findingId: (int) $finding->id,
                title: (string) $finding->title,
                severity: (string) $finding->severity,
                status: (string) $finding->status,
                digitalAssetId: $finding->digital_asset_id !== null ? (int) $finding->digital_asset_id : null,
                firstSeenAt: $finding->first_seen_at?->toIso8601String(),
                lastSeenAt: $finding->last_seen_at?->toIso8601String(),
                resolvedAt: $finding->resolved_at?->toIso8601String(),
                periodRole: $role,
                latestEvaluationId: $finding->latest_evaluation_id !== null ? (int) $finding->latest_evaluation_id : null,
                historicalCertaintyLimited: $finding->first_seen_at === null,
            );
        }

        return $items;
    }

    /**
     * @return list<ClientValueOpportunityItem>
     */
    private function projectOpportunities(Brand $brand, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = Opportunity::query()
            ->with(['brandGoal', 'brandOffering.primaryName'])
            ->where('brand_id', $brand->id)
            ->where('customer_id', $brand->customer_id)
            ->where(function ($q) use ($start, $end): void {
                $q->whereBetween('first_detected_at', [$start, $end])
                    ->orWhereBetween('last_detected_at', [$start, $end])
                    ->orWhereBetween('closed_at', [$start, $end])
                    ->orWhere(function ($inner) use ($start, $end): void {
                        $inner->where('first_detected_at', '<=', $end)
                            ->where(function ($open) use ($start): void {
                                $open->whereNull('closed_at')
                                    ->orWhere('closed_at', '>=', $start);
                            });
                    });
            })
            ->orderByRaw("CASE qualitative_priority WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 ELSE 4 END")
            ->orderByDesc('last_detected_at')
            ->orderBy('id')
            ->limit(200)
            ->get();

        $items = [];
        foreach ($rows as $opportunity) {
            $service = $opportunity->service_definition_code;
            $items[] = new ClientValueOpportunityItem(
                opportunityId: (int) $opportunity->id,
                title: (string) $opportunity->title,
                status: (string) $opportunity->status,
                qualitativePriority: $opportunity->qualitative_priority !== null ? (string) $opportunity->qualitative_priority : null,
                digitalAssetId: $opportunity->digital_asset_id !== null ? (int) $opportunity->digital_asset_id : null,
                goalLabel: $opportunity->brandGoal?->label,
                serviceLabel: $service !== null ? (string) $service : null,
                firstDetectedAt: $opportunity->first_detected_at?->toIso8601String(),
                lastDetectedAt: $opportunity->last_detected_at?->toIso8601String(),
                closedAt: $opportunity->closed_at?->toIso8601String(),
                isPotential: true,
                realizedValue: false,
            );
        }

        return $items;
    }

    /**
     * @return array{0: list<ClientValueWorkItem>, 1: list<ClientValueWorkItem>}
     */
    private function projectWork(Brand $brand, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $tasks = Task::query()
            ->with(['recommendation:id,finding_id,opportunity_id'])
            ->where('brand_id', $brand->id)
            ->where('customer_id', $brand->customer_id)
            ->where(function ($q) use ($start, $end): void {
                $q->whereBetween('completed_at', [$start, $end])
                    ->orWhere(function ($active) use ($end): void {
                        $active->where('created_at', '<=', $end)
                            ->whereIn('status', [
                                TaskStatus::OPEN,
                                TaskStatus::IN_PROGRESS,
                                TaskStatus::BLOCKED,
                            ]);
                    });
            })
            ->orderByDesc('completed_at')
            ->orderByDesc('created_at')
            ->orderBy('id')
            ->limit(300)
            ->get();

        $projections = $this->tasks->batchProjectionsForStory($tasks->all());

        $completed = [];
        $active = [];
        foreach ($tasks as $task) {
            $projection = $projections[(int) $task->id] ?? ['qa' => null, 'approval' => null];
            $qa = $projection['qa'];
            $approval = $projection['approval'];
            $qaStatus = is_array($qa) ? ($qa['result'] ?? $qa['status'] ?? null) : null;
            $qaFailed = is_string($qaStatus) && in_array(strtolower($qaStatus), ['failed', 'fail', 'rejected'], true);
            $approvalPending = is_array($approval)
                && ($approval['status'] ?? null) === 'pending'
                && (bool) ($approval['is_current_for_subject'] ?? true);

            $completedAt = $task->completed_at ? CarbonImmutable::parse($task->completed_at) : null;
            $isCompletedInPeriod = $task->status === TaskStatus::COMPLETED
                && $completedAt !== null
                && $completedAt->between($start, $end, true);

            $isActiveInPeriod = ! $isCompletedInPeriod
                && in_array($task->status, [
                    TaskStatus::OPEN,
                    TaskStatus::IN_PROGRESS,
                    TaskStatus::BLOCKED,
                ], true);

            $item = new ClientValueWorkItem(
                taskId: (int) $task->id,
                title: (string) $task->title,
                status: (string) $task->status,
                sourceKind: $task->source_kind?->value ?? (is_string($task->source_kind) ? $task->source_kind : null),
                digitalAssetId: $task->digital_asset_id !== null ? (int) $task->digital_asset_id : null,
                completedAt: $task->completed_at?->toIso8601String(),
                createdAt: $task->created_at?->toIso8601String(),
                isCompletedInPeriod: $isCompletedInPeriod,
                isActiveInPeriod: $isActiveInPeriod,
                qaStatus: is_string($qaStatus) ? $qaStatus : null,
                qaFailed: $qaFailed,
                approvalPending: $approvalPending,
                recommendationId: $task->recommendation_id !== null ? (int) $task->recommendation_id : null,
                findingId: $task->recommendation?->finding_id !== null ? (int) $task->recommendation->finding_id : null,
                opportunityId: $task->recommendation?->opportunity_id !== null ? (int) $task->recommendation->opportunity_id : null,
            );

            if ($isCompletedInPeriod) {
                $completed[] = $item;
            } elseif ($isActiveInPeriod) {
                $active[] = $item;
            }
        }

        return [$completed, $active];
    }

    /**
     * @param  list<ClientValueFindingItem>  $findings
     * @param  list<ClientValueOpportunityItem>  $opportunities
     * @param  list<ClientValueWorkItem>  $completedWork
     * @return list<ClientValueStoryLimitation>
     */
    private function resolveLimitations(array $findings, array $opportunities, array $completedWork): array
    {
        $limitations = [ClientValueStoryLimitation::NoCanonicalAttribution];

        if ($findings === []) {
            $limitations[] = ClientValueStoryLimitation::NoFindingsInPeriod;
        }
        foreach ($findings as $finding) {
            if ($finding->historicalCertaintyLimited) {
                $limitations[] = ClientValueStoryLimitation::HistoricalFindingStateLimited;
                break;
            }
        }

        if ($opportunities === []) {
            $limitations[] = ClientValueStoryLimitation::NoOpportunitiesInPeriod;
        }

        if ($completedWork === []) {
            $limitations[] = ClientValueStoryLimitation::NoCompletedWorkInPeriod;
        }

        return array_values(array_unique($limitations, SORT_REGULAR));
    }

    /**
     * @param  list<ClientValueFindingItem>  $findings
     * @param  list<ClientValueOpportunityItem>  $opportunities
     * @param  list<ClientValueWorkItem>  $completedWork
     * @param  list<ClientValueWorkItem>  $activeWork
     * @param  list<ClientValueStoryLimitation>  $limitations
     * @return list<ClientValueStoryClaim>
     */
    private function buildClaims(
        array $findings,
        array $opportunities,
        array $completedWork,
        array $activeWork,
        array $limitations,
    ): array {
        $claims = [];
        $findingCount = count($findings);
        $claims[] = new ClientValueStoryClaim(
            ClientValueStoryClaimType::FindingsIdentified,
            $findingCount === 1
                ? '1 Finding was identified during the selected period.'
                : "{$findingCount} Findings were identified during the selected period.",
            ['count' => $findingCount],
        );

        $resolved = count(array_filter($findings, static fn (ClientValueFindingItem $f): bool => str_contains($f->periodRole, 'resolved')));
        if ($resolved > 0) {
            $claims[] = new ClientValueStoryClaim(
                ClientValueStoryClaimType::FindingsResolved,
                $resolved === 1
                    ? '1 Finding was resolved during the selected period.'
                    : "{$resolved} Findings were resolved during the selected period.",
                ['count' => $resolved],
            );
        }

        $oppCount = count($opportunities);
        $claims[] = new ClientValueStoryClaim(
            ClientValueStoryClaimType::OpportunitiesIdentified,
            $oppCount === 1
                ? '1 Opportunity was present as potential during the selected period.'
                : "{$oppCount} Opportunities were present as potential during the selected period.",
            ['count' => $oppCount],
        );

        $workCount = count($completedWork);
        $claims[] = new ClientValueStoryClaim(
            ClientValueStoryClaimType::WorkCompleted,
            $workCount === 1
                ? '1 work item was completed during the selected period.'
                : "{$workCount} work items were completed during the selected period.",
            ['count' => $workCount],
        );

        if ($activeWork !== []) {
            $activeCount = count($activeWork);
            $claims[] = new ClientValueStoryClaim(
                ClientValueStoryClaimType::WorkInProgress,
                $activeCount === 1
                    ? '1 work item was active during the selected period.'
                    : "{$activeCount} work items were active during the selected period.",
                ['count' => $activeCount],
            );
        }

        $claims[] = new ClientValueStoryClaim(
            ClientValueStoryClaimType::DataLimitation,
            'No canonical marketing attribution is available. Temporal coexistence does not establish causality.',
            ['codes' => array_map(static fn (ClientValueStoryLimitation $l): string => $l->value, $limitations)],
        );

        return $claims;
    }

    /**
     * @param  list<ClientValueFindingItem>  $findings
     * @param  list<ClientValueOpportunityItem>  $opportunities
     * @param  list<ClientValueWorkItem>  $completedWork
     * @param  list<ClientValueWorkItem>  $activeWork
     * @param  list<ClientValueStoryLimitation>  $limitations
     */
    private function buildManifest(
        Brand $brand,
        string $start,
        string $end,
        array $findings,
        array $opportunities,
        array $completedWork,
        array $activeWork,
        array $limitations,
    ): ClientValueStorySourceManifest {
        $taskIds = array_values(array_unique(array_merge(
            array_map(static fn (ClientValueWorkItem $w): int => $w->taskId, $completedWork),
            array_map(static fn (ClientValueWorkItem $w): int => $w->taskId, $activeWork),
        )));

        return new ClientValueStorySourceManifest(
            customerId: (int) $brand->customer_id,
            brandId: (int) $brand->id,
            periodStart: $start,
            periodEnd: $end,
            findingIds: array_map(static fn (ClientValueFindingItem $f): int => $f->findingId, $findings),
            opportunityIds: array_map(static fn (ClientValueOpportunityItem $o): int => $o->opportunityId, $opportunities),
            taskIds: $taskIds,
            outcomeDefinitionIds: [],
            outcomeObservationRevisionIds: [],
            limitationCodes: array_map(static fn (ClientValueStoryLimitation $l): string => $l->value, $limitations),
        );
    }

    /**
     * @param  list<ClientValueFindingItem>  $findings
     * @param  list<ClientValueOpportunityItem>  $opportunities
     * @param  list<ClientValueWorkItem>  $completedWork
     * @param  list<ClientValueStoryLimitation>  $limitations
     */
    private function resolveStatus(
        array $findings,
        array $opportunities,
        array $completedWork,
        array $limitations,
    ): ClientValueStoryStatus {
        $hasAny = $findings !== [] || $opportunities !== [] || $completedWork !== [];

        if (! $hasAny) {
            return ClientValueStoryStatus::Unavailable;
        }

        $partialCodes = [
            ClientValueStoryLimitation::PartialOutcomeCoverage,
            ClientValueStoryLimitation::UnknownOutcomeCompleteness,
            ClientValueStoryLimitation::HistoricalFindingStateLimited,
            ClientValueStoryLimitation::MixedCurrencyNotComparable,
        ];
        foreach ($limitations as $limitation) {
            if (in_array($limitation, $partialCodes, true)) {
                return ClientValueStoryStatus::Partial;
            }
        }

        return ClientValueStoryStatus::Complete;
    }

    private function assertPeriod(string $start, string $end): void
    {
        try {
            $from = CarbonImmutable::parse($start)->startOfDay();
            $to = CarbonImmutable::parse($end)->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['period' => 'INVALID_STORY_PERIOD']);
        }

        if ($from->greaterThan($to)) {
            throw ValidationException::withMessages(['period' => 'INVALID_STORY_PERIOD']);
        }
    }

    /**
     * @param  list<int>  $authorizedCustomerIds
     * @param  list<int>  $authorizedBrandIds
     */
    private function assertAuthorized(Brand $brand, array $authorizedCustomerIds, array $authorizedBrandIds): void
    {
        if ($authorizedBrandIds !== [] && ! in_array((int) $brand->id, array_map('intval', $authorizedBrandIds), true)) {
            throw ValidationException::withMessages(['brand' => 'UNAUTHORIZED_BRAND']);
        }
        if ($authorizedCustomerIds !== [] && ! in_array((int) $brand->customer_id, array_map('intval', $authorizedCustomerIds), true)) {
            throw ValidationException::withMessages(['customer' => 'UNAUTHORIZED_CUSTOMER']);
        }
    }

    /**
     * Guard used by tests / integrity checks: story assembly never writes domains.
     *
     * @return array{findings: int, opportunities: int, tasks: int}
     */
    public function domainWriteProbe(): array
    {
        return [
            'findings' => (int) DB::table('findings')->count(),
            'opportunities' => (int) DB::table('opportunities')->count(),
            'tasks' => (int) DB::table('tasks')->count(),
        ];
    }
}
