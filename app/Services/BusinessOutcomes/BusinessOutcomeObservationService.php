<?php

namespace App\Services\BusinessOutcomes;

use App\Enums\BusinessOutcomeCompleteness;
use App\Enums\BusinessOutcomeDefinitionStatus;
use App\Enums\BusinessOutcomeObservationStatus;
use App\Enums\BusinessOutcomeSourceKind;
use App\Enums\BusinessOutcomeUnit;
use App\Models\Brand;
use App\Models\BusinessOutcomeDefinition;
use App\Models\BusinessOutcomeObservation;
use App\Models\BusinessOutcomeObservationRevision;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Canonical Business Outcome Observation write boundary (manual + import).
 * Aggregate only. No CRM. No provider mapping. No AI.
 */
final class BusinessOutcomeObservationService
{
    public const string ERROR_CORRECTION_REQUIRED = 'BUSINESS_OUTCOME_CORRECTION_REQUIRED';

    /**
     * @param  array<string, mixed>  $input
     */
    public function record(
        Brand $brand,
        BusinessOutcomeDefinition $definition,
        array $input,
        ?User $actor = null,
        BusinessOutcomeSourceKind $source = BusinessOutcomeSourceKind::Manual,
        bool $allowCorrection = false,
    ): BusinessOutcomeObservation {
        $this->assertDefinitionBelongs($brand, $definition);

        $periodStart = $this->parseDate((string) ($input['period_start'] ?? ''), 'period_start');
        $periodEnd = $this->parseDate((string) ($input['period_end'] ?? ''), 'period_end');
        if ($periodEnd->lt($periodStart)) {
            throw ValidationException::withMessages(['period_end' => 'END_BEFORE_START']);
        }

        $completeness = BusinessOutcomeCompleteness::tryFrom(strtolower((string) ($input['completeness'] ?? '')));
        if ($completeness === null) {
            throw ValidationException::withMessages(['completeness' => 'INVALID_COMPLETENESS']);
        }

        [$valueNumeric, $valueCount, $currency] = $this->normalizeValue($definition, $input);

        $semanticKey = $this->semanticKey($periodStart, $periodEnd);
        $fingerprint = $this->rowFingerprint(
            brandId: (int) $brand->id,
            definitionId: (int) $definition->id,
            periodStart: $periodStart->toDateString(),
            periodEnd: $periodEnd->toDateString(),
            value: (string) $valueNumeric,
            currency: $currency,
            completeness: $completeness->value,
            source: $source->value,
        );

        return DB::transaction(function () use (
            $brand,
            $definition,
            $periodStart,
            $periodEnd,
            $completeness,
            $valueNumeric,
            $valueCount,
            $currency,
            $semanticKey,
            $fingerprint,
            $actor,
            $source,
            $allowCorrection,
            $input,
        ): BusinessOutcomeObservation {
            $this->assertNoOverlap(
                definitionId: (int) $definition->id,
                periodStart: $periodStart,
                periodEnd: $periodEnd,
                exceptSemanticKey: $semanticKey,
            );

            $observation = BusinessOutcomeObservation::query()
                ->where('definition_id', $definition->id)
                ->where('semantic_key', $semanticKey)
                ->lockForUpdate()
                ->first();

            if ($observation === null) {
                $observation = BusinessOutcomeObservation::query()->create([
                    'customer_id' => $brand->customer_id,
                    'brand_id' => $brand->id,
                    'definition_id' => $definition->id,
                    'period_start' => $periodStart->toDateString(),
                    'period_end' => $periodEnd->toDateString(),
                    'status' => BusinessOutcomeObservationStatus::Active,
                    'semantic_key' => $semanticKey,
                ]);

                $revision = $this->createRevision(
                    $observation,
                    $definition,
                    $valueNumeric,
                    $valueCount,
                    $currency,
                    $completeness,
                    $source,
                    $actor,
                    $fingerprint,
                    $input,
                    null,
                    1,
                );
                $observation->forceFill(['current_revision_id' => $revision->id])->save();

                return $observation->refresh()->load('currentRevision');
            }

            if ($observation->status !== BusinessOutcomeObservationStatus::Active) {
                throw ValidationException::withMessages(['observation' => 'OBSERVATION_INVALIDATED']);
            }

            $current = $observation->currentRevision;
            if ($current !== null && $current->row_fingerprint === $fingerprint) {
                return $observation->load('currentRevision');
            }

            if ($current !== null && ! $allowCorrection) {
                throw ValidationException::withMessages([
                    'value' => self::ERROR_CORRECTION_REQUIRED,
                ]);
            }

            $reason = trim((string) ($input['correction_reason'] ?? ''));
            if ($allowCorrection && $reason === '') {
                throw ValidationException::withMessages(['correction_reason' => 'CORRECTION_REASON_REQUIRED']);
            }

            $nextNumber = (int) $observation->revisions()->max('revision_number') + 1;
            $revision = $this->createRevision(
                $observation,
                $definition,
                $valueNumeric,
                $valueCount,
                $currency,
                $completeness,
                $source,
                $actor,
                $fingerprint,
                $input,
                $allowCorrection ? $reason : null,
                $nextNumber,
            );
            $observation->forceFill(['current_revision_id' => $revision->id])->save();

            return $observation->refresh()->load('currentRevision');
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function correct(
        BusinessOutcomeObservation $observation,
        array $input,
        ?User $actor = null,
        BusinessOutcomeSourceKind $source = BusinessOutcomeSourceKind::Manual,
    ): BusinessOutcomeObservation {
        $observation->loadMissing('definition', 'brand');

        return $this->record(
            brand: $observation->brand,
            definition: $observation->definition,
            input: array_merge([
                'period_start' => $observation->period_start->toDateString(),
                'period_end' => $observation->period_end->toDateString(),
            ], $input),
            actor: $actor,
            source: $source,
            allowCorrection: true,
        );
    }

    public function invalidate(BusinessOutcomeObservation $observation): BusinessOutcomeObservation
    {
        $observation->forceFill([
            'status' => BusinessOutcomeObservationStatus::Invalidated,
        ])->save();

        return $observation->refresh();
    }

    public function semanticKey(CarbonImmutable $start, CarbonImmutable $end): string
    {
        return hash('sha256', $start->toDateString().'|'.$end->toDateString());
    }

    public function rowFingerprint(
        int $brandId,
        int $definitionId,
        string $periodStart,
        string $periodEnd,
        string $value,
        ?string $currency,
        string $completeness,
        string $source,
    ): string {
        return hash('sha256', implode('|', [
            $brandId,
            $definitionId,
            $periodStart,
            $periodEnd,
            $value,
            $currency ?? '',
            $completeness,
            $source,
        ]));
    }

    private function assertDefinitionBelongs(Brand $brand, BusinessOutcomeDefinition $definition): void
    {
        if ((int) $definition->brand_id !== (int) $brand->id
            || (int) $definition->customer_id !== (int) $brand->customer_id) {
            throw ValidationException::withMessages(['definition_id' => 'DEFINITION_NOT_IN_BRAND']);
        }

        if ($definition->status !== BusinessOutcomeDefinitionStatus::Active) {
            throw ValidationException::withMessages(['definition_id' => 'DEFINITION_NOT_ACTIVE']);
        }
    }

    private function parseDate(string $value, string $field): CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw ValidationException::withMessages([$field => 'INVALID_PERIOD']);
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => 'INVALID_PERIOD']);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: string, 1: ?int, 2: ?string}
     */
    private function normalizeValue(BusinessOutcomeDefinition $definition, array $input): array
    {
        if (! array_key_exists('value', $input) || $input['value'] === null || $input['value'] === '') {
            throw ValidationException::withMessages(['value' => 'VALUE_REQUIRED']);
        }

        $raw = is_string($input['value']) ? trim($input['value']) : $input['value'];

        if ($definition->unit === BusinessOutcomeUnit::Count) {
            if (is_string($raw) && ! preg_match('/^\d+$/', $raw)) {
                throw ValidationException::withMessages(['value' => 'COUNT_MUST_BE_INTEGER']);
            }
            if (! is_int($raw) && ! (is_string($raw) && ctype_digit($raw))) {
                if (is_float($raw) || (is_string($raw) && str_contains($raw, '.'))) {
                    throw ValidationException::withMessages(['value' => 'COUNT_MUST_BE_INTEGER']);
                }
                throw ValidationException::withMessages(['value' => 'COUNT_MUST_BE_INTEGER']);
            }
            $count = (int) $raw;
            if ($count < 0) {
                throw ValidationException::withMessages(['value' => 'NEGATIVE_NOT_ALLOWED']);
            }

            return [(string) $count, $count, null];
        }

        if (is_string($raw) && ! preg_match('/^\d+(\.\d{1,4})?$/', $raw)) {
            throw ValidationException::withMessages(['value' => 'INVALID_REVENUE_FORMAT']);
        }
        $numeric = (string) $raw;
        if (bccomp($numeric, '0', 4) < 0) {
            throw ValidationException::withMessages(['value' => 'NEGATIVE_NOT_ALLOWED']);
        }

        $currency = isset($input['currency'])
            ? strtoupper(trim((string) $input['currency']))
            : ($definition->currency_code !== null ? strtoupper($definition->currency_code) : null);

        if ($currency === null || strlen($currency) !== 3) {
            throw ValidationException::withMessages(['currency' => 'REVENUE_CURRENCY_REQUIRED']);
        }
        if ($definition->currency_code !== null && strtoupper($definition->currency_code) !== $currency) {
            throw ValidationException::withMessages(['currency' => 'CURRENCY_MISMATCH']);
        }

        return [bcadd($numeric, '0', 4), null, $currency];
    }

