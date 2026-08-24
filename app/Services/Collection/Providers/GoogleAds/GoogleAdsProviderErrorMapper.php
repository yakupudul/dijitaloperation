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
            // Zero means: let the collection RetryPolicy choose exponential backoff + jitter.
            return DatasetExecutionResult::retry(
                CollectionErrorCategory::Network,
                $message,
                0,
                'NETWORK_TRANSIENT',
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
        $reason = $this->reasonFromBody($body, $response);
        $reason = mb_substr($reason, 0, 1500);
        $retryAfter = $this->retryAfterSeconds($response);
        $lower = strtolower($reason);
        $requestId = $this->requestIdFromBody($body);
        $requestSuffix = $requestId !== '' ? ' requestId='.$requestId : '';

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
                    'Google Ads quota/rate limit: '.$reason.$requestSuffix,
                    $retryAfter,
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
                'Google Ads rate limit: '.$reason.$requestSuffix,
                $retryAfter,
                'RATE_LIMIT',
            );
        }

        if ($status >= 500) {
            return DatasetExecutionResult::retry(
                CollectionErrorCategory::Provider5xx,
                'Google Ads provider 5xx: '.$reason.$requestSuffix,
                $retryAfter,
                $status === 503 ? 'PROVIDER_UNAVAILABLE' : 'PROVIDER_5XX',
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

    /**
     * Prefer GoogleAdsFailure detail messages over the generic HTTP error.message.
     *
     * @param  array<string, mixed>|mixed  $body
     */
    private function reasonFromBody(mixed $body, Response $response): string
    {
        if (! is_array($body)) {
            return $response->body();
        }

        $parts = [];
        $top = data_get($body, 'error.message') ?? data_get($body, '0.error.message');
        if (is_string($top) && $top !== '') {
            $parts[] = $top;
        }

        $details = data_get($body, 'error.details');
        if (! is_array($details)) {
            $details = data_get($body, '0.error.details');
        }
        if (is_array($details)) {
            foreach ($details as $detail) {
                if (! is_array($detail)) {
                    continue;
                }
                $errors = $detail['errors'] ?? [];
                if (! is_array($errors)) {
                    continue;
                }
                foreach ($errors as $error) {
                    if (! is_array($error)) {
                        continue;
                    }
                    $codeBits = [];
                    $code = $error['errorCode'] ?? [];
                    if (is_array($code)) {
                        foreach ($code as $key => $value) {
                            if (is_scalar($value)) {
                                $codeBits[] = $key.':'.(string) $value;
                            }
                        }
                    }
                    $message = trim((string) ($error['message'] ?? ''));
                    $fragment = trim(implode(' ', array_filter([
                        $codeBits !== [] ? implode(',', $codeBits) : null,
                        $message !== '' ? $message : null,
                    ])));
                    if ($fragment !== '') {
                        $parts[] = $fragment;
                    }
                }
            }
        }

        $joined = implode(' | ', array_values(array_unique($parts)));

        return $joined !== '' ? $joined : $response->body();
    }

    /** @param array<string, mixed>|mixed $body */
    private function requestIdFromBody(mixed $body): string
    {
        if (! is_array($body)) {
            return '';
        }

        $candidates = [
            data_get($body, 'requestId'),
            data_get($body, '0.requestId'),
            data_get($body, 'error.details.0.requestId'),
            data_get($body, '0.error.details.0.requestId'),
        ];
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    private function retryAfterSeconds(Response $response): int
    {
        $value = trim((string) $response->header('Retry-After'));
        if ($value === '') {
            return 0;
        }
        if (is_numeric($value)) {
            return max(1, (int) $value);
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return 0;
        }

        return max(1, $timestamp - time());
    }
}
