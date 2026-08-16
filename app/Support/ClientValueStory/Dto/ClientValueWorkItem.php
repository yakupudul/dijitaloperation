<?php

namespace App\Support\ClientValueStory\Dto;

final class ClientValueWorkItem
{
    public function __construct(
        public readonly int $taskId,
        public readonly string $title,
        public readonly string $status,
        public readonly ?string $sourceKind,
        public readonly ?int $digitalAssetId,
        public readonly ?string $completedAt,
        public readonly ?string $createdAt,
        public readonly bool $isCompletedInPeriod,
        public readonly bool $isActiveInPeriod,
        public readonly ?string $qaStatus,
        public readonly bool $qaFailed,
        public readonly bool $approvalPending,
        public readonly ?int $recommendationId,
        public readonly ?int $findingId,
        public readonly ?int $opportunityId,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'task_id' => $this->taskId,
            'title' => $this->title,
            'status' => $this->status,
            'source_kind' => $this->sourceKind,
            'digital_asset_id' => $this->digitalAssetId,
            'completed_at' => $this->completedAt,
            'created_at' => $this->createdAt,
            'is_completed_in_period' => $this->isCompletedInPeriod,
            'is_active_in_period' => $this->isActiveInPeriod,
            'qa_status' => $this->qaStatus,
            'qa_failed' => $this->qaFailed,
            'approval_pending' => $this->approvalPending,
            'verified_success' => false,
            'client_approved' => false,
            'business_result' => false,
            'recommendation_id' => $this->recommendationId,
            'finding_id' => $this->findingId,
            'opportunity_id' => $this->opportunityId,
            'section' => 'work_performed',
            'causes_outcome' => false,
        ];
    }
}
