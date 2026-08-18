<?php

namespace App\Services\Prospects;

use App\Enums\ProspectReportProjection;
use App\Enums\ProspectSalesIntelligenceStatus;
use App\Models\Prospect;
use App\Models\ProspectEvidence;
use App\Models\ProspectSalesIntelligence;
use App\Support\Options\AgencyServiceOptions;

/**
 * Strict allowlisted projections for Prospect pre-analysis.
 * Internal and client-shareable are never built from one uncontrolled template.
 */
final class ProspectReportProjectionService
{
    /**
     * Internal fields that must never appear in a client-shareable payload.
     *
     * @var list<string>
     */
    public const CLIENT_FORBIDDEN_KEYS = [
        'identity_status',
        'inquiry',
        'source',
        'contact_name',
        'contact_email',
        'contact_phone',
        'sales_priorities',
        'first_meeting_focus',
        'diagnostic_questions',
        'suggested_positioning',
        'not_recommended_services',
        'overall_confidence',
        'uncertainties',
        'internal_notes',
        'qualification',
        'how_to_sell',
        'objection_handling',
        'hidden_reasoning',
    ];

    /**
     * @return array<string, mixed>
     */
    public function internal(Prospect $prospect): array
    {
        $prospect->loadMissing(['latestResearchRun', 'latestSalesIntelligence', 'evidence', 'owner']);
        $intelligence = $prospect->latestSalesIntelligence;
        $run = $prospect->latestResearchRun;

        return [
            'projection' => ProspectReportProjection::Internal->value,
            'company_name' => $prospect->company_name,
            'analysis_date' => now()->toDateString(),
            'identity_status' => $prospect->identity_status->value,
            'source' => $prospect->source->value,
            'inquiry' => $prospect->inquiry,
            'contact_name' => $prospect->contact_name,
            'contact_email' => $prospect->contact_email,
            'contact_phone' => $prospect->contact_phone,
            'website_url' => $prospect->website_url,
            'owner_name' => $prospect->owner?->name,
            'research_status' => $run?->status->value,
            'research_summary' => is_array($run?->metadata) ? ($run->metadata['message'] ?? null) : null,
            'observed_facts' => $this->observedFacts($prospect),
            'detected_needs' => $this->intelligenceList($intelligence, 'detected_needs'),
            'recommended_services' => $this->recommended($intelligence),
            'not_recommended_services' => $this->notRecommended($intelligence),
            'sales_priorities' => $this->intelligenceList($intelligence, 'sales_priorities'),
            'first_meeting_focus' => $intelligence?->first_meeting_focus,
            'diagnostic_questions' => $this->intelligenceList($intelligence, 'diagnostic_questions'),
            'suggested_positioning' => $intelligence?->suggested_positioning,
            'uncertainties' => $this->intelligenceList($intelligence, 'uncertainties'),
            'overall_confidence' => $intelligence?->overall_confidence,
            'intelligence_status' => $intelligence?->status->value,
            'internal_notes' => null,
            'evidence_references' => $this->evidenceReferences($prospect),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function clientShareable(Prospect $prospect): array
    {
        $prospect->loadMissing(['latestSalesIntelligence', 'evidence', 'latestResearchRun']);
        $intelligence = $prospect->latestSalesIntelligence;

        $payload = [
            'projection' => ProspectReportProjection::ClientShareable->value,
            'company_name' => $prospect->company_name,
            'analysis_date' => now()->toDateString(),
            'website_url' => $prospect->website_url,
            'public_digital_situation' => $this->publicDigitalSituation($prospect, $intelligence),
            'observed_findings' => $this->observedFacts($prospect),
            'important_opportunities' => $this->clientOpportunities($intelligence),
            'recommended_priorities' => $this->clientPriorities($intelligence),
            'suggested_next_steps' => $this->clientNextSteps($intelligence),
            'source_references' => $this->evidenceReferences($prospect),
        ];

        foreach (self::CLIENT_FORBIDDEN_KEYS as $key) {
            unset($payload[$key]);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function assertClientSafe(array $payload): void
    {
        foreach (self::CLIENT_FORBIDDEN_KEYS as $key) {
            if (array_key_exists($key, $payload)) {
                throw new \InvalidArgumentException('CLIENT_PROJECTION_LEAK:'.$key);
            }
        }
    }

    /**
     * @return list<array{title: string, source_url: ?string, provenance: string}>
     */
    private function observedFacts(Prospect $prospect): array
    {
        return $prospect->evidence
            ->map(fn (ProspectEvidence $row): array => [
                'title' => $row->title,
                'source_url' => $row->source_url,
                'provenance' => $row->provenance->value,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{title: string, source_url: ?string}>
     */
    private function evidenceReferences(Prospect $prospect): array
    {
        return $prospect->evidence
            ->filter(fn (ProspectEvidence $row): bool => is_string($row->source_url) && $row->source_url !== '')
            ->map(fn (ProspectEvidence $row): array => [
                'title' => $row->title,
                'source_url' => $row->source_url,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function intelligenceList(?ProspectSalesIntelligence $intelligence, string $field): array
    {
        if (! $intelligence instanceof ProspectSalesIntelligence) {
            return [];
        }

        $value = $intelligence->{$field};

        return is_array($value) ? array_values(array_map('strval', $value)) : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recommended(?ProspectSalesIntelligence $intelligence): array
    {
        if (! $intelligence instanceof ProspectSalesIntelligence || ! is_array($intelligence->recommended_services)) {
            return [];
        }

        return array_values($intelligence->recommended_services);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function notRecommended(?ProspectSalesIntelligence $intelligence): array
    {
        if (! $intelligence instanceof ProspectSalesIntelligence || ! is_array($intelligence->not_recommended_services)) {
            return [];
        }

        return array_values($intelligence->not_recommended_services);
    }

    private function publicDigitalSituation(Prospect $prospect, ?ProspectSalesIntelligence $intelligence): string
    {
        if ($prospect->website_url) {
            if ($intelligence instanceof ProspectSalesIntelligence && is_string($intelligence->summary) && $intelligence->summary !== '') {
                return $intelligence->summary;
            }

            return __('operator.prospects.reports.public_situation_with_site', ['url' => $prospect->website_url]);
        }

        return __('operator.prospects.reports.public_situation_without_site');
    }

    /**
     * @return list<array{title: string, explanation: string}>
     */
    private function clientOpportunities(?ProspectSalesIntelligence $intelligence): array
    {
        $out = [];
        foreach ($this->recommended($intelligence) as $service) {
            $code = (string) ($service['service_definition_code'] ?? '');
            $out[] = [
                'title' => AgencyServiceOptions::label($code),
                'explanation' => (string) ($service['rationale'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function clientPriorities(?ProspectSalesIntelligence $intelligence): array
    {
        $out = [];
        foreach ($this->recommended($intelligence) as $service) {
            $code = (string) ($service['service_definition_code'] ?? '');
            if ($code !== '') {
                $out[] = AgencyServiceOptions::label($code);
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function clientNextSteps(?ProspectSalesIntelligence $intelligence): array
    {
        if (! $intelligence instanceof ProspectSalesIntelligence || $intelligence->status !== ProspectSalesIntelligenceStatus::Available) {
            return [
                __('operator.prospects.reports.next_step_review_public'),
            ];
        }

        return [
            __('operator.prospects.reports.next_step_review_priorities'),
            __('operator.prospects.reports.next_step_share_findings'),
        ];
    }
}
