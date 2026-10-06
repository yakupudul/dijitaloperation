<?php

namespace App\Ai\Agents;

use App\Ai\Concerns\UsesPromptRegistry;
use App\Ai\Contracts\RegistryPrompted;
use App\Support\Ai\AiProviderOptions;
use App\Support\Ai\AiRouteKeys;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * Şube sayfası (operation `gbp.branch_page`, ADR-079): the text parts of one Business Profile location's page on the
 * brand's website (title, SEO fields, introduction, services linked to the brand's own pages, how to reach, questions).
 * Address, phone, hours, map link and local-business markup are added by BranchPages from the profile, never by AI.
 */
final class GbpBranchPageAgent implements Agent, HasProviderOptions, HasStructuredOutput, RegistryPrompted
{
    use Promptable;
    use UsesPromptRegistry;

    public const string OPERATION = AiRouteKeys::GBP_BRANCH_PAGE;

    public function promptOperation(): string
    {
        return self::OPERATION;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required(),
            'slug' => $schema->string()->required(),
            'meta_title' => $schema->string()->required(),
            'meta_description' => $schema->string()->required(),
            'focus_keyword' => $schema->string()->required(),
            'intro' => $schema->array()->items($schema->string())->required(),
            'services' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'name' => $row->string()->required(),
                'text' => $row->string()->required(),
                'page_url' => $row->string()->required(),
            ]))->required(),
            'access' => $schema->string()->required(),
            'faq' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'question' => $row->string()->required(),
                'answer' => $row->string()->required(),
            ]))->required(),
        ];
    }

    /** @return array<string, mixed> */
    public function providerOptions(Lab|string $provider): array
    {
        $key = $provider instanceof Lab ? $provider->value : $provider;

        return AiProviderOptions::for((string) $key);
    }
}
