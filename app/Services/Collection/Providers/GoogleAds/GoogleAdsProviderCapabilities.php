<?php

namespace App\Services\Collection\Providers\GoogleAds;

/**
 * Centralized official Google Ads API capability constants.
 * Verification date: 2026-08-13 (API v25 field/search docs).
 */
final class GoogleAdsProviderCapabilities
{
    public const string VERIFICATION_DATE = '2026-08-13';

    public const string SEARCH_DOCS = 'https://developers.google.com/google-ads/api/rest/common/search';

    public const string STREAMING_DOCS = 'https://developers.google.com/google-ads/api/docs/reporting/streaming';

    public const string GAQL_DOCS = 'https://developers.google.com/google-ads/api/docs/query/overview';

    /** Official Search page size (fixed by provider) */
    public const int SEARCH_PAGE_SIZE = 10000;

    public const string PROVIDER_COMPLETENESS = 'PROVIDER_REPORT_BOUNDED';

    public const string EXECUTION_COMPLETENESS = 'REQUEST_EXECUTION_COMPLETE';

    public const string SEARCH_TERM_PRIVACY = 'PROVIDER_MAY_OMIT_SEARCH_TERMS';
}
