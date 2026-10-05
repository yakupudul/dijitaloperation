<?php

namespace App\Mcp\Tools;

use App\Services\Integrations\WordPress\WordPressSiteBuilder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Site kurulumu: lists the paired WordPress sites (site_id, name, brand, url, plugin version) its mode (direct = applied at once, approval = waits for the operator, null = off) and whether each one can be built now (ready) or why not (reason: the MoxDOP switch "Claude site kurulumu", the plugin version, or "Site building" in the plugin settings).')]
#[IsReadOnly]
class ListSites extends Tool
{
    public function __construct(private readonly WordPressSiteBuilder $builder) {}

    public function handle(Request $request): Response
    {
        $sites = $this->builder->sites();

        return Response::json(['count' => count($sites), 'sites' => $sites]);
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
