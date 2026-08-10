<?php

namespace App\Support\Agents;

/**
 * Stable Agent Profile keys shared across Core and modules.
 * Modules own the profile meaning; Core must not import module classes for keys.
 */
final class AgentProfileKeys
{
    public const string WEBSITE_SEO_ANALYST = 'website.seo_analyst';
}
