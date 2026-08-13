<?php

namespace App\Support\Agents;

/**
 * Stable Agent Profile keys shared across Core and modules.
 * Modules own the profile meaning; Core must not import module classes for keys.
 */
final class AgentProfileKeys
{
    public const string WEBSITE_SEO_ANALYST = 'website.seo_analyst';

    public const string WEBSITE_BRAND_DISCOVERY_ANALYST = 'website.brand_discovery_analyst';

    public const string GOOGLE_ADS_ANALYST = 'google_ads.analyst';

    public const string META_ADS_ANALYST = 'meta_ads.analyst';

    public const string GBP_LOCAL_PRESENCE_ANALYST = 'gbp.local_presence_analyst';

    public const string GA4_MEASUREMENT_ANALYST = 'ga4.measurement_analyst';

    public const string GSC_ORGANIC_SEARCH_ANALYST = 'gsc.organic_search_analyst';
}
