<?php

namespace App\Support\Demo;

/**
 * Brand-level aggregate Demo business outcomes — no channel attribution claim.
 */
final class BusinessOutcomeFixtures
{
    /**
     * @return array<string, mixed>
     */
    public static function summary(string $period): array
    {
        $f = DemoCatalog::periodFactors($period);
        $factor = (float) ($f['results_factor'] ?? 1.0);

        $platformLeads = (int) round(142 * $factor);
        $qualifiedLeads = (int) round(38 * $factor);
        $consultations = (int) round(21 * $factor);
        $patients = (int) round(7 * max(0.85, $factor));

        $qualifiedRate = $platformLeads > 0
            ? round(($qualifiedLeads / $platformLeads) * 100, 1)
            : 0.0;

        return [
            'period' => $period,
            'period_label' => $f['label'] ?? $period,
            'platform_leads' => $platformLeads,
            'platform_leads_label' => __('operator.outcomes.platform_results'),
            'qualified_leads' => $qualifiedLeads,
            'qualified_leads_label' => __('operator.outcomes.qualified_leads'),
            'consultations' => $consultations,
            'consultations_label' => __('operator.outcomes.consultations'),
            'patients' => $patients,
            'patients_label' => __('operator.outcomes.patients'),
            'revenue' => null,
            'revenue_label' => __('operator.outcomes.revenue'),
            'revenue_display' => __('operator.outcomes.not_available'),
            'qualified_rate' => sprintf('%d / %d (%.1f%%)', $qualifiedLeads, $platformLeads, $qualifiedRate),
            'provenance' => 'Demo',
            'note' => __('operator.outcomes.brand_aggregate_note'),
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    public static function operationalOutcomes(): array
    {
        return [
            [
                'label' => 'Mobile LCP improved on implant landing',
                'detail' => 'Associated improvement after website task — not claiming causality.',
            ],
            [
                'label' => 'Meta CPL stabilized after creative refresh',
                'detail' => '14-day follow-up window complete.',
            ],
            [
                'label' => 'GSC indexing errors resolved',
                'detail' => '3 implant URLs returned to indexed state.',
            ],
        ];
    }
}
