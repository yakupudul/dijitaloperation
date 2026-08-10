<?php

namespace MoxDop\Website\Ai;

/**
 * Versioned Website AI recommendation playbook constants (not a plugin runtime).
 */
final class WebsiteAiRecommendationConfig
{
    public const string MODULE_ID = 'website-ai-insights';

    public const string EVIDENCE_TYPE_AI_INSIGHT = 'ai_insight';

    public const string PROMPT_VERSION = 'website-ai-recommendation-v2';

    public const string SCHEMA_VERSION = 'website-ai-recommendation-schema-v1';

    public const string RUN_TITLE = 'AI Guidance';

    public const int MAX_FINDINGS = 12;

    public const int MAX_EVIDENCE = 24;

    public const int MAX_STRING_LENGTH = 800;

    public const int MAX_ARRAY_ROWS = 20;

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
            'head_html',
            'content',
            'credentials',
            'token',
            'authorization',
            'secret',
            'password',
            'api_key',
            'access_token',
            'refresh_token',
        ];
    }
}
