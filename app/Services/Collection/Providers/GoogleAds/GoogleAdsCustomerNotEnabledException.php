<?php

namespace App\Services\Collection\Providers\GoogleAds;

use RuntimeException;

/**
 * The Google Ads account answered CUSTOMER_NOT_ENABLED (closed or suspended). The history probe has already marked it
 * (`not_enabled_at`), so automatic collection waits and re-checks it weekly instead of failing.
 */
final class GoogleAdsCustomerNotEnabledException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Google Ads hesabı etkin değil (kapalı ya da askıda).');
    }
}
