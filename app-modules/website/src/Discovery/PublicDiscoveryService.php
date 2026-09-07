<?php

namespace MoxDop\Website\Discovery;

use App\Models\BrandOfferingName;
use App\Models\DigitalAsset;
use App\Models\DiscoveryCandidate;
use App\Models\Evidence;
use App\Models\Run;
use App\Services\Website\PublicDiscovery\StoredDiscoverySource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Stored public facts only. Collection and human application have separate, explicit boundaries. */
final class PublicDiscoveryService
{
    public function __construct(
        private readonly StoredDiscoverySource $source,
        private readonly DiscoveryCandidateBuilder $builder,
    ) {}

    /** @return array<string, mixed> */
    public function discover(DigitalAsset $asset): array
    {
        if ($asset->type !== 'website') {
            throw new InvalidArgumentException('Public discovery requires a Website Digital Asset.');
        }

        return Cache::lock('stored-discovery:'.$asset->id, 330)->block(5, function () use ($asset): array {
            $crawl = $this->source->read($asset);
            $names = BrandOfferingName::query()->where('brand_id', $asset->brand_id)->where('is_active', true)
                ->whereHas('offering', fn ($query) => $query->where('status', 'active'))->orderBy('normalized_key')->pluck('raw_label')->all();
            $crawl['input_fingerprint'] = hash('sha256', json_encode([$crawl['input_fingerprint'], $names], JSON_THROW_ON_ERROR));
            $cached = $crawl['status'] === 'succeeded' ? Run::query()
                ->where('digital_asset_id', $asset->id)->where('module_id', DiscoveryConfig::MODULE_ID)
                ->where('status', 'completed')->where('metadata->discovery_status', 'succeeded')
                ->where('metadata->input_fingerprint', $crawl['input_fingerprint'])->latest('id')->first() : null;
            if ($cached !== null) {
                return $this->result($cached, true);
            }

            return DB::transaction(function () use ($asset, $crawl, $names): array {
                $run = Run::query()->create([
                    'digital_asset_id' => $asset->id, 'module_id' => DiscoveryConfig::MODULE_ID,
                    'status' => 'running', 'started_at' => now(),
                    'metadata' => ['trigger' => 'manual', 'human_title' => 'Kayıtlı veriden kamu keşfi',
                        'discovery_mode' => DiscoveryConfig::VERSION, 'brand_id' => $asset->brand_id],
                ]);
                $evidenceIds = [];
                foreach ($crawl['pages'] as $page) {
                    $evidence = Evidence::query()->create([
                        'run_id' => $run->id, 'digital_asset_id' => $asset->id,
                        'source_module' => DiscoveryConfig::MODULE_ID, 'type' => DiscoveryConfig::EVIDENCE_PAGE_SNAPSHOT,
                        'title' => mb_substr('Public page: '.($page['extracted']['title'] ?? $page['final_url']), 0, 255),
                        'payload' => array_merge($page, [
                            'ok' => true, 'source' => 'stored_html', 'source_url' => $page['final_url'],
                            'retrieved_at' => $page['observed_at'], 'processed_at' => now()->toIso8601String(),
                            'normalization_version' => DiscoveryConfig::VERSION,
                        ]),
                        'observed_at' => $page['observed_at'],
                    ]);
                    $evidenceIds[] = $evidence->id;
                }
                $payload = array_diff_key($crawl, ['pages' => true]);
                $payload += [
                    'ok' => $crawl['status'] !== 'failed', 'source' => 'stored_html',
                    'page_urls' => array_column($crawl['pages'], 'final_url'),
                    'processed_at' => now()->toIso8601String(),
                    'retrieved_at' => collect($crawl['pages'])->max('observed_at'),
                    'normalization_version' => DiscoveryConfig::VERSION,
                    'competitor_status' => 'not_requested', 'competitor_count' => 0,
                    'competitor_message' => 'Bu aşama kayıtlı site bilgilerini inceler. Yeni rakip araştırması çalıştırılmaz.',
                    'ai' => ['attempted' => false, 'reason' => 'deterministic_stored_discovery'],
                    'paid_requests' => 0,
                ];
                $summary = Evidence::query()->create([
                    'run_id' => $run->id, 'digital_asset_id' => $asset->id,
                    'source_module' => DiscoveryConfig::MODULE_ID, 'type' => DiscoveryConfig::EVIDENCE_SITE_SUMMARY,
                    'title' => 'Stored public discovery summary', 'payload' => $payload, 'observed_at' => now(),
                ]);
                $evidenceIds[] = $summary->id;
                $count = $this->persistCandidates($asset, $run, $summary->id, $this->builder->fromCrawl($crawl, $names));
                $run->update([
                    'status' => $crawl['status'] === 'failed' ? 'failed' : 'completed', 'finished_at' => now(),
                    'metadata' => array_merge($run->metadata, $payload, [
                        'discovery_status' => $crawl['status'], 'fact_candidates' => $count,
                        'inference_candidates' => 0, 'competitor_candidates' => 0, 'evidence_ids' => $evidenceIds,
                    ]),
                ]);

                return $this->result($run);
            });
        });
    }

