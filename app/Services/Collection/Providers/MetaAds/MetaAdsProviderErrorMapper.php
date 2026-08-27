<?php

namespace App\Services\Collection\Providers\MetaAds;

use App\Enums\Collection\CollectionErrorCategory;
use App\Services\Collection\Support\DatasetExecutionResult;
use App\Services\Integrations\Meta\MetaException;
use Throwable;

final class MetaAdsProviderErrorMapper
{
    private const DIAGNOSTIC_VERSION = 'meta-error-v2';

    public function fromThrowable(Throwable $e): DatasetExecutionResult
    {
        if ($e instanceof MetaException) {
            $message = $this->diagnosticMessage($e);
            $providerCode = $e->providerCode !== null ? '_'.$e->providerCode : '';

            return match ($e->kind) {
                MetaException::KIND_AUTH => DatasetExecutionResult::failed(
                    CollectionErrorCategory::Authentication,
                    $message,
                    'META_AUTH'.$providerCode,
                ),
                MetaException::KIND_PERMISSION => DatasetExecutionResult::failed(
                    CollectionErrorCategory::Authorization,
                    $message,
                    'META_PERMISSION'.$providerCode,
                ),
                MetaException::KIND_RATE_LIMIT => DatasetExecutionResult::retry(
                    CollectionErrorCategory::RateLimit,
                    $message,
                    60,
                    'META_RATE_LIMIT'.$providerCode,
                ),
                MetaException::KIND_TRANSPORT => DatasetExecutionResult::retry(
                    CollectionErrorCategory::Network,
                    $message,
                    30,
                    'META_NETWORK'.$providerCode,
                ),
                MetaException::KIND_HTTP => DatasetExecutionResult::retry(
                    CollectionErrorCategory::Provider5xx,
                    $message,
                    45,
                    'META_HTTP'.$providerCode,
                ),
                MetaException::KIND_CONFIG => DatasetExecutionResult::failed(
                    CollectionErrorCategory::Authentication,
                    $message,
                    'META_CONFIG'.$providerCode,
                ),
                default => DatasetExecutionResult::failed(
                    CollectionErrorCategory::Unknown,
                    $message,
                    'META_PROVIDER'.$providerCode,
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

    private function diagnosticMessage(MetaException $e): string
    {
        $message = trim($e->getMessage());
        if ($message === '') {
            $message = 'Meta provider error.';
        }

        $diagnostics = [self::DIAGNOSTIC_VERSION];
        if ($e->httpStatus !== null) {
            $diagnostics[] = 'http '.$e->httpStatus;
        }
        if ($e->providerCode !== null) {
            $diagnostics[] = 'code '.$e->providerCode;
        }

        return mb_substr($message.' · ['.implode(' · ', $diagnostics).']', 0, 900);
    }
}
