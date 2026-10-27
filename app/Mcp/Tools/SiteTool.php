<?php

namespace App\Mcp\Tools;

use App\Services\Integrations\WordPress\WordPressSiteBuilder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Throwable;

#[Description(<<<'TEXT'
Siteyi yönetme (connector ≥ 1.26.0): runs one tool of the site's own Claude / ChatGPT connection, within the level its admin chose in WordPress › Settings › Claude / ChatGPT (read, draft, edit or full; off = refused). tool "list" shows the level and every allowed tool with its input schema: call it first. Full level covers publishing and scheduling (Akıllı tarih), trash, Elementor pages and the site builder, media, menus, ACF, technical SEO, site settings, plugins from WordPress.org (search, install, activate, update), plugin settings (plugin_settings, set_option, setup_guide, apply_setup for Rank Math / Yoast / LiteSpeed / Autoptimize / Cache Enabler), clear_cache and site_check; comments and form messages need the site's personal-data switch. A plugin activation, a setting or a setup is checked on the live site and put back at once when the site broke. Every write is in the site's change log; undo_change puts it back. Only what the operator asked; a brandless site gets the read tools only.
TEXT)]
class SiteTool extends Tool
{
    public function __construct(private readonly WordPressSiteBuilder $builder) {}

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'site_id' => ['required', 'integer'],
            'tool' => ['required', 'string', 'max:60', 'regex:/^[a-z_]+$/'],
            'arguments' => ['nullable', 'array'],
        ]);
        try {
            return Response::json($this->builder->tool((int) $validated['site_id'], (string) $validated['tool'], (array) ($request->get('arguments') ?? [])));
        } catch (Throwable $exception) {
            return Response::error($exception->getMessage());
        }
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'site_id' => $schema->integer()->description('site_id from list-sites.')->required(),
            'tool' => $schema->string()->description('"list", or a tool name from it (e.g. get_content, publish, install_plugin, apply_setup).')->required(),
            'arguments' => $schema->object()->description('The tool\'s arguments (its input schema from "list").'),
        ];
    }
}
