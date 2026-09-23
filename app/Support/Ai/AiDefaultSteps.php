<?php

namespace App\Support\Ai;

/**
 * Default provider chains per kind of AI work (owner decision 2026-09-24). Applied only while a
 * route has no steps saved in the AI Control Plane; the first eligible step runs.
 */
final class AiDefaultSteps
{
    /** Reasoning, briefs, analysis: Claude Sonnet 5 → OpenAI → Gemini. @return list<array{provider: string, model: string}> */
    public static function analysis(): array
    {
        return [
            ['provider' => AiProviderCatalog::ANTHROPIC, 'model' => (string) config('moxdop.ai.defaults.anthropic_model', 'claude-sonnet-5')],
            ['provider' => AiProviderCatalog::OPENAI, 'model' => AiProviderCatalog::defaultModel(AiProviderCatalog::OPENAI)],
            ['provider' => AiProviderCatalog::GEMINI, 'model' => AiProviderCatalog::defaultModel(AiProviderCatalog::GEMINI)],
        ];
    }

    /** Matching, labelling, classification: Claude Haiku 4.5 → OpenAI → Gemini. @return list<array{provider: string, model: string}> */
    public static function classification(): array
    {
        return [
            ['provider' => AiProviderCatalog::ANTHROPIC, 'model' => (string) config('moxdop.ai.defaults.anthropic_fast_model', 'claude-haiku-4-5')],
            ['provider' => AiProviderCatalog::OPENAI, 'model' => AiProviderCatalog::defaultModel(AiProviderCatalog::OPENAI)],
            ['provider' => AiProviderCatalog::GEMINI, 'model' => AiProviderCatalog::defaultModel(AiProviderCatalog::GEMINI)],
        ];
    }

    /** Public / agency-wide text only: free Groq model first, then Haiku 4.5, then OpenAI. @return list<array{provider: string, model: string}> */
    public static function publicData(): array
    {
        return [
            ['provider' => AiProviderCatalog::GROQ, 'model' => AiProviderCatalog::defaultModel(AiProviderCatalog::GROQ)],
            ['provider' => AiProviderCatalog::ANTHROPIC, 'model' => (string) config('moxdop.ai.defaults.anthropic_fast_model', 'claude-haiku-4-5')],
            ['provider' => AiProviderCatalog::OPENAI, 'model' => AiProviderCatalog::defaultModel(AiProviderCatalog::OPENAI)],
        ];
    }
}
