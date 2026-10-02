<?php

namespace App\Services\Collection\Monitoring;

use App\Enums\Collection\CollectionErrorCategory;

final class CollectionErrorPresenter
{
    /**
     * @return array{category: ?string, title: string, message: ?string, automatic_retry: bool, operator_action_required: bool}
     */
    public function present(?CollectionErrorCategory $category, ?string $message, bool $willAutoRetry): array
    {
        $safeMessage = $this->sanitize($message);

        return [
            'category' => $category?->value,
            'title' => $this->title($category),
            'message' => $safeMessage,
            'automatic_retry' => $willAutoRetry,
            'operator_action_required' => $this->requiresOperatorAction($category) && ! $willAutoRetry,
        ];
    }

    public function sanitize(?string $message): ?string
    {
        if ($message === null || $message === '') {
            return null;
        }

        $clean = preg_replace('/\b(Bearer\s+[A-Za-z0-9\-._~+\/]+=*|access_token|refresh_token|client_secret)\b/i', '[redacted]', $message) ?? $message;
        $clean = preg_replace('/\s+/', ' ', $clean) ?? $clean;

        return mb_substr(trim($clean), 0, 500);
    }

    private function title(?CollectionErrorCategory $category): string
    {
        return match ($category) {
            CollectionErrorCategory::RateLimit => __('operator.collection.errors.rate_limit'),
            CollectionErrorCategory::Quota => __('operator.collection.errors.quota'),
            CollectionErrorCategory::Timeout => __('operator.collection.errors.timeout'),
            CollectionErrorCategory::Network => __('operator.collection.errors.network'),
            CollectionErrorCategory::Provider5xx => __('operator.collection.errors.provider_5xx'),
            CollectionErrorCategory::Authentication => __('operator.collection.errors.authentication'),
            CollectionErrorCategory::Authorization => __('operator.collection.errors.authorization'),
            CollectionErrorCategory::ContractMismatch => __('operator.collection.errors.contract_mismatch'),
            CollectionErrorCategory::UnimplementedCapability => __('operator.collection.errors.unimplemented'),
            CollectionErrorCategory::InvalidRequest => __('operator.collection.errors.invalid_request'),
            CollectionErrorCategory::Normalization => __('operator.collection.errors.normalization'),
            CollectionErrorCategory::Persistence => __('operator.collection.errors.persistence'),
            CollectionErrorCategory::Cancelled => __('operator.collection.errors.cancelled'),
            default => __('operator.collection.errors.unknown'),
        };
    }

    private function requiresOperatorAction(?CollectionErrorCategory $category): bool
    {
        return in_array($category, [
            CollectionErrorCategory::Authentication,
            CollectionErrorCategory::Authorization,
            CollectionErrorCategory::ContractMismatch,
            CollectionErrorCategory::UnimplementedCapability,
            CollectionErrorCategory::InvalidRequest,
        ], true);
    }
}
