<?php

namespace App\Services\Collection\Providers\GoogleAds;

use App\Enums\Collection\CollectionErrorCategory;
use App\Exceptions\Integrations\GoogleAuthenticationException;
use App\Exceptions\Integrations\GoogleAuthorizationException;
use App\Services\Collection\Support\DatasetExecutionResult;
use Illuminate\Http\Client\Response;
use Throwable;

final class GoogleAdsProviderErrorMapper
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

        $message = $e->getMessage();
        $lower = strtolower($message);

        if (str_contains($lower, 'developer token')) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Authorization,
                $message,
                'DEVELOPER_TOKEN_REQUIRED',
            );
        }

        if (str_contains($lower, 'network') || str_contains($lower, 'timeout') || str_contains($lower, 'timed out')) {
            return DatasetExecutionResult::retry(
                CollectionErrorCategory::Network,
                $message,
                30,
                'NETWORK',
            );
        }

        return DatasetExecutionResult::failed(
            CollectionErrorCategory::Unknown,
            $message,
        );
    }

    public function fromHttpResponse(Response $response): DatasetExecutionResult
    {
        $status = $response->status();
        $body = $response->json();
        $reason = is_array($body)
            ? (string) (data_get($body, 'error.message')
                ?? data_get($body, '0.error.message')
                ?? $response->body())
            : $response->body();
        $reason = mb_substr($reason, 0, 500);
        $retryAfter = $response->header('Retry-After');
        $backoff = is_numeric($retryAfter) ? max(1, (int) $retryAfter) : 60;
        $lower = strtolower($reason);
        $requestId = is_array($body)
            ? (string) (data_get($body, 'requestId') ?? data_get($body, '0.requestId') ?? '')
            : '';

        if ($status === 401) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Authentication,
                'Google Ads authentication failed: '.$reason,
                'AUTHENTICATION',
            );
        }

        if ($status === 403) {
            if (str_contains($lower, 'developer') && str_contains($lower, 'token')) {
                return DatasetExecutionResult::failed(
                    CollectionErrorCategory::Authorization,
                    'Google Ads developer token error: '.$reason,
                    'DEVELOPER_TOKEN_REQUIRED',
                );
            }
            if (str_contains($lower, 'quota') || str_contains($lower, 'rate') || str_contains($lower, 'exhausted') || str_contains($lower, 'resource_exhausted')) {
                return DatasetExecutionResult::retry(
                    CollectionErrorCategory::Quota,
                    'Google Ads quota/rate limit: '.$reason.($requestId !== '' ? ' requestId='.$requestId : ''),
                    $backoff,
                    'QUOTA_EXHAUSTED',
                );
            }

            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Authorization,
                'Google Ads authorization failed: '.$reason,
                'AUTHORIZATION',
            );
        }

        if ($status === 429) {
            return DatasetExecutionResult::retry(
                CollectionErrorCategory::RateLimit,
                'Google Ads rate limit: '.$reason,
                $backoff,
                'RATE_LIMIT',
            );
        }

        if ($status >= 500) {
            return DatasetExecutionResult::retry(
                CollectionErrorCategory::Provider5xx,
                'Google Ads provider 5xx: '.$reason,
                $backoff,
                'PROVIDER_5XX',
            );
        }

        if (str_contains($lower, 'queryerror') || str_contains($lower, 'invalid') || $status === 400) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::ContractMismatch,
                'Google Ads invalid GAQL/request: '.$reason,
                'CONTRACT_MISMATCH',
            );
        }

        return DatasetExecutionResult::failed(
            CollectionErrorCategory::Unknown,
            'Google Ads unexpected response: '.$reason,
            'INVALID_RESPONSE',
        );
    }
}
