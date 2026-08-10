<?php

namespace App\Support\Ai;

/**
 * Canonical AI provider identities for Control Plane routing (Integrations, not Modules).
 */
final class AiProviderCatalog
{
    public const string OPENAI = 'openai';

    public const string ANTHROPIC = 'anthropic';

    public const string GEMINI = 'gemini';

    /**
     * V1 direct providers only (no aggregators).
     *
     * @return list<string>
     */
    public static function supported(): array
    {
        return [
            self::OPENAI,
            self::ANTHROPIC,
            self::GEMINI,
        ];
    }

    public static function isSupported(string $provider): bool
    {
        return in_array($provider, self::supported(), true);
    }

    public static function label(string $provider): string
    {
        return match ($provider) {
            self::OPENAI => 'OpenAI',
            self::ANTHROPIC => 'Anthropic',
            self::GEMINI => 'Gemini',
            default => str($provider)->replace('_', ' ')->title()->toString(),
        };
    }

    public static function defaultModel(string $provider): string
    {
        return match ($provider) {
            self::OPENAI => (string) config('moxdop.ai.defaults.openai_model', 'gpt-5-mini'),
            self::ANTHROPIC => (string) config('moxdop.ai.defaults.anthropic_model', 'claude-sonnet-5'),
            self::GEMINI => (string) config('moxdop.ai.defaults.gemini_model', 'gemini-3.6-flash'),
            default => 'unknown',
        };
    }

    public static function humanModelLabel(string $model): string
    {
        return match ($model) {
            'gpt-5-mini' => 'GPT-5 mini',
            'gpt-5' => 'GPT-5',
            'gpt-4.1-mini' => 'GPT-4.1 mini',
            'gpt-4.1' => 'GPT-4.1',
            'claude-sonnet-5' => 'Claude Sonnet 5',
            'claude-opus-4-8' => 'Claude Opus 4.8',
            'claude-haiku-4-5-20251001' => 'Claude Haiku 4.5',
            'gemini-3.6-flash' => 'Gemini 3.6 Flash',
            'gemini-3.5-flash-lite' => 'Gemini 3.5 Flash Lite',
            default => $model,
        };
    }
}
