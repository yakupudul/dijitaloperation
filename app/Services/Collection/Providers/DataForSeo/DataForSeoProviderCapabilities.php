<?php

namespace App\Services\Collection\Providers\DataForSeo;

/**
 * DataForSEO paid-enrichment notes for the shared Collection Engine.
 */
final class DataForSeoProviderCapabilities
{
    public const string VERIFICATION_DATE = '2026-08-20';

    public const string COLLECTOR_VERSION = 'dataforseo-production-collector-v1';

    public const string PROVIDER_COMPLETENESS = 'BOUNDED_LABS_ENRICHMENT';

    public const string ESTIMATED_NOTE = 'DataForSEO etv / search_volume / traffic value are PROVIDER_ESTIMATED, never GA4/GSC measured traffic.';

    public const string PAID_NOTE = 'Paid POSTs are cache-first, fingerprint-locked, never auto-retried, and never scheduled by the routine collection scheduler.';

    public const string SCOPE_NOTE = 'Agency Integration credentials; facts remain Website Digital Asset scoped. DataForSEO is not a Digital Asset or External Resource binding.';
}
