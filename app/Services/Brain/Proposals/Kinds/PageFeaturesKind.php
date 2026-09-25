<?php

namespace App\Services\Brain\Proposals\Kinds;

use App\Ai\Agents\Brain\PageFeatureAgent;
use App\Models\BrainProposal;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Brain\BrainAi;
use App\Services\Brain\Clustering\ServiceClusters;
use App\Services\Brain\Proposals\ProposalKind;
use App\Services\Brain\Success\PageFeatureExtractor;
use App\Services\SeoTasks\SeoText;
use App\Support\Ai\AiRouteKeys;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * "Sayfa özellikleri (AI okur)": AI fills a fixed checklist for the cluster pages whose content changed since it
 * last read them (content hash). This is measurement, not a change, so it is stored directly — nothing to approve.
 * The Brain compares these checklists between successful and weaker pages of the same cohort.
 */
final class PageFeaturesKind implements ProposalKind
{
    public const string KIND = 'page_features';

    private const int LIMIT = 40;

    public function __construct(
        private readonly BrainAi $ai,
        private readonly ServiceClusters $clusters,
        private readonly PageFeatureExtractor $extractor,
    ) {}

    public function kind(): string
    {
        return self::KIND;
    }

    public function label(): string
    {
        return 'Sayfa özellikleri (AI okur)';
    }

    public function usesAi(): bool
    {
        return true;
    }

    public function resultNoun(): string
    {
        return 'sayfa okundu';
    }

    public function prepare(array $options): int
    {
        $snapshots = DB::table('brain_success_snapshots')->whereNotNull('url')
            ->when(! empty($options['service_id']), fn ($q) => $q->where('service_id', (int) $options['service_id']))
            ->orderByDesc('period')->orderByDesc('impressions')->limit(400)->get(['digital_asset_id', 'cluster_id', 'url'])
            ->unique(fn ($s): string => $s->digital_asset_id.'|'.SeoText::urlKey((string) $s->url));
        $clusters = $this->clusters->all();
        $read = 0;
        foreach ($snapshots as $snapshot) {
            if ($read >= self::LIMIT) {
                break;
            }
            $row = DB::table('brain_page_features')->where('digital_asset_id', $snapshot->digital_asset_id)->where('url_key', SeoText::urlKey((string) $snapshot->url))->first();
            if ($row !== null && $row->ai_hash !== null && $row->ai_hash === $row->content_hash) {
                continue;
            }
            $site = DigitalAsset::query()->find($snapshot->digital_asset_id);
            $cluster = $clusters[(int) $snapshot->cluster_id] ?? null;
            if ($site === null || $cluster === null) {
                continue;
            }
            $page = $this->extractor->extract($site, (string) $snapshot->url, $cluster['keys'], PageFeatureExtractor::places((int) $site->brand_id));
            if ($page === null || $page['text'] === '') {
                continue;
            }
            try {
                $answer = $this->ai->ask(new PageFeatureAgent, AiRouteKeys::BRAIN_PAGE_FEATURES, [
                    'url' => $snapshot->url, 'topic' => $cluster['name'], 'topic_queries' => array_slice(array_keys($cluster['keys']), 0, 30), 'text' => $page['text'],
                ], 120);
            } catch (Throwable $exception) {
                if ($read === 0) {
                    throw $exception;
                }
                report($exception);

                break;
            }
            $checks = [];
            foreach (array_keys(PageFeatureAgent::CHECKS) as $key) {
                $checks[$key] = (bool) ($answer[$key] ?? false);
            }
            $checks['subtopics_missing'] = array_values(array_slice(array_map(fn ($s): string => mb_substr(strip_tags((string) $s), 0, 120), (array) ($answer['subtopics_missing'] ?? [])), 0, 6));
            DB::table('brain_page_features')->where('digital_asset_id', $site->id)->where('url_key', SeoText::urlKey((string) $snapshot->url))
                ->update(['ai_features' => json_encode($checks, JSON_UNESCAPED_UNICODE), 'ai_hash' => $page['hash'], 'updated_at' => now()]);
            $read++;
        }

        return $read;
    }

    public function apply(BrainProposal $proposal, User $actor): void
    {
        throw new RuntimeException('Sayfa özellikleri onay gerektirmez.');
    }
}
