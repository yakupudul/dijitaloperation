<?php

namespace App\Mcp\Tools;

use App\Models\Brand;
use App\Services\Brand\BrandDossier;
use App\Services\Mcp\BrandBriefing;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Reads one brand: `facts` (the brand file MoxDOP builds by rules from its own data: identity, business context, assets, services with their pages, demand, website state, decisions and outcomes, open work, operator notes) and `claude_notes` (your own earlier notes, opinions not facts). Marks the returned sections as read. only_changed=true returns only sections changed since your last read; sections limits to some keys; rebuild=true rebuilds the file first (no AI).')]
class GetBrand extends Tool
{
    public function __construct(private readonly BrandBriefing $briefing) {}

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'brand_id' => ['required', 'integer'],
            'sections' => ['nullable', 'array'],
            'sections.*' => ['string'],
            'only_changed' => ['nullable', 'boolean'],
            'rebuild' => ['nullable', 'boolean'],
        ]);
        $brand = Brand::query()->operational()->find($validated['brand_id']);
        if ($brand === null) {
            return Response::error('Marka bulunamadı ya da aktif müşteri değil.');
        }
        $sections = array_values(array_intersect((array) ($validated['sections'] ?? []), array_keys(BrandDossier::SECTIONS)));

        return Response::json($this->briefing->brand($brand, $sections, (bool) ($validated['only_changed'] ?? false), (bool) ($validated['rebuild'] ?? false)));
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'brand_id' => $schema->integer()->description('Brand id from list-brands.')->required(),
            'sections' => $schema->array()->items($schema->string()->enum(array_keys(BrandDossier::SECTIONS)))->description('Only these sections.'),
            'only_changed' => $schema->boolean()->description('Only sections changed since your last read.'),
            'rebuild' => $schema->boolean()->description('Rebuild the brand file from current data first.'),
        ];
    }
}
