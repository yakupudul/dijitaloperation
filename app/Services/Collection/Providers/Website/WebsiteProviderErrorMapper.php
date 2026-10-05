<?php

namespace App\Services\Collection\Providers\Website;

use App\Enums\Collection\CollectionErrorCategory;
use App\Services\Collection\Support\DatasetExecutionResult;
use App\Services\Integrations\WordPress\WordPressConnectorSiteException;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

final class WebsiteProviderErrorMapper
{
    public function fromThrowable(Throwable $e): DatasetExecutionResult
    {
        // The message quotes the site's own output, so a word in it (setTimeout, network) never makes it a retry.
        if ($e instanceof WordPressConnectorSiteException) {
            return DatasetExecutionResult::failed(CollectionErrorCategory::Unknown, $e->getMessage());
        }

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
