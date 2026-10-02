<?php

namespace App\Support\Ai;

use Illuminate\Support\Facades\Context;
use Laravel\Ai\Enums\Lab;

/**
 * Provider options every agent sends. OpenAI: nothing stored on their side, and for the GPT-5 / o-series reasoning
 * models a low reasoning effort — their hidden reasoning tokens are billed as output and were most of the AI bill.
 * The effort is set in config (moxdop.ai.defaults.openai_reasoning_effort; empty = the model's default).
 */
final class AiProviderOptions
{
    /** Hidden context: the OpenAI model of the route being run (set by AiRouteResolver). */
    public const string OPENAI_MODEL_CONTEXT = 'ai_openai_model';

    /** @return array<string, mixed> */
    public static function for(string $provider): array
    {
        if ($provider !== Lab::OpenAI->value) {
            return [];
        }
        $effort = (string) config('moxdop.ai.defaults.openai_reasoning_effort', 'low');
        $model = (string) (Context::getHidden(self::OPENAI_MODEL_CONTEXT) ?? AiProviderCatalog::defaultModel(AiProviderCatalog::OPENAI));

        return ['store' => false] + ($effort !== '' && self::isReasoningModel($model) ? ['reasoning' => ['effort' => $effort]] : []);
    }

    public static function isReasoningModel(string $model): bool
    {
        return preg_match('/^(gpt-5|o\d)/', $model) === 1;
    }
}
