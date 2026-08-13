<?php

namespace App\Services\Collection\Providers\Ga4;

use App\Enums\Collection\CollectionErrorCategory;
use App\Exceptions\Integrations\GoogleAuthenticationException;
use App\Exceptions\Integrations\GoogleAuthorizationException;
use App\Services\Collection\Support\DatasetExecutionResult;
use Illuminate\Http\Client\Response;
use Throwable;

final class Ga4ProviderErrorMapper
{
    public function fromThrowable(Throwable $e): DatasetExecutionResult
    {
        if ($e instanceof GoogleAuthorizationException) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Authorization,
                $e->getMessage(),
                'SCOPE_REQUIRED',
            );
        }

        if ($e instanceof GoogleAuthenticationException) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Authentication,
                $e->getMessage(),
                'AUTHENTICATION_REQUIRED',
            );
        }

        if (str_contains(strtolower($e->getMessage()), 'network')) {
            return DatasetExecutionResult::retry(
                CollectionErrorCategory::Network,
                $e->getMessage(),
                30,
                'NETWORK',
            );
        }

        return DatasetExecutionResult::failed(
            CollectionErrorCategory::Unknown,
            $e->getMessage(),
        );
    }

    public function fromHttpResponse(Response $response): DatasetExecutionResult
    {
        $status = $response->status();
        $body = $response->json();
        $reason = is_array($body)
            ? (string) (data_get($body, 'error.message') ?? $response->body())
            : $response->body();
        $reason = mb_substr($reason, 0, 500);
        $retryAfter = $response->header('Retry-After');
        $backoff = is_numeric($retryAfter) ? max(1, (int) $retryAfter) : 60;
        $lower = strtolower($reason);

        if ($status === 401) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Authentication,
                'GA4 authentication failed: '.$reason,
                'AUTHENTICATION',
            );
        }

        if ($status === 403) {
            if (str_contains($lower, 'quota') || str_contains($lower, 'rate') || str_contains($lower, 'exhausted')) {
                return DatasetExecutionResult::retry(
                    CollectionErrorCategory::Quota,
                    'GA4 quota/rate limit: '.$reason,
                    $backoff,
                    'QUOTA_EXHAUSTED',
                );
            }

            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Authorization,
                'GA4 authorization failed: '.$reason,
                'AUTHORIZATION',
            );
        }

        if ($status === 429) {
            return DatasetExecutionResult::retry(
                CollectionErrorCategory::RateLimit,
                'GA4 rate limit: '.$reason,
                $backoff,
                'RATE_LIMIT',
            );
        }

        if ($status >= 500) {
            return DatasetExecutionResult::retry(
                CollectionErrorCategory::Provider5xx,
                'GA4 provider 5xx: '.$reason,
                $backoff,
                'PROVIDER_5XX',
            );
        }

        if ($status === 400) {
            if (str_contains($lower, 'incompatible') || str_contains($lower, 'compatibility')) {
                return DatasetExecutionResult::failed(
                    CollectionErrorCategory::ContractMismatch,
                    'GA4 provider incompatible request: '.$reason,
                    'PROVIDER_INCOMPATIBLE',
                );
            }

            return DatasetExecutionResult::failed(
                CollectionErrorCategory::InvalidRequest,
                'GA4 invalid request: '.$reason,
                'INVALID_REQUEST',
            );
        }

        return DatasetExecutionResult::failed(
            CollectionErrorCategory::Unknown,
            'GA4 HTTP '.$status.': '.$reason,
            'HTTP_'.$status,
        );
    }
}
