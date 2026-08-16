<?php

namespace App\Services\BusinessOutcomes;

use App\Enums\BusinessOutcomeDefinitionStatus;
use App\Enums\BusinessOutcomeKind;
use App\Enums\BusinessOutcomeObservationStatus;
use App\Models\Brand;
use App\Models\BusinessOutcomeDefinition;
use App\Models\BusinessOutcomeObservation;
use App\Support\BusinessOutcomes\Dto\BusinessOutcomeAggregateResult;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Canonical Business Outcome read boundary — no provider/AI calls.
 */
final class BusinessOutcomeReadService
{
    public function __construct(
        private readonly BusinessOutcomeAggregateService $aggregates,
    ) {}

    /**
     * @return list<BusinessOutcomeDefinition>
     */
    public function listActiveDefinitions(Brand $brand): array
    {
        return BusinessOutcomeDefinition::query()
            ->where('brand_id', $brand->id)
            ->where('customer_id', $brand->customer_id)
            ->where('status', BusinessOutcomeDefinitionStatus::Active)
            ->orderBy('kind')
            ->get()
            ->all();
    }

    public function findActiveDefinitionByCode(Brand $brand, string $code): ?BusinessOutcomeDefinition
    {
        return BusinessOutcomeDefinition::query()
            ->where('brand_id', $brand->id)
            ->where('customer_id', $brand->customer_id)
            ->where('status', BusinessOutcomeDefinitionStatus::Active)
            ->where('code', $code)
            ->first();
    }

    public function findActiveDefinitionByKind(Brand $brand, BusinessOutcomeKind $kind): ?BusinessOutcomeDefinition
    {
        return BusinessOutcomeDefinition::query()
            ->where('brand_id', $brand->id)
            ->where('customer_id', $brand->customer_id)
            ->where('status', BusinessOutcomeDefinitionStatus::Active)
            ->where('kind', $kind->value)
            ->first();
    }

    /**
     * @return LengthAwarePaginator<int, BusinessOutcomeObservation>
     */
    public function paginateObservations(Brand $brand, ?int $definitionId = null, int $perPage = 25): LengthAwarePaginator
    {
        $query = BusinessOutcomeObservation::query()
            ->with(['currentRevision', 'definition'])
            ->where('brand_id', $brand->id)
            ->where('customer_id', $brand->customer_id)
            ->orderByDesc('period_start');

        if ($definitionId !== null) {
            $query->where('definition_id', $definitionId);
        }

        return $query->paginate($perPage);
    }

    public function getObservationForBrand(Brand $brand, int $observationId): ?BusinessOutcomeObservation
    {
        return BusinessOutcomeObservation::query()
            ->with(['currentRevision', 'definition', 'revisions'])
            ->where('brand_id', $brand->id)
            ->where('customer_id', $brand->customer_id)
            ->whereKey($observationId)
            ->first();
    }

    public function aggregate(
        Brand $brand,
        BusinessOutcomeKind $kind,
        string $start,
        string $end,
    ): BusinessOutcomeAggregateResult {
        $definition = $this->findActiveDefinitionByKind($brand, $kind);
        if ($definition === null) {
            return $this->aggregates->aggregateByKind((int) $brand->id, $kind, $start, $end);
        }

        return $this->aggregates->aggregate($definition, $start, $end);
    }

    /**
     * Brand → Value outcomes card projection (Prompt 57 data; Prompt 58 owns story).
     *
     * @return array<string, mixed>
     */
    public function forValueSurface(Brand $brand, string $start, string $end): array
    {
        $cards = [];
        foreach (BusinessOutcomeKind::cases() as $kind) {
            $result = $this->aggregate($brand, $kind, $start, $end);
            $cards[$kind->value] = [
                'kind' => $kind->value,
                'label' => $kind->defaultLabel(),
                'value' => $result->value,
                'unit' => $result->unit->value,
                'currency_code' => $result->currencyCode,
                'status' => $result->status->value,
                'completeness' => $result->worstCompleteness?->value,
                'available' => $result->value !== null,
                'limitations' => $result->limitations,
            ];
        }

        $any = collect($cards)->contains(fn (array $c): bool => $c['available'] === true);

        return [
            'available' => $any,
            'period_start' => $start,
            'period_end' => $end,
            'qualified_leads' => $cards[BusinessOutcomeKind::QualifiedLead->value]['value'],
            'consultations' => $cards[BusinessOutcomeKind::Consultation->value]['value'],
            'patients' => $cards[BusinessOutcomeKind::SaleOrPatient->value]['value'],
            'revenue' => $cards[BusinessOutcomeKind::Revenue->value]['value'],
            'revenue_display' => $cards[BusinessOutcomeKind::Revenue->value]['available']
                ? ($cards[BusinessOutcomeKind::Revenue->value]['currency_code'].' '.$cards[BusinessOutcomeKind::Revenue->value]['value'])
                : __('operator.outcomes.not_available'),
            'cards' => $cards,
            'empty_message' => $any ? null : 'No reported Business Outcome data for this period.',
            'demo' => false,
            'provenance' => 'business_outcome',
            'platform_leads' => null,
            'platform_leads_note' => 'Platform leads are provider metrics, not Business Outcomes.',
        ];
    }

    /**
     * @return list<BusinessOutcomeObservation>
     */
    public function activeObservationsForDefinition(int $definitionId): array
    {
        return BusinessOutcomeObservation::query()
            ->with('currentRevision')
            ->where('definition_id', $definitionId)
            ->where('status', BusinessOutcomeObservationStatus::Active)
            ->orderBy('period_start')
            ->get()
            ->all();
    }
}
