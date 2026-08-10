<?php

namespace MoxDop\GoogleAds\Ai;

/**
 * Stable fingerprint for Google Ads AI input (never includes API keys or full Skill text).
 */
final class GoogleAdsAiInputFingerprint
{
    /**
     * @param  array<string, mixed>  $boundedContext
     * @param  list<string>  $skillSignatures
     */
    public static function make(
        string $promptVersion,
        string $schemaVersion,
        string $routeSignature,
        string $agentSignature,
        array $skillSignatures,
        array $boundedContext,
    ): string {
        $canonical = [
            'prompt_version' => $promptVersion,
            'schema_version' => $schemaVersion,
            'route_signature' => $routeSignature,
            'agent_signature' => $agentSignature,
            'skill_signatures' => array_values($skillSignatures),
            'context' => $boundedContext,
        ];

        $json = json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', is_string($json) ? $json : '');
    }
}
