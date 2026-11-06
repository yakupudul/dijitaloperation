<?php

namespace App\Ai\Agents\Site;

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
 * Kaynak kalite kapısı (operation `backlinks.quality`, Backlink hizmeti 2026-11-06): a batch of new pool sources, each
 * with what its page shows (title, description, text, how many other sites it links to, words that sell links), is
 * judged before any brand is offered it: uygun or red, with a one-line note, and its kind, city and way to apply
 * corrected (resources/prompts/link-quality.md).
 */
class LinkQualityAgent implements Agent, HasProviderOptions, HasStructuredOutput, RegistryPrompted
{
    use Promptable;

    public const string ROUTE = 'backlinks.quality';

    public function instructions(): Stringable|string
    {
        return (string) file_get_contents(resource_path('prompts/link-quality.md'));
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
            'sources' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'id' => $row->integer()->required(),
                'verdict' => $row->string()->enum(['uygun', 'red'])->required(),
                'note' => $row->string()->required(),
                'kind' => $row->string()->enum(BacklinkSourcesAgent::KINDS)->required(),
                'city' => $row->string()->nullable()->required(),
                'apply_via' => $row->string()->enum(BacklinkSourcesAgent::APPLY_VIA)->required(),
            ]))->required(),
        ];
    }

    public function providerOptions(Lab|string $provider): array
    {
        return AiProviderOptions::for($provider instanceof Lab ? $provider->value : $provider);
    }
}
