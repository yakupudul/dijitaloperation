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
 * Satış 3. hafta (yakup 2026-11-05: "3 bulgu seç değil, tam analiz"): a full written analysis of a prospect for the
 * operator (site, profile, conversation, plan, offer, questions, risks) plus the one-finding message for the customer
 * (resources/prompts/sales-analysis.md). Nothing is sent from MoxDOP.
 */
class SalesAnalysisAgent implements Agent, HasProviderOptions, HasStructuredOutput, RegistryPrompted
{
    use Promptable;

    public const string VERSION = '1.0.0';

    public const string ROUTE = 'sales.analysis';

    public function instructions(): Stringable|string
    {
        return (string) file_get_contents(resource_path('prompts/sales-analysis.md'));
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
            'analysis' => $schema->string()->required(),
            'message' => $schema->string()->required(),
            'headline' => $schema->string()->required(),
        ];
    }

    public function providerOptions(Lab|string $provider): array
    {
        return AiProviderOptions::for($provider instanceof Lab ? $provider->value : $provider);
    }
}
