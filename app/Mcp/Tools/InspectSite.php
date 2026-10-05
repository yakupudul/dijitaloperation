<?php

namespace App\Mcp\Tools;

use App\Services\Integrations\WordPress\WordPressSiteBuilder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[Description('Site kurulumu: reads a WordPress site before building: ACF and Elementor (version, Pro), theme (page templates, menu locations), settings, post types, ACF field groups with their fields, ACF post types / taxonomies, Elementor templates with conditions, menus, and `built` (everything earlier builds made, by ref). Only for a site whose list-sites row is ready.')]
#[IsReadOnly]
class InspectSite extends Tool
{
    public function __construct(private readonly WordPressSiteBuilder $builder) {}

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['site_id' => ['required', 'integer']]);
        try {
            return Response::json($this->builder->inspect((int) $validated['site_id']));
        } catch (Throwable $exception) {
            return Response::error($exception->getMessage());
        }
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'site_id' => $schema->integer()->description('site_id from list-sites.')->required(),
        ];
    }
}
