<?php

namespace MoxDop\Website\Ai;

/**
 * Stable fingerprint for AI input context (never includes API keys).
 */
final class WebsiteAiInputFingerprint
{
    /**
     * @param  array<string, mixed>  $boundedContext
     */
    public static function make(
        string $promptVersion,
        string $schemaVersion,
        string $routeSignature,
        array $boundedContext,
    ): string {
        $canonical = [
            'prompt_version' => $promptVersion,
            'schema_version' => $schemaVersion,
            'route_signature' => $routeSignature,
            'context' => $boundedContext,
        ];

        $json = json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', is_string($json) ? $json : '');
    }
}
