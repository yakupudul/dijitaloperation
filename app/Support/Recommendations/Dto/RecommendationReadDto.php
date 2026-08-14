<?php

namespace App\Support\Recommendations\Dto;

use App\Enums\RecommendationSourceKind;
use App\Models\Recommendation;

/**
 * Safe Recommendation read DTO. Source provenance is explicit; no Demo shape leaks in.
 */
final readonly class RecommendationReadDto
{
    /**
     * @param  list<int>  $taskIds
     */
    public function __construct(
        public int $id,
        public ?RecommendationSourceKind $sourceKind,
        public ?int $findingId,
        public ?int $opportunityId,
        public ?int $digitalAssetId,
        public string $sourceModule,
        public ?string $origin,
        public ?string $idempotencyKey,
        public string $title,
        public ?string $action,
        public ?string $rationale,
        public string $priority,
        public ?string $effort,
        public string $status,
        public array $taskIds,
        public mixed $createdAt,
        public mixed $updatedAt,
        public ?RecommendationSourceViewData $source = null,
    ) {}

    public static function fromModel(Recommendation $recommendation, ?RecommendationSourceViewData $source = null): self
    {
        $taskIds = $recommendation->relationLoaded('tasks')
            ? $recommendation->tasks->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all()
            : [];

        return new self(
            id: (int) $recommendation->id,
            sourceKind: $recommendation->sourceKind(),
            findingId: $recommendation->finding_id === null ? null : (int) $recommendation->finding_id,
            opportunityId: $recommendation->opportunity_id === null ? null : (int) $recommendation->opportunity_id,
            digitalAssetId: $recommendation->digital_asset_id === null ? null : (int) $recommendation->digital_asset_id,
            sourceModule: (string) $recommendation->source_module,
            origin: $recommendation->origin,
            idempotencyKey: $recommendation->idempotency_key,
            title: (string) $recommendation->title,
            action: $recommendation->action,
            rationale: $recommendation->rationale,
            priority: (string) $recommendation->priority,
            effort: $recommendation->effort,
            status: (string) $recommendation->status,
            taskIds: $taskIds,
            createdAt: $recommendation->created_at,
            updatedAt: $recommendation->updated_at,
            source: $source,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'source_kind' => $this->sourceKind?->value,
            'finding_id' => $this->findingId,
            'opportunity_id' => $this->opportunityId,
            'digital_asset_id' => $this->digitalAssetId,
            'source_module' => $this->sourceModule,
            'origin' => $this->origin,
            'idempotency_key' => $this->idempotencyKey,
            'title' => $this->title,
            'action' => $this->action,
            'rationale' => $this->rationale,
            'priority' => $this->priority,
            'effort' => $this->effort,
            'status' => $this->status,
            'task_ids' => $this->taskIds,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'source' => $this->source?->toArray(),
        ];
    }
}
