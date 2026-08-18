<?php

namespace App\Services\Collection\Monitoring;

use App\Enums\Collection\CollectionRunStatus;

/**
 * Centralized domain status → operator presentation mapping.
 */
final class CollectionStatusPresenter
{
    /**
     * @return array{key: string, label: string, tone: string, icon: string, terminal: bool, retryable: bool}
     */
    public function present(CollectionRunStatus $status): array
    {
        return match ($status) {
            CollectionRunStatus::Queued => [
                'key' => $status->value,
                'label' => __('operator.collection.status.queued'),
                'tone' => 'slate',
                'icon' => 'clock',
                'terminal' => false,
                'retryable' => false,
            ],
            CollectionRunStatus::Running => [
                'key' => $status->value,
                'label' => __('operator.collection.status.running'),
                'tone' => 'blue',
                'icon' => 'arrow-path',
                'terminal' => false,
                'retryable' => false,
            ],
            CollectionRunStatus::Retrying => [
                'key' => $status->value,
                'label' => __('operator.collection.status.retrying'),
                'tone' => 'amber',
                'icon' => 'arrow-path',
                'terminal' => false,
                'retryable' => false,
            ],
            CollectionRunStatus::Completed => [
                'key' => $status->value,
                'label' => __('operator.collection.status.completed'),
                'tone' => 'emerald',
                'icon' => 'check-circle',
                'terminal' => true,
                'retryable' => false,
            ],
            CollectionRunStatus::Partial => [
                'key' => $status->value,
                'label' => __('operator.collection.status.partial'),
                'tone' => 'amber',
                'icon' => 'exclamation-triangle',
                'terminal' => true,
                'retryable' => true,
            ],
            CollectionRunStatus::Failed => [
                'key' => $status->value,
                'label' => __('operator.collection.status.failed'),
                'tone' => 'rose',
                'icon' => 'x-circle',
                'terminal' => true,
                'retryable' => true,
            ],
            CollectionRunStatus::CancellationRequested => [
                'key' => $status->value,
                'label' => __('operator.collection.status.cancelling'),
                'tone' => 'amber',
                'icon' => 'no-symbol',
                'terminal' => false,
                'retryable' => false,
            ],
            CollectionRunStatus::Cancelled => [
                'key' => $status->value,
                'label' => __('operator.collection.status.cancelled'),
                'tone' => 'slate',
                'icon' => 'no-symbol',
                'terminal' => true,
                'retryable' => false,
            ],
            CollectionRunStatus::Skipped => [
                'key' => $status->value,
                'label' => __('operator.collection.status.skipped'),
                'tone' => 'slate',
                'icon' => 'minus-circle',
                'terminal' => true,
                'retryable' => false,
            ],
            CollectionRunStatus::NotEligible => [
                'key' => $status->value,
                'label' => __('operator.collection.status.not_eligible'),
                'tone' => 'slate',
                'icon' => 'minus-circle',
                'terminal' => true,
                'retryable' => false,
            ],
        };
    }
}
