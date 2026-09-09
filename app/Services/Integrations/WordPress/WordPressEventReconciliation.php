<?php

namespace App\Services\Integrations\WordPress;

use App\Models\Collection\CollectionRun;
use App\Models\CoreConnection;
use App\Services\Collection\Providers\Website\WebsiteRequestFamilyCatalog;
use App\Services\Collection\Website\WebsiteCollectionOrchestrator;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Admits bounded work to the existing queue; never performs provider HTTP here. */
final class WordPressEventReconciliation
{
    public function tick(): void
    {
        DB::table('website_connector_nonces')->where('created_at', '<', now()->subHour())->delete();
        $active = DB::table('website_connector_delivery')->whereNotNull('collection_run_id')->get();
        foreach ($active as $state) {
            $run = CollectionRun::query()->find($state->collection_run_id);
            $status = $run?->status?->value;
            if (in_array($status, ['queued', 'running', 'retrying'], true)) {
                continue;
            }
            $update = ['collection_run_id' => null, 'next_reconcile_at' => now()->addMinutes(10)];
            if ($status === 'completed') {
                $update += ['reconciled_event_id' => $state->collection_event_id, 'last_reconciled_at' => now(), 'last_error' => null];
                if ($state->collection_is_full) {
                    $update['last_inventory_at'] = now();
                }
            } else {
                $update += ['last_error' => 'WordPress veri yenilemesi tamamlanamadı; çekim geçmişini inceleyin.', 'next_reconcile_at' => now()->addHour()];
                $update['next_reconcile_at'] = now()->addHour();
            }
            DB::table('website_connector_delivery')->where('connection_id', $state->connection_id)->where('collection_run_id', $state->collection_run_id)->update($update);
        }
        $slots = max(0, 2 - DB::table('website_connector_delivery')->whereNotNull('collection_run_id')->count());
        if ($slots === 0) {
            return;
        }
        $states = DB::table('website_connector_delivery')
            ->whereNull('collection_run_id')->where('last_received_at', '>=', now()->subDay())
            ->where(fn ($q) => $q->whereNull('next_reconcile_at')->orWhere('next_reconcile_at', '<=', now()))
            ->where(fn ($q) => $q->whereColumn('latest_event_id', '>', 'reconciled_event_id')
                ->orWhereNull('last_inventory_at')->orWhere('last_inventory_at', '<=', now()->subDay()))
            ->orderBy('next_reconcile_at')->limit(20)->get();
        foreach ($states as $state) {
            if ($slots < 1) {
                break;
            }
            $connection = CoreConnection::query()->with(['digitalAsset', 'credential'])->find($state->connection_id);
            if (! $connection?->enabled || data_get($connection->config, 'pairing_state') !== 'paired'
                || ! $connection->digitalAsset || ! $connection->credential
                || version_compare((string) $state->plugin_version, '1.1.0', '<')) {
                continue;
            }
            if (CollectionRun::query()->where('digital_asset_id', $connection->digital_asset_id)
                ->whereIn('status', ['queued', 'running', 'retrying'])->exists()) {
                continue;
            }
            $full = $state->last_inventory_at === null || strtotime($state->last_inventory_at) <= now()->subDay()->getTimestamp()
                || ($state->gap_at && strtotime($state->gap_at) > strtotime($state->last_inventory_at ?? '1970-01-01'));
            $events = DB::table('website_connector_events')->where('connection_id', $connection->id)
                ->where('id', '>', $state->reconciled_event_id)->orderBy('id')->limit(50)->get();
            $ids = $events->filter(fn ($e) => str_starts_with($e->type, 'content.') || str_starts_with($e->type, 'seo.'))
                ->pluck('object_id')->filter(fn ($id) => ctype_digit($id) && (int) $id > 0)->map(fn ($id) => (int) $id)->unique()->values()->all();
            // Global template/settings updates require a fresh inventory.
            $full = $full || $events->contains(fn ($e) => $e->type === 'settings.updated' || $e->type === 'maintenance.theme_changed');
            $watermark = $full ? (int) $state->latest_event_id : (int) ($events->max('id') ?? $state->reconciled_event_id);
            $context = [
                'force_refresh' => true,
                'idempotency_key' => 'wp-events:'.$connection->id.':'.$watermark.':'.now()->format('YmdHi'),
                'collection_intent' => 'wordpress_event_reconciliation',
                'collection_intent_label' => $full ? 'WordPress inventory reconciliation' : 'WordPress changed-object refresh',
                'wordpress_object_ids' => $full ? [] : $ids,
            ];
            $families = [WebsiteRequestFamilyCatalog::FAMILY_WP_REST];
            $urls = [];
            if ($ids !== []) {
                $urls = DB::table('website_cms_object_snapshot')->where('digital_asset_id', $connection->digital_asset_id)
                    ->whereIn('object_id', array_map('strval', $ids))->pluck('permalink')->all();
            }
            foreach ($events as $event) {
                $url = data_get(json_decode($event->payload, true), 'url');
                if (is_string($url) && $url !== '') {
                    $urls[] = $url;
                }
            }
            $host = strtolower((string) parse_url($connection->digitalAsset->primary_url ?: ('https://'.$connection->digitalAsset->domain), PHP_URL_HOST));
            $urls = array_values(array_unique(array_filter($urls, fn ($url) => is_string($url)
                && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)
                && strtolower((string) parse_url($url, PHP_URL_HOST)) === $host
                && parse_url($url, PHP_URL_USER) === null && parse_url($url, PHP_URL_PASS) === null)));
            if ($urls !== []) {
                $families[] = WebsiteRequestFamilyCatalog::FAMILY_PUBLIC_CRAWL;
                $context['targeted_verification'] = ['version' => 1, 'urls' => array_slice($urls, 0, 100),
                    'candidate_url_count' => count($urls), 'truncated' => count($urls) > 100,
                    'issue_code' => 'WORDPRESS_CHANGE', 'relation_key' => 'wordpress-events'];
            }
            try {
                $run = app(WebsiteCollectionOrchestrator::class)->start(
                    asset: $connection->digitalAsset, requestFamilyIds: $families, context: $context,
                );
                DB::table('website_connector_delivery')->where('connection_id', $connection->id)->update([
                    'collection_run_id' => $run->id, 'collection_event_id' => $watermark,
                    'collection_is_full' => $full, 'last_error' => null,
                ]);
                $slots--;
            } catch (Throwable $error) {
                DB::table('website_connector_delivery')->where('connection_id', $connection->id)->update([
                    'last_error' => 'WordPress yenilemesi başlatılamadı: '.class_basename($error),
                    'next_reconcile_at' => now()->addHour(),
                ]);
            }
        }
    }
}