    private function assertNoOverlap(
        int $definitionId,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
        string $exceptSemanticKey,
    ): void {
        $overlap = BusinessOutcomeObservation::query()
            ->where('definition_id', $definitionId)
            ->where('status', BusinessOutcomeObservationStatus::Active)
            ->where('semantic_key', '!=', $exceptSemanticKey)
            ->where('period_start', '<=', $periodEnd->toDateString())
            ->where('period_end', '>=', $periodStart->toDateString())
            ->lockForUpdate()
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages(['period' => 'OVERLAPPING_PERIOD']);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function createRevision(
        BusinessOutcomeObservation $observation,
        BusinessOutcomeDefinition $definition,
        string $valueNumeric,
        ?int $valueCount,
        ?string $currency,
        BusinessOutcomeCompleteness $completeness,
        BusinessOutcomeSourceKind $source,
        ?User $actor,
        string $fingerprint,
        array $input,
        ?string $correctionReason,
        int $revisionNumber,
    ): BusinessOutcomeObservationRevision {
        $note = isset($input['source_note']) ? trim((string) $input['source_note']) : null;
        if ($note === '') {
            $note = null;
        }

        return BusinessOutcomeObservationRevision::query()->create([
            'observation_id' => $observation->id,
            'revision_number' => $revisionNumber,
            'value_numeric' => $valueNumeric,
            'value_count' => $valueCount,
            'currency_code' => $currency,
            'completeness' => $completeness,
            'source_kind' => $source,
            'recorded_by' => $actor?->id,
            'recorded_at' => now(),
            'correction_reason' => $correctionReason,
            'import_batch_id' => $input['import_batch_id'] ?? null,
            'import_row_number' => $input['import_row_number'] ?? null,
            'row_fingerprint' => $fingerprint,
            'source_note' => $note,
            'definition_version_snapshot' => $definition->definition_version,
            'semantic_definition_snapshot' => $definition->semantic_definition,
        ]);
    }
}
