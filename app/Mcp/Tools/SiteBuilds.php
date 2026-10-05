<?php

namespace App\Mcp\Tools;

use App\Services\Integrations\WordPress\WordPressSiteBuilder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Site kurulumu: the site\'s build log, newest first, the same list the operator sees in MoxDOP: id, status (awaiting_approval, queued, running, succeeded, partial, failed, rejected with reject_reason, undoing, undone, undo_failed), mode, and per operation its label, target, result, error and edit URL. Use it to follow a build that waited for approval.')]
#[IsReadOnly]
class SiteBuilds extends Tool
{
    public function __construct(private readonly WordPressSiteBuilder $builder) {}

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['site_id' => ['required', 'integer'], 'limit' => ['nullable', 'integer', 'min:1', 'max:50']]);

        return Response::json(['builds' => $this->builder->history((int) $validated['site_id'], (int) ($validated['limit'] ?? 10))]);
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'site_id' => $schema->integer()->description('site_id from list-sites.')->required(),
            'limit' => $schema->integer()->description('How many builds (default 10, at most 50).'),
        ];
    }
}
