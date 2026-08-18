<?php

namespace App\Support\Evidence;

/**
 * Frozen V1 Evidence Definition — what analytical statement can be made from which facts.
 *
 * @phpstan-type MetricField list<string>
 */
final class EvidenceDefinition
{
    /**
     * @param  list<string>  $metricFields
     * @param  list<string>  $formulaIds
     */
    public function __construct(
        public readonly string $id,
        public readonly string $statementKind,
        public readonly string $titleTemplate,
        public readonly string $sourceModule,
        public readonly string $provider,
        public readonly string $datasetId,
        public readonly string $physicalTable,
        public readonly string $resourceType,
        public readonly string $bindingCapability,
        public readonly string $grainColumn,
        public readonly array $metricFields,
        public readonly array $formulaIds,
        public readonly int $defaultPeriodDays,
    ) {}
}