    /** @return array<string, mixed> */
    private function result(Run $run, bool $cached = false): array
    {
        $meta = $run->metadata;

        return [
            'run' => $run, 'status' => $meta['discovery_status'], 'cached' => $cached,
            'message' => $cached ? 'Kayıtlı kaynaklar değişmedi; önceki keşif sonucu kullanıldı.' : match ($meta['discovery_status']) {
                'succeeded' => 'Kayıtlı sayfalar incelendi. Adayları gözden geçirip ilgili kayıtlara aktarabilirsiniz.',
                'partial' => 'Okunabilen güncel sayfalar incelendi. Kapsam eksiklerini kontrol edin.',
                default => 'İncelenebilecek güncel HTML bulunamadı. Veri toplama sonucunu kontrol edip yeniden deneyin.',
            },
            'pages_inspected' => $meta['pages_inspected'], 'fact_candidates' => $meta['fact_candidates'],
            'inference_candidates' => 0, 'competitor_candidates' => 0,
            'evidence_ids' => $meta['evidence_ids'], 'coverage' => $meta['coverage'],
        ];
    }

    private function persistCandidates(DigitalAsset $asset, Run $run, int $evidenceId, array $rows): int
    {
        $existing = DiscoveryCandidate::query()->where('digital_asset_id', $asset->id)
            ->where('brand_id', $asset->brand_id)->orderBy('id')->lockForUpdate()->get()->groupBy(fn ($candidate) => $this->builder->identity($candidate->candidate_kind, $candidate->target_field, $candidate->proposed_value));
        foreach ($rows as $row) {
            $key = $this->builder->identity($row['candidate_kind'], $row['target_field'], $row['proposed_value']);
            $candidate = ($existing->get($key) ?? collect())->sortBy(fn ($item) => $item->status === 'pending' ? 1 : 0)->first();
            if ($candidate !== null) {
                // Review decisions and their receipts survive new observations and legacy fingerprints.
                $candidate->update([
                    'run_id' => $run->id, 'evidence_id' => $evidenceId,
                    'support_json' => array_merge($candidate->support_json ?? [], $row['support_json']),
                    'support_label' => $row['support_label'],
                ]);
            } else {
                DiscoveryCandidate::query()->create(array_merge($row, [
                    'brand_id' => $asset->brand_id, 'digital_asset_id' => $asset->id,
                    'run_id' => $run->id, 'evidence_id' => $evidenceId,
                    'fingerprint' => hash('sha256', $asset->id.'|'.$key), 'status' => DiscoveryCandidate::STATUS_PENDING,
                ]));
            }
        }

        return count($rows);
    }
}
