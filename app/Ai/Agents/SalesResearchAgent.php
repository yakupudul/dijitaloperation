<?php

namespace App\Ai\Agents;

use App\Ai\Contracts\RegistryPrompted;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\WebFetch;
use Laravel\Ai\Providers\Tools\WebSearch;
use Stringable;

/**
 * Yeni aday araştırması (yakup 2026-11-08: "numaradan isimden araştırma yapıp klinik web sitesini, instagram adresini
 * harita kaydını analiz etsen"): Claude searches the web for an unknown number's business and reads its site,
 * Instagram and Google harita kaydı (resources/prompts/sales-research.md). Anthropic only: the search and fetch tools
 * run on Anthropic's side. Nothing is sent.
 */
class SalesResearchAgent implements Agent, HasStructuredOutput, HasTools, RegistryPrompted
{
    use Promptable;

    public const string VERSION = '1.0.0';

    public const string ROUTE = 'sales.research';

    public function instructions(): Stringable|string
    {
        return (string) file_get_contents(resource_path('prompts/sales-research.md'));
    }

    public function promptOperation(): string
    {
        return self::ROUTE;
    }

    public function promptVersionId(): ?int
    {
        return null;
    }

    public function tools(): iterable
    {
        return [(new WebSearch(6))->location(country: 'TR'), new WebFetch(6)];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'business_name' => $schema->string()->required(),
            'person_name' => $schema->string()->required(),
            'kind' => $schema->string()->required(),
            'city' => $schema->string()->required(),
            'district' => $schema->string()->required(),
            'website' => $schema->string()->required(),
            'instagram' => $schema->string()->required(),
            'google_maps' => $schema->string()->required(),
            'maps' => $schema->object(fn (JsonSchema $row): array => [
                'rating' => $row->string()->required(), 'reviews' => $row->string()->required(), 'category' => $row->string()->required(),
                'address' => $row->string()->required(), 'website_linked' => $row->string()->required(),
            ])->required(),
            'instagram_facts' => $schema->object(fn (JsonSchema $row): array => [
                'followers' => $row->string()->required(), 'posts' => $row->string()->required(), 'last_post' => $row->string()->required(),
                'bio' => $row->string()->required(),
            ])->required(),
            'site_notes' => $schema->array()->items($schema->string())->required(),
            'findings' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'title' => $row->string()->required(), 'detail' => $row->string()->required(), 'source' => $row->string()->required(),
            ]))->required(),
            'confidence' => $schema->string()->enum(['high', 'medium', 'low'])->required(),
            'sources' => $schema->array()->items($schema->string())->required(),
            'note' => $schema->string()->required(),
        ];
    }
}
