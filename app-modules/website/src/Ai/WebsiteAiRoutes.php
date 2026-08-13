<?php

namespace MoxDop\Website\Ai;

use App\Support\Ai\AiRouteKeys;

/**
 * Website module route key for AI Guidance (Control Plane consumer).
 */
final class WebsiteAiRoutes
{
    /** @deprecated Use AiRouteKeys::WEBSITE_AI_GUIDANCE */
    public const string AI_GUIDANCE = AiRouteKeys::WEBSITE_AI_GUIDANCE;

    public const string AI_GUIDANCE_NAME = 'Website AI Guidance';

    public const string DISCOVERY_CONTEXT_NAME = 'Website Discovery Context';

    public const string GA4_AI_GUIDANCE_NAME = 'GA4 Measurement Guidance';

    public const string GSC_AI_GUIDANCE_NAME = 'Search Console Organic Search Guidance';
}
