<?php

namespace App\Support\Ai;

/**
 * Stable AI route keys registered by modules and consumed by Control Plane UI.
 * Keys are shared identifiers — route meaning remains owned by the registering module.
 */
final class AiRouteKeys
{
    public const string WEBSITE_AI_GUIDANCE = 'website.ai_guidance';

    public const string WEBSITE_DISCOVERY_CONTEXT = 'website.discovery_context';

    public const string GOOGLE_ADS_AI_GUIDANCE = 'google_ads.ai_guidance';

    public const string META_ADS_AI_GUIDANCE = 'meta_ads.ai_guidance';
}
