<?php

namespace App\Services\Collection\Providers\MetaAds;

use App\Enums\Collection\CollectionErrorCategory;
use App\Services\Collection\Support\DatasetExecutionResult;
use App\Services\Integrations\Meta\MetaException;
use Throwable;

final class MetaAdsProviderErrorMapper
{
    public function fromThrowable(Throwable $e): DatasetExecutionResult
    {
        if ($e instanceof MetaException) {
            return match ($e->kind) {
                MetaException::KIND_AUTH => DatasetExecutionResult::failed(
                    CollectionErrorCategory::Authentication,
                    'Meta authentication failed.',
                    'AUTHENTICATION',
                ),
                MetaException::KIND_PERMISSION => DatasetExecutionResult::failed(
                    CollectionErrorCategory::Authorization,
                    'Meta permission missing for analytical collection.',
                    'PERMISSION_REQUIRED',
                ),
                MetaException::KIND_RATE_LIMIT => DatasetExecutionResult::retry(
                    CollectionErrorCategory::RateLimit,
                    'Meta rate limited.',
                    60,
                    'RATE_LIMIT',
                ),
                MetaException::KIND_TRANSPORT => DatasetExecutionResult::retry(
                    CollectionErrorCategory::Network,
                    'Meta transport error.',
                    30,
                    'NETWORK',
                ),
                MetaException::KIND_HTTP => DatasetExecutionResult::retry(
                    CollectionErrorCategory::Provider5xx,
                    'Meta provider unavailable.',
                    45,
                    'PROVIDER_5XX',
                ),
                MetaException::KIND_CONFIG => DatasetExecutionResult::failed(
                    CollectionErrorCategory::Authentication,
                    $e->getMessage(),
                    'CONFIG',
                ),
                default => DatasetExecutionResult::failed(
                    CollectionErrorCategory::Unknown,
                    $e->getMessage() !== '' ? $e->getMessage() : 'Meta provider error.',
                    'PROVIDER',
                ),
            };
        }

        $message = $e->getMessage();
        $lower = strtolower($message);

        if (str_contains($lower, 'timeout') || str_contains($lower, 'timed out') || str_contains($lower, 'network')) {
            return DatasetExecutionResult::retry(
                CollectionErrorCategory::Network,
                $message,
                30,
                'NETWORK',
            );
        }

        if (str_contains($lower, 'unimplemented') || str_contains($lower, 'unknown meta ads request family')) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::UnimplementedCapability,
                $message,
                'UNIMPLEMENTED_CAPABILITY',
            );
        }

        return DatasetExecutionResult::failed(
            CollectionErrorCategory::Unknown,
            $message,
        );
    }
}
