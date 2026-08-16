<?php

namespace App\Support\ClientValueStory\Dto;

use App\Enums\BusinessOutcomeKind;
use App\Enums\ClientValueStoryLimitation;
use App\Enums\ClientValueStoryStatus;

/**
 * Typed Client Value Story read projection (Prompt 58).
 * Not a writable domain. Not a Report Snapshot.
 */
final class ClientValueStory
{
    /**
     * @param  list<ClientValueFindingItem>  $findings
     * @param  list<ClientValueOpportunityItem>  $opportunities
     * @param  list<ClientValueWorkItem>  $completedWork
     * @param  list<ClientValueWorkItem>  $activeWork
     * @param  list<ClientValueOutcomeItem>  $outcomes
     * @param  list<ClientValueStoryLimitation>  $limitations
     * @param  list<ClientValueStoryClaim>  $claims
     */
    public function __construct(
        public readonly int $customerId,
        public readonly int $brandId,
        public readonly string $periodStart,
        public readonly string $periodEnd,
        public readonly string $periodLabel,
        public readonly ClientValueStoryStatus $status,
        public readonly array $findings,
        public readonly array $opportunities,
        public readonly array $completedWork,
        public readonly array $activeWork,
        public readonly array $outcomes,
        public readonly array $limitations,
        public readonly ClientValueStorySourceManifest $sourceManifest,
        public readonly array $claims,
        public readonly string $generatedAt,
        public readonly string $causationDisclaimer,
        public readonly bool $attributionEstablished = false,
    ) {}

    /**
     * Frozen Brand → Value overview counters (deterministic).
     *
     * @return array<string, mixed>
     */
    public function toSummaryArray(): array
    {
        $business = $this->businessOutcomesPresentation();

        return [
            'period' => $this->periodStart.'→'.$this->periodEnd,
            'period_label' => $this->periodLabel,
            'observed' => count($this->findings),
            'decided' => 0,
            'delivered' => count($this->completedWork),
            'operational_outcomes' => 0,
            'business' => $business,
            'open_opportunities' => count(array_filter(
                $this->opportunities,
                static fn (ClientValueOpportunityItem $o): bool => $o->isPotential,
            )),
            'next' => count($this->activeWork),
            'demo' => false,
            'provenance' => 'client_value_story',
            'status' => $this->status->value,
            'attribution_established' => false,
            'causation_established' => false,
        ];
    }

    /**
     * Frozen `_value-story` blade shape — layout preserved, real sources.
     *
     * @return array<string, mixed>
     */
    public function toPresentationArray(): array
    {
        $observations = array_map(static function (ClientValueFindingItem $f): array {
            return [
                'id' => 'finding-'.$f->findingId,
                'text' => $f->title,
                'source_type' => 'finding',
                'source_label' => 'Finding',
                'source_url' => route('demo.findings'),
                'finding_id' => $f->findingId,
                'severity' => $f->severity,
                'status' => $f->status,
                'period_role' => $f->periodRole,
            ];
        }, $this->findings);

        $completedWork = array_map(static function (ClientValueWorkItem $w): array {
            $suffix = '';
            if ($w->qaFailed) {
                $suffix = ' (QA failed — not verified success)';
            } elseif ($w->approvalPending) {
                $suffix = ' (approval pending — not client-approved)';
            }

            return [
                'id' => 'task-'.$w->taskId,
                'text' => $w->title.$suffix,
                'source_url' => route('demo.task', ['taskId' => $w->taskId]),
                'task_id' => $w->taskId,
                'source_kind' => $w->sourceKind,
                'qa_status' => $w->qaStatus,
                'approval_pending' => $w->approvalPending,
                'verified_success' => false,
                'business_result' => false,
            ];
        }, $this->completedWork);

        $opportunities = array_map(static function (ClientValueOpportunityItem $o): array {
            return [
                'id' => (string) $o->opportunityId,
                'title' => $o->title,
                'goal' => $o->goalLabel ?? '—',
                'service' => $o->serviceLabel ?? '',
                'source_url' => route('demo.opportunities', ['view' => 'open']),
                'status' => $o->status,
                'potential' => true,
                'realized_value' => false,
            ];
        }, $this->opportunities);

        $nextActions = array_map(static function (ClientValueWorkItem $w): array {
            return [
                'id' => 'active-'.$w->taskId,
                'text' => $w->title,
                'source_url' => route('demo.task', ['taskId' => $w->taskId]),
            ];
        }, $this->activeWork);

        return [
            'brand_id' => (string) $this->brandId,
            'customer_id' => $this->customerId,
            'period' => $this->periodStart.'→'.$this->periodEnd,
            'period_label' => $this->periodLabel,
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'locale' => app()->getLocale(),
            'observations' => $observations,
            'decisions' => [],
            'completed_work' => $completedWork,
            'operational_changes' => [],
            'business_outcomes' => $this->businessOutcomesPresentation(),
            'opportunities' => $opportunities,
            'next_actions' => $nextActions,
            'ai_assisted' => false,
            'causation_disclaimer' => $this->causationDisclaimer,
            'attribution_established' => false,
            'limitations' => array_map(
                static fn (ClientValueStoryLimitation $l): string => $l->value,
                $this->limitations,
            ),
            'claims' => array_map(
                static fn (ClientValueStoryClaim $c): array => $c->toArray(),
                $this->claims,
            ),
            'source_manifest' => $this->sourceManifest->toArray(),
            'status' => $this->status->value,
            'demo' => false,
            'generated_at' => $this->generatedAt,
            'empty_sections' => [
                'findings' => $this->findings === [],
                'opportunities' => $this->opportunities === [],
                'completed_work' => $this->completedWork === [],
                'outcomes' => ! $this->hasAnyOutcomeData(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function businessOutcomesPresentation(): array
    {
        $byKind = [];
        foreach ($this->outcomes as $outcome) {
            $byKind[$outcome->kind->value] = $outcome;
        }

        $ql = $byKind[BusinessOutcomeKind::QualifiedLead->value] ?? null;
        $consult = $byKind[BusinessOutcomeKind::Consultation->value] ?? null;
        $patient = $byKind[BusinessOutcomeKind::SaleOrPatient->value] ?? null;
        $revenue = $byKind[BusinessOutcomeKind::Revenue->value] ?? null;

        $available = $this->hasAnyOutcomeData();

        return [
            'available' => $available,
            'platform_leads' => null,
            'qualified_leads' => $ql?->displayValue(),
            'consultations' => $consult?->displayValue(),
            'patients' => $patient?->displayValue(),
            'revenue' => $revenue?->value,
            'revenue_display' => $revenue !== null && $revenue->value !== null
                ? trim(($revenue->currencyCode ?? '').' '.$revenue->value)
                : __('operator.outcomes.not_available'),
            'qualified_rate' => null,
            'provenance' => 'business_outcome',
            'note' => __('operator.outcomes.brand_aggregate_note'),
            'unavailable_message' => $available
                ? null
                : 'No reported Business Outcome data is available for this period.',
            'cards' => array_map(
                static fn (ClientValueOutcomeItem $o): array => $o->toArray(),
                $this->outcomes,
            ),
        ];
    }

    public function hasAnyOutcomeData(): bool
    {
        foreach ($this->outcomes as $outcome) {
            if ($outcome->value !== null) {
                return true;
            }
        }

        return false;
    }
}
