<?php

namespace App\Services\Collection\Providers\Website;

/**
 * Bounded Website direct-observation notes for the shared Collection Engine.
 */
final class WebsiteProviderCapabilities
{
    public const string VERIFICATION_DATE = '2026-08-20';

    public const string COLLECTOR_VERSION = 'website-production-collector-v1';

    public const string PROVIDER_COMPLETENESS = 'BOUNDED_PUBLIC_OBSERVATION';

    public const string CRAWL_NOTE = 'SSRF-safe public HTTP only. No JavaScript execution, login, cookies, or CMS writes.';

    public const string INVENTORY_NOTE = 'URL inventory is a union of observed sources with per-source provenance. Partial coverage is the honest default.';

    public const string MISSING_NOTE = 'Uncollected or failed fetches stay missing. HTTP 200 is not health. Missing is never stored as zero.';
}
