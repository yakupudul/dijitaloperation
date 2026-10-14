<?php

namespace App\Mcp\Tools;

use App\Models\DigitalAsset;
use App\Services\Gbp\GbpDailyWorkspace;
use App\Services\Gbp\GbpStandardInput;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('One Business Profile asset\'s stored evidence behind its Yapılacaklar checks (no Google call): when each dataset was last captured, the categories and service labels of the latest snapshots, media rows by category with their dates, review counts with / without a reply (last 90 days), and each standard\'s current result (state + finding). Use it to tell a wrong check from stale data. Read-only.')]
#[IsReadOnly]
class GbpEvidence extends Tool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate(['asset_id' => ['required', 'integer']]);
        $asset = DigitalAsset::query()->whereIn('type', ['google_business_profile', 'gbp'])->find((int) $validated['asset_id']);
        $resource = $asset !== null ? app(GbpDailyWorkspace::class)->resource($asset) : null;
        if ($asset === null || $resource === null) {
            return Response::error('İşletme Profili varlığı ya da bağlı Google konumu bulunamadı.');
        }
        $id = (int) $resource->id;
        $latest = fn (string $table, string $column = 'captured_at'): ?string => ($value = DB::table($table)->where('external_resource_id', $id)->max($column)) !== null ? (string) $value : null;
        $input = app(GbpStandardInput::class);
        $since = now()->subDays(90);

        return Response::json([
            'asset' => ['id' => $asset->id, 'name' => $asset->name, 'brand_id' => $asset->brand_id, 'resource_id' => $id],
            'captured' => [
                'location' => $latest('gbp_location_snapshots'),
                'services' => $latest('gbp_service_snapshots'),
                'media' => $latest('gbp_media', 'updated_at'),
                'reviews' => $latest('gbp_reviews', 'collected_at'),
                'reviews_full_sync' => data_get($resource->metadata, 'gbp_reviews_full_sync_at'),
            ],
            'location' => $input->location($id),
            'services' => $input->services($id),
            'media' => DB::table('gbp_media')->where('external_resource_id', $id)
                ->selectRaw("coalesce(category, '-') as category, coalesce(media_format, '-') as format, count(*) as n, max(create_time) as newest")
                ->groupBy('category', 'media_format')->get(),
            'reviews_90d' => [
                'total' => DB::table('gbp_reviews')->where('external_resource_id', $id)->where('create_time', '>=', $since)->count(),
                'with_reply_column' => DB::table('gbp_reviews')->where('external_resource_id', $id)->where('create_time', '>=', $since)->whereNotNull('review_reply')->count(),
            ],
            'standards' => collect($input->results($asset, $id))->map(fn (array $r): array => ['state' => $r['state'], 'finding' => $r['finding']])->all(),
        ]);
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return ['asset_id' => $schema->integer()->description('The Business Profile asset id (the number in /assets/gbp/{id}).')->required()];
    }
}
