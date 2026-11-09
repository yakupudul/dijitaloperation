<?php

namespace App\Ai\Agents;

use App\Ai\Contracts\RegistryPrompted;
use App\Support\Ai\AiProviderOptions;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Satış beyni (yakup 2026-11-08): reads a closed deal (its karne, the conversation, the notes) and proposes what the
 * sales work should keep doing or change, and the questions the operator should answer
 * (resources/prompts/sales-lesson.md). Nothing is applied before the operator approves it.
 */
class SalesLessonAgent implements Agent, HasProviderOptions, HasStructuredOutput, RegistryPrompted
{
    use Promptable;

    public const string VERSION = '1.0.0';

    public const string ROUTE = 'sales.lesson';

    public function instructions(): Stringable|string
    {
        return (string) file_get_contents(resource_path('prompts/sales-lesson.md'));
    }

    public function promptOperation(): string
    {
        return self::ROUTE;
    }

    public function promptVersionId(): ?int
    {
        return null;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'verdict' => $schema->string()->required(),
            'lessons' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'kind' => $row->string()->enum(['karar', 'yontem'])->required(),
                'text' => $row->string()->required(),
            ]))->required(),
            'questions' => $schema->array()->items($schema->string())->required(),
        ];
    }

    public function providerOptions(Lab|string $provider): array
    {
        return AiProviderOptions::for($provider instanceof Lab ? $provider->value : $provider);
    }
}
