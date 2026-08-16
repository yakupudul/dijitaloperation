<?php

namespace App\Support\ReportSnapshots;

use App\Enums\ReportSnapshotSchemaVersion;
use App\Enums\ReportType;
use App\Services\ClientValueStory\ClientValueStoryReadService;

/**
 * Bounded report-type registry (Prompt 59).
 * No arbitrary SQL / widget / PHP report builders.
 */
final class ReportTypeRegistry
{
    /**
     * @return array<string, array{
     *     id: string,
     *     display_label: string,
     *     allowed_scope: string,
     *     snapshot_schema: string,
     *     source_read_service: class-string,
     *     source_manifest_type: string,
     *     comparison_supported: bool,
     *     delivery_may_be_supported_later: bool,
     *     default_presentation_contract: string
     * }>
     */
    public function all(): array
    {
        return [
            ReportType::ClientValueStory->value => [
                'id' => ReportType::ClientValueStory->value,
                'display_label' => ReportType::ClientValueStory->displayLabel(),
                'allowed_scope' => 'brand',
                'snapshot_schema' => ReportSnapshotSchemaVersion::ClientValueStoryV1->value,
                'source_read_service' => ClientValueStoryReadService::class,
                'source_manifest_type' => 'client_value_story_manifest_v1',
                'comparison_supported' => false,
                'delivery_may_be_supported_later' => true,
                'default_presentation_contract' => 'client_value_story_presentation_v1',
            ],
        ];
    }

    public function has(string $reportType): bool
    {
        return ReportType::tryFrom($reportType) !== null && isset($this->all()[$reportType]);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $reportType): array
    {
        if (! $this->has($reportType)) {
            throw new \InvalidArgumentException('UNSUPPORTED_REPORT_TYPE');
        }

        return $this->all()[$reportType];
    }
}
