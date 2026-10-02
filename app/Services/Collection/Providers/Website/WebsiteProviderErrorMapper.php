<?php

namespace App\Services\Collection\Providers\Website;

use App\Enums\Collection\CollectionErrorCategory;
use App\Services\Collection\Support\DatasetExecutionResult;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

final class WebsiteProviderErrorMapper
{
    public function fromThrowable(Throwable $e): DatasetExecutionResult
    {
        if ($e instanceof ConnectionException || str_contains(strtolower($e->getMessage()), 'timeout')) {
            return DatasetExecutionResult::retry(
                CollectionErrorCategory::Timeout,
                $e->getMessage(),
                15,
                'TIMEOUT',
            );
        }

        if (str_contains(strtolower($e->getMessage()), 'network')) {
            return DatasetExecutionResult::retry(
                CollectionErrorCategory::Network,
                $e->getMessage(),
                20,
                'NETWORK',
            );
        }

        return DatasetExecutionResult::failed(
            CollectionErrorCategory::Unknown,
            $e->getMessage(),
        );
    }
}
