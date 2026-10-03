<?php

namespace App\Mcp\Tools;

use App\Services\Mcp\BrandBriefing;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Lists the active brands: id, name, sector, websites, when the brand file was built, which file sections changed since Claude last read it (changed_sections), open Claude notes and waiting AI tasks. only_changed=true lists only brands with something new to read.')]
#[IsReadOnly]
class ListBrands extends Tool
{
    public function __construct(private readonly BrandBriefing $briefing) {}

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['only_changed' => ['nullable', 'boolean']]);
        $brands = $this->briefing->brands((bool) ($validated['only_changed'] ?? false));

        return Response::json(['count' => count($brands), 'brands' => $brands]);
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'only_changed' => $schema->boolean()->description('Only brands whose file changed since Claude last read it (or never read).'),
        ];
    }
}
