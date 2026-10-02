<?php

namespace App\Services\Collection\Providers\GoogleAds;

use RuntimeException;

final class GoogleAdsQuotaCooldownException extends RuntimeException
{
    public function __construct(
        public readonly int $retryAfterSeconds,
        public readonly string $scope = 'global',
    ) {
        parent::__construct(sprintf(
            'Google Ads quota cooldown active (%s). Retry in %d seconds.',
            $scope,
            max(1, $retryAfterSeconds),
        ));
    }
}
