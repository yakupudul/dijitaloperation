<?php

namespace App\Services\Prospects;

use App\Enums\ProspectIdentityStatus;
use App\Enums\ProspectResearchRunStatus;
use App\Enums\ProspectSource;
use App\Enums\ProspectStatus;
use App\Models\Prospect;
use App\Models\ProspectActivity;
use App\Models\ProspectDiscoveryCandidate;
use App\Models\ProspectEvidence;
use App\Models\ProspectResearchRun;
use App\Models\ProspectSalesIntelligence;
use App\Support\Options\AgencyServiceOptions;

/**
 * Read model for Prospect operator UI.
 */
final class ProspectReadService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function listRows(): array
    {
        return Prospect::query()
            ->with(['owner', 'latestResearchRun', 'latestSalesIntelligence'])
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (Prospect $prospect): array => $this->listRow($prospect))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(Prospect $prospect): array
    {
        $prospect->load([
            'owner',
            'researchRuns' => fn ($q) => $q->orderByDesc('id')->limit(10),
            'evidence' => fn ($q) => $q->orderByDesc('observed_at'),
            'discoveryCandidates' => fn ($q) => $q->orderByDesc('id'),
            'salesIntelligence' => fn ($q) => $q->orderByDesc('id')->limit(5),
            'activities' => fn ($q) => $q->with('actor')->orderByDesc('occurred_at')->limit(50),
            'latestResearchRun',
            'latestSalesIntelligence',
        ]);

        return [
            'prospect' => $this->prospectCard($prospect),
            'research_runs' => $prospect->researchRuns->map(fn (ProspectResearchRun $run): array => $this->researchRunRow($run))->all(),
            'evidence' => $prospect->evidence->map(fn (ProspectEvidence $row): array => $this->evidenceRow($row))->all(),
            'candidates' => $prospect->discoveryCandidates->map(fn (ProspectDiscoveryCandidate $row): array => $this->candidateRow($row))->all(),
            'sales_intelligence' => $prospect->latestSalesIntelligence instanceof ProspectSalesIntelligence
                ? $this->salesIntelligenceRow($prospect->latestSalesIntelligence)
                : null,
            'sales_intelligence_history' => $prospect->salesIntelligence->map(fn (ProspectSalesIntelligence $row): array => $this->salesIntelligenceRow($row))->all(),
            'activities' => $prospect->activities->map(fn (ProspectActivity $row): array => $this->activityRow($row))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function listRow(Prospect $prospect): array
    {
        $run = $prospect->latestResearchRun;
        $intelligence = $prospect->latestSalesIntelligence;

        return [
            'id' => (string) $prospect->id,
            'company_name' => $prospect->company_name,
            'website_url' => $prospect->website_url,
            'source' => $prospect->source->value,
            'source_label' => __('operator.prospects.sources.'.$prospect->source->value),
            'status' => $prospect->status->value,
            'status_label' => __('operator.prospects.statuses.'.$prospect->status->value),
            'identity_status' => $prospect->identity_status->value,
            'identity_status_label' => __('operator.prospects.identity.'.$prospect->identity_status->value),
            'owner_name' => $prospect->owner?->name,
            'research_status' => $run?->status->value,
            'research_status_label' => $run ? __('operator.prospects.research_statuses.'.$run->status->value) : __('operator.prospects.research_statuses.none'),
            'research_at' => $run?->finished_at?->toIso8601String() ?? $run?->started_at?->toIso8601String(),
            'intelligence_status' => $intelligence?->status->value,
            'updated_at' => $prospect->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function prospectCard(Prospect $prospect): array
    {
        $run = $prospect->latestResearchRun;

        return [
            'id' => (string) $prospect->id,
            'company_name' => $prospect->company_name,
            'website_url' => $prospect->website_url,
            'source' => $prospect->source->value,
            'source_label' => __('operator.prospects.sources.'.$prospect->source->value),
            'inquiry' => $prospect->inquiry,
            'contact_name' => $prospect->contact_name,
            'contact_email' => $prospect->contact_email,
            'contact_phone' => $prospect->contact_phone,
            'country' => $prospect->country,
            'city' => $prospect->city,
            'identity_status' => $prospect->identity_status->value,
            'identity_status_label' => __('operator.prospects.identity.'.$prospect->identity_status->value),
            'status' => $prospect->status->value,
            'status_label' => __('operator.prospects.statuses.'.$prospect->status->value),
            'owner_user_id' => $prospect->owner_user_id ? (string) $prospect->owner_user_id : '',
            'owner_name' => $prospect->owner?->name,
            'research_status' => $run?->status->value,
            'research_status_label' => $run ? __('operator.prospects.research_statuses.'.$run->status->value) : __('operator.prospects.research_statuses.none'),
            'research_message' => is_array($run?->metadata) ? ($run->metadata['message'] ?? null) : null,
            'research_at' => $run?->finished_at?->toIso8601String() ?? $run?->started_at?->toIso8601String(),
            'can_research' => ! in_array($run?->status, [ProspectResearchRunStatus::Queued, ProspectResearchRunStatus::Running], true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function researchRunRow(ProspectResearchRun $run): array
    {
        return [
            'id' => (string) $run->id,
            'status' => $run->status->value,
            'status_label' => __('operator.prospects.research_statuses.'.$run->status->value),
            'seed_url' => $run->seed_url,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'message' => is_array($run->metadata) ? ($run->metadata['message'] ?? null) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function evidenceRow(ProspectEvidence $row): array
    {
        return [
            'id' => (string) $row->id,
            'type' => $row->type,
            'title' => $row->title,
            'source_url' => $row->source_url,
            'provenance' => $row->provenance->value,
            'provenance_label' => __('operator.prospects.provenance.'.$row->provenance->value),
            'observed_at' => $row->observed_at?->toIso8601String(),
            'payload' => $row->payload,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function candidateRow(ProspectDiscoveryCandidate $row): array
    {
        return [
            'id' => (string) $row->id,
            'kind' => $row->candidate_kind,
            'type' => $row->candidate_type,
            'value' => $row->proposed_value,
            'provenance' => $row->provenance->value,
            'provenance_label' => __('operator.prospects.provenance.'.$row->provenance->value),
            'source_url' => data_get($row->support_json, 'source_url'),
            'support_label' => $row->support_label,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function salesIntelligenceRow(ProspectSalesIntelligence $row): array
    {
        $recommended = collect(is_array($row->recommended_services) ? $row->recommended_services : [])
            ->map(function (array $service): array {
                $code = (string) ($service['service_definition_code'] ?? '');
                $service['service_definition_label'] = $service['service_definition_label']
                    ?? ($code !== '' ? AgencyServiceOptions::label($code) : $code);

                return $service;
            })
            ->all();

        $notRecommended = collect(is_array($row->not_recommended_services) ? $row->not_recommended_services : [])
            ->map(function (array $service): array {
                $code = (string) ($service['service_definition_code'] ?? '');
                $service['service_definition_label'] = $service['service_definition_label']
                    ?? ($code !== '' ? AgencyServiceOptions::label($code) : $code);

                return $service;
            })
            ->all();

        return [
            'id' => (string) $row->id,
            'status' => $row->status->value,
            'status_label' => __('operator.prospects.intelligence_statuses.'.$row->status->value),
            'summary' => $row->summary,
            'detected_needs' => $row->detected_needs ?? [],
            'recommended_services' => $recommended,
            'not_recommended_services' => $notRecommended,
            'sales_priorities' => $row->sales_priorities ?? [],
            'first_meeting_focus' => $row->first_meeting_focus,
            'diagnostic_questions' => $row->diagnostic_questions ?? [],
            'suggested_positioning' => $row->suggested_positioning,
            'uncertainties' => $row->uncertainties ?? [],
            'overall_confidence' => $row->overall_confidence,
            'created_at' => $row->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function activityRow(ProspectActivity $row): array
    {
        return [
            'id' => (string) $row->id,
            'type' => $row->type,
            'title' => $row->title,
            'description' => $row->description,
            'actor_name' => $row->actor?->name,
            'occurred_at' => $row->occurred_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        $out = [];
        foreach (ProspectStatus::ordered() as $status) {
            $out[$status->value] = __('operator.prospects.statuses.'.$status->value);
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    public static function identityOptions(): array
    {
        $out = [];
        foreach (ProspectIdentityStatus::ordered() as $status) {
            $out[$status->value] = __('operator.prospects.identity.'.$status->value);
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    public static function sourceOptions(): array
    {
        $out = [];
        foreach (ProspectSource::ordered() as $source) {
            $out[$source->value] = __('operator.prospects.sources.'.$source->value);
        }

        return $out;
    }
}
