<?php

namespace MoxDop\MetaAds\Ai;

/**
 * Versioned Meta Ads AI guidance constants.
 */
final class MetaAdsAiGuidanceConfig
{
    public const string MODULE_ID = 'meta-ads-ai-insights';

    public const string EVIDENCE_TYPE_AI_INSIGHT = 'ai_insight';

    public const string PROMPT_VERSION = 'meta-ads-ai-guidance-v1';

    public const string SCHEMA_VERSION = 'meta-ads-ai-guidance-schema-v1';

    public const string RUN_TITLE = 'Meta Ads AI Guidance';

    public const int MAX_FINDINGS = 12;

    public const int MAX_EVIDENCE = 24;

    public const int MAX_HIERARCHY_ROWS_IN_CONTEXT = 40;

    public const int MAX_STRING_LENGTH = 800;

    public const int MAX_ARRAY_ROWS = 25;

    public const int MAX_NESTING_DEPTH = 4;

    /**
     * @return list<string>
     */
    public static function blockedPayloadKeys(): array
    {
        return [
            'raw',
            'html',
            'body',
            'credentials',
            'token',
            'authorization',
            'secret',
            'password',
            'api_key',
            'access_token',
            'refresh_token',
            'object_story_spec',
            'asset_feed_spec',
        ];
    }
}
