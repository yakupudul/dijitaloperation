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
 * Bağlantı masası başvuru malzemesi (operation `backlinks.pack`, Backlink hizmeti 2026-11-06): for ONE desk row (a link
 * source + the brand + the page and link text to ask for) the ready material: short and long profile text, the values of
 * the usual form fields, an e-mail and a phone note (resources/prompts/link-pack.md). Nothing is sent by it; the
 * operator copies or sends with one click.
 */
class LinkPackAgent implements Agent, HasProviderOptions, HasStructuredOutput, RegistryPrompted
{
    use Promptable;

    public const string ROUTE = 'backlinks.pack';

    public function instructions(): Stringable|string
    {
        return (string) file_get_contents(resource_path('prompts/link-pack.md'));
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
            'fit' => $schema->boolean()->required(),
            'why_not' => $schema->string()->required(),
            'short_description' => $schema->string()->required(),
            'description' => $schema->string()->required(),
            'fields' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'label' => $row->string()->required(),
                'value' => $row->string()->required(),
            ]))->required(),
            'email_subject' => $schema->string()->required(),
            'email_body' => $schema->string()->required(),
            'phone_note' => $schema->string()->required(),
            'steps' => $schema->array()->items($schema->string())->required(),
        ];
    }

    public function providerOptions(Lab|string $provider): array
    {
        return AiProviderOptions::for($provider instanceof Lab ? $provider->value : $provider);
    }
}
