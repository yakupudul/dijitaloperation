<?php

namespace App\Services\Collection\Providers\DataForSeo;

use App\Enums\Collection\CollectionErrorCategory;
use App\Services\Collection\Support\DatasetExecutionResult;
use App\Services\Integrations\DataForSeo\DataForSeoException;
use Throwable;

final class DataForSeoProviderErrorMapper
{
    public function fromThrowable(Throwable $e): DatasetExecutionResult
    {
        if ($e instanceof DataForSeoException) {
            return $this->fromProviderException($e);
        }

        return DatasetExecutionResult::failed(
            CollectionErrorCategory::Unknown,
            $e->getMessage(),
        );
    }

    /**
     * Paid POST already left the process (or charge is otherwise unknown).
     * The Collection Engine must not Retry — Retry would POST again.
     */
    public function fromPaidAttempt(Throwable $e): DatasetExecutionResult
    {
        if ($e instanceof DataForSeoException
            && $e->kind === DataForSeoException::KIND_HTTP
            && in_array($e->httpStatus, [401, 403], true)
        ) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Authentication,
                $e->getMessage(),
                'AUTHENTICATION',
            );
        }

        return DatasetExecutionResult::failed(
            CollectionErrorCategory::Unknown,
            $e->getMessage(),
            'CHARGE_UNKNOWN',
        );
    }

    public function fromProviderException(DataForSeoException $e): DatasetExecutionResult
    {
        return match ($e->kind) {
            DataForSeoException::KIND_TRANSPORT => DatasetExecutionResult::retry(
                CollectionErrorCategory::Network,
                $e->getMessage(),
                30,
                'NETWORK',
            ),
            DataForSeoException::KIND_HTTP => $this->fromHttp($e),
            DataForSeoException::KIND_PROVIDER_STATUS => DatasetExecutionResult::failed(
                CollectionErrorCategory::InvalidRequest,
                $e->getMessage(),
                'PROVIDER_STATUS',
            ),
            DataForSeoException::KIND_MALFORMED => DatasetExecutionResult::failed(
                CollectionErrorCategory::Normalization,
                $e->getMessage(),
                'MALFORMED',
            ),
            DataForSeoException::KIND_ENDPOINT_NOT_ALLOWED => DatasetExecutionResult::failed(
                CollectionErrorCategory::ContractMismatch,
                $e->getMessage(),
                'ENDPOINT_NOT_ALLOWED',
            ),
            DataForSeoException::KIND_AMBIGUOUS_PAID => DatasetExecutionResult::failed(
                CollectionErrorCategory::Unknown,
                $e->getMessage(),
                'CHARGE_UNKNOWN',
            ),
            default => DatasetExecutionResult::failed(
                CollectionErrorCategory::Unknown,
                $e->getMessage(),
            ),
        };
    }

    private function fromHttp(DataForSeoException $e): DatasetExecutionResult
    {
        $status = $e->httpStatus ?? 0;
        if ($status === 401 || $status === 403) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Authentication,
                $e->getMessage(),
                'AUTHENTICATION',
            );
        }
        if ($status === 429) {
            return DatasetExecutionResult::retry(
                CollectionErrorCategory::RateLimit,
                $e->getMessage(),
                60,
                'RATE_LIMIT',
            );
        }
        if ($status >= 500) {
            return DatasetExecutionResult::retry(
                CollectionErrorCategory::Provider5xx,
                $e->getMessage(),
                45,
                'PROVIDER_5XX',
            );
        }

        return DatasetExecutionResult::failed(
            CollectionErrorCategory::InvalidRequest,
            $e->getMessage(),
            'HTTP_'.$status,
        );
    }
}
