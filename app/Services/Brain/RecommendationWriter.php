<?php

namespace App\Services\Brain;

use Illuminate\Support\Facades\DB;

/**
 * Keeps a source's open recommendations for one scope in step with the latest calculation: new ones are added, ones
 * no longer detected close as "resolved", and ones an operator dismissed are not raised again. A recommendation
 * marked done is re-raised only after RECHECK_DAYS if it is still detected (the fix did not hold).
 */
final class RecommendationWriter
{
    private const int RECHECK_DAYS = 28;

    public function __construct(private readonly ComplianceBrake $brake) {}

    /**
     * @param  array{source: string, digital_asset_id?: ?int, brand_id?: ?int, service_id?: ?int}  $scope
     * @param  list<array{channel: string, type: string, title: string, detail?: ?string, evidence?: array<string, mixed>, impact?: ?float, basis?: string, method_id?: ?int, service_id?: ?int, cluster_id?: ?int, brand_id?: ?int, digital_asset_id?: ?int, key: string}>  $items
     * @return array{added: int, resolved: int}
     */
    public function sync(array $scope, array $items): array
    {
        $existing = DB::table('brain_recommendations')->where('source', $scope['source'])
            ->when(array_key_exists('digital_asset_id', $scope), fn ($q) => $q->where('digital_asset_id', $scope['digital_asset_id']))
            ->when(array_key_exists('brand_id', $scope), fn ($q) => $q->where('brand_id', $scope['brand_id']))
            ->when(array_key_exists('service_id', $scope), fn ($q) => $q->where('service_id', $scope['service_id']))
            ->get()->groupBy('fingerprint');
        $seen = [];
        $added = 0;
        foreach ($items as $item) {
            $fingerprint = hash('sha256', $scope['source'].'|'.$item['type'].'|'.$item['key']);
            $seen[$fingerprint] = true;
            $rows = $existing->get($fingerprint, collect());
            $blocked = $this->brake->reason($item, $item['brand_id'] ?? ($scope['brand_id'] ?? null));
            if ($blocked !== null) {
                $values = ['title' => mb_substr($item['title'], 0, 500), 'detail' => $blocked, 'status' => 'blocked', 'updated_at' => now()];
                $current = $rows->whereIn('status', ['open', 'blocked'])->first();
                if ($current !== null) {
                    DB::table('brain_recommendations')->where('id', $current->id)->update($values);
                } elseif (! $rows->contains('status', 'dismissed')) {
                    DB::table('brain_recommendations')->insert($values + [
                        'brand_id' => $item['brand_id'] ?? ($scope['brand_id'] ?? null), 'digital_asset_id' => $item['digital_asset_id'] ?? ($scope['digital_asset_id'] ?? null),
                        'service_id' => $item['service_id'] ?? ($scope['service_id'] ?? null), 'cluster_id' => $item['cluster_id'] ?? null,
                        'source' => $scope['source'], 'channel' => $item['channel'], 'type' => $item['type'], 'fingerprint' => $fingerprint,
                        'evidence' => json_encode($item['evidence'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'basis' => $item['basis'] ?? 'rule',
                        'method_id' => $item['method_id'] ?? null, 'created_at' => now(),
                    ]);
                }

                continue;
            }
            $open = $rows->firstWhere('status', 'open') ?? $rows->firstWhere('status', 'blocked');
            $values = [
                'title' => mb_substr($item['title'], 0, 500), 'detail' => $item['detail'] ?? null,
                'evidence' => json_encode($item['evidence'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'impact' => $item['impact'] ?? null, 'basis' => $item['basis'] ?? 'rule', 'method_id' => $item['method_id'] ?? null, 'updated_at' => now(),
            ];
            if ($open !== null) {
                DB::table('brain_recommendations')->where('id', $open->id)->update($values + ['status' => 'open']);

                continue;
            }
            if ($rows->contains('status', 'dismissed')) {
                continue;
            }
            $done = $rows->where('status', 'done')->sortByDesc('resolved_at')->first();
            if ($done !== null && $done->resolved_at !== null && now()->diffInDays($done->resolved_at, true) < self::RECHECK_DAYS) {
                continue;
            }
            DB::table('brain_recommendations')->insert($values + [
                'brand_id' => $item['brand_id'] ?? ($scope['brand_id'] ?? null), 'digital_asset_id' => $item['digital_asset_id'] ?? ($scope['digital_asset_id'] ?? null),
                'service_id' => $item['service_id'] ?? ($scope['service_id'] ?? null), 'cluster_id' => $item['cluster_id'] ?? null,
                'source' => $scope['source'], 'channel' => $item['channel'], 'type' => $item['type'], 'fingerprint' => $fingerprint,
                'status' => 'open', 'created_at' => now(),
            ]);
            $added++;
        }
        $resolved = 0;
        foreach ($existing as $fingerprint => $rows) {
            if (! isset($seen[$fingerprint])) {
                $resolved += DB::table('brain_recommendations')->whereIn('id', $rows->whereIn('status', ['open', 'blocked'])->pluck('id'))
                    ->update(['status' => 'resolved', 'resolved_at' => now(), 'updated_at' => now()]);
            }
        }

        return ['added' => $added, 'resolved' => $resolved];
    }
}
