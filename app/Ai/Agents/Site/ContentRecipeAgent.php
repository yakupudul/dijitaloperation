<?php

namespace App\Ai\Agents\Site;

use App\Support\Ai\AiRouteKeys;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/** `site.content_recipe` ("SEO analizi"): an ordered, concrete recipe for ONE content idea of a brand's site. */
final class ContentRecipeAgent extends SiteAgent
{
    public const array AREAS = ['teknik', 'baslik', 'bolum', 'soru_cevap', 'ic_baglanti', 'meta'];

    public const array AREA_LABELS = ['teknik' => 'Teknik', 'baslik' => 'Başlık', 'bolum' => 'Bölüm', 'soru_cevap' => 'Soru-cevap', 'ic_baglanti' => 'İç bağlantı', 'meta' => 'Meta'];

    public function promptOperation(): string
    {
        return AiRouteKeys::SITE_CONTENT_RECIPE;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'summary' => $schema->string()->required(),
            'steps' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'order' => $row->integer()->required(),
                'area' => $row->string()->enum(self::AREAS)->required(),
                'action' => $row->string()->required(),
                'where' => $row->string()->required(),
                'why' => $row->string()->required(),
                'evidence' => $row->array()->items($row->string())->required(),
            ]))->required(),
            'seo_title' => $schema->string()->nullable()->required(),
            'meta_description' => $schema->string()->nullable()->required(),
            'expected_effect' => $schema->string()->required(),
            'measure_after_days' => $schema->integer()->required(),
        ];
    }
}
