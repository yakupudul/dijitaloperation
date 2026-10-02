<?php

namespace App\Services\Collection\Providers\SearchConsole;

use App\Enums\Collection\CollectionErrorCategory;
use App\Exceptions\Integrations\GoogleAuthenticationException;
use App\Exceptions\Integrations\GoogleAuthorizationException;
use App\Services\Collection\Support\DatasetExecutionResult;
use Illuminate\Http\Client\Response;
use Throwable;

final class SearchConsoleProviderErrorMapper
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
        if (str_contains(strtolower($message), 'network')) {
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
            ? (string) (data_get($body, 'error.message') ?? data_get($body, 'error_description') ?? $response->body())
            : $response->body();
        $reason = mb_substr($reason, 0, 500);

        $retryAfter = $response->header('Retry-After');
        $backoff = is_numeric($retryAfter) ? max(1, (int) $retryAfter) : 60;

        if ($status === 401) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Authentication,
                'Search Console authentication failed: '.$reason,
                'AUTHENTICATION',
            );
        }

        if ($status === 403) {
            $lower = strtolower($reason);
            if (str_contains($lower, 'quota') || str_contains($lower, 'rate') || str_contains($lower, 'userRateLimit')) {
                return DatasetExecutionResult::retry(
                    CollectionErrorCategory::Quota,
                    'Search Console quota/load limit: '.$reason,
                    $backoff,
                    'QUOTA_EXHAUSTED',
                );
            }

            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Authorization,
                'Search Console authorization failed: '.$reason,
                'AUTHORIZATION',
            );
        }

        if ($status === 429) {
            return DatasetExecutionResult::retry(
                CollectionErrorCategory::RateLimit,
                'Search Console rate limit: '.$reason,
                $backoff,
                'RATE_LIMIT',
            );
        }

        if ($status >= 500) {
            return DatasetExecutionResult::retry(
                CollectionErrorCategory::Provider5xx,
                'Search Console provider 5xx: '.$reason,
                $backoff,
                'PROVIDER_5XX',
            );
        }

        if ($status === 400) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::InvalidRequest,
                'Search Console invalid request: '.$reason,
                'INVALID_REQUEST',
            );
        }

        return DatasetExecutionResult::failed(
            CollectionErrorCategory::Unknown,
            'Search Console HTTP '.$status.': '.$reason,
            'HTTP_'.$status,
        );
    }
}
