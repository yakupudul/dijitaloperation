<?php

namespace App\Services\Collection\Providers\Ga4;

/**
 * Centralized official GA4 Data API capability constants.
 * Verification date: 2026-08-13.
 */
final class Ga4ProviderCapabilities
{
    public const string VERIFICATION_DATE = '2026-08-13';

    public const string RUN_REPORT_DOCS = 'https://developers.google.com/analytics/devguides/reporting/data/v1/rest/v1beta/properties/runReport';

    public const string METADATA_DOCS = 'https://developers.google.com/analytics/devguides/reporting/data/v1/rest/v1beta/properties/getMetadata';

    public const string COMPATIBILITY_DOCS = 'https://developers.google.com/analytics/devguides/reporting/data/v1/rest/v1beta/properties/checkCompatibility';

    public const string ADMIN_PROPERTY_DOCS = 'https://developers.google.com/analytics/devguides/config/admin/v1/rest/v1beta/properties/get';

    /** Official maximum rows per runReport request */
    public const int MAX_ROW_LIMIT = 250000;

    public const int DEFAULT_PAGE_SIZE = 10000;

    public const string PROVIDER_COMPLETENESS = 'PROVIDER_REPORT_BOUNDED';

    public const string EXECUTION_COMPLETENESS = 'REQUEST_EXECUTION_COMPLETE';
}
