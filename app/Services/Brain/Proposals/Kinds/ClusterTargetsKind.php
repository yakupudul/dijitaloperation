<?php

namespace App\Services\Brain\Proposals\Kinds;

use App\Models\BrainProposal;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Brain\Clustering\ServiceClusters;
use App\Services\Brain\Proposals\ProposalKind;
use App\Services\Brain\Proposals\ProposalService;
use App\Services\Brain\ServiceSites;
use App\Services\SearchDemand\ManualQueryClusterService;
use Illuminate\Support\Facades\DB;

/**
 * "Küme → sayfa": for each brand website that offers the service and each cluster without a target page, proposes
 * the URL Google already shows most for that cluster's queries (Search Console, last 90 days). No AI: the confidence
 * is the share of the cluster's impressions that page gets. Clusters with no page are left for "sayfa oluştur".
 */
final class ClusterTargetsKind implements ProposalKind
{
    public const string KIND = 'cluster_targets';

    private const float MIN_SHARE = 0.4;

    private const int MIN_IMPRESSIONS = 20;

    public function __construct(
        private readonly ServiceClusters $clusters,
        private readonly ServiceSites $sites,
        private readonly ProposalService $proposals,
    ) {}

    public function kind(): string
    {
        return self::KIND;
    }

    public function label(): string
    {
        return 'Küme → hedef sayfa';
    }

    public function usesAi(): bool
    {
        return false;
    }

    public function resultNoun(): string
    {
        return 'yeni öneri';
    }

    public function prepare(array $options): int
    {
        $clusters = collect($this->clusters->all(! empty($options['service_id']) ? [(int) $options['service_id']] : null))
            ->filter(fn (array $c): bool => $c['page_type'] !== 'faq' && $c['keys'] !== []);
        $targets = DB::table('library_cluster_targets')->where('url', '!=', '')->get(['cluster_key', 'digital_asset_id'])
            ->mapWithKeys(fn ($t): array => [$t->cluster_key.'|'.$t->digital_asset_id => true]);
        $created = 0;
        foreach ($clusters->groupBy('service_id') as $serviceId => $serviceClusters) {
            foreach ($this->sites->websites((int) $serviceId) as $site) {
                $rows = $this->sites->gscRows($site);
                if ($rows === []) {
                    continue;
                }
                foreach ($serviceClusters as $cluster) {
                    if (isset($targets[$cluster['id'].'|'.$site->id])) {
                        continue;
                    }
                    $pages = ServiceClusters::pages($rows, $cluster['keys']);
                    $total = array_sum(array_column($pages, 'impressions'));
                    $best = reset($pages);
                    if ($best === false || $total < self::MIN_IMPRESSIONS || $best['impressions'] / $total < self::MIN_SHARE) {
                        continue;
                    }
                    $share = $best['impressions'] / $total;
                    $created += $this->proposals->propose(self::KIND, 'cluster_site', $this->subjectId($cluster['id'], $site->id), $site->brand_id,
                        ($site->brand?->name ?? $site->name).' · '.$cluster['name'].' → '.$best['url'], null,
                        ['service_id' => (int) $serviceId, 'cluster_id' => $cluster['id'], 'digital_asset_id' => (int) $site->id, 'url' => $best['url']],
                        'Bu kümenin Search Console gösterimlerinin %'.(int) round($share * 100).'\'i bu sayfada ('.$best['impressions'].' / '.$total.').',
                        $share, 'system') !== null ? 1 : 0;
                }
            }
        }

        return $created;
    }

    public function apply(BrainProposal $proposal, User $actor): void
    {
        $p = $proposal->proposed;
        $manual = app(ManualQueryClusterService::class);
        $manual->cluster((int) $p['service_id'], (int) $p['cluster_id']);
        DigitalAsset::query()->where('type', 'website')->findOrFail((int) $p['digital_asset_id']);
        $existing = DB::table('library_cluster_targets')->where('service_id', $p['service_id'])->where('cluster_key', $p['cluster_id'])->where('digital_asset_id', $p['digital_asset_id'])->first();
        $values = ['url' => (string) $p['url'], 'source' => 'brain', 'score' => $proposal->confidence, 'updated_by' => $actor->id, 'updated_at' => now()];
        if ($existing !== null) {
            DB::table('library_cluster_targets')->where('id', $existing->id)->update($values + ['revision' => (int) $existing->revision + 1]);
        } else {
            DB::table('library_cluster_targets')->insert($values + ['service_id' => $p['service_id'], 'cluster_key' => $p['cluster_id'], 'digital_asset_id' => $p['digital_asset_id'], 'revision' => 1, 'created_at' => now()]);
        }
    }

    /** One subject per cluster × site (so a newer proposal supersedes an older one for the same pair). */
    private function subjectId(int $clusterId, int $siteId): int
    {
        return $clusterId * 1_000_000 + $siteId;
    }
}
