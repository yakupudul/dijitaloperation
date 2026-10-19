<?php

namespace App\Services\Site;

use App\Ai\Agents\Site\OverlapCheckAgent;
use App\Models\Brand;
use App\Models\Cluster;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Models\Suggestion;
use Illuminate\Support\Collection;

/**
 * Yanlış çakışmaları ayıklama (yakup, 2026-10-19: "öğürme refleksi ↔ çocuk sedasyonu" was an overlap). Every night
 * Claude reads each open overlap once (`overlap.check`, 25 per call, pages' address, title, H1 and summary):
 * - unrelated: the pairing is a mistake; the row closes with Claude's reason and stays closed until the pair changes;
 * - different on a 301 proposal: never merged, the page is refocused instead (ClusterOverlaps::checked);
 * - same on an "Ayrıştır" proposal that only the address rule kept apart (basis distinct): the 301 is proposed.
 * A pair is read again only when one of its pages changed. Nothing is written to a site.
 */
final class OverlapCheck
{
    public const string KEY = 'check';

    public const int BATCH = 25;

    public const int PER_RUN = 150;

    public function __construct(private readonly ClusterOverlaps $overlaps) {}

    /** @return array{checked: int, closed: int, waiting: int} waiting: pairs whose batch waits for Claude (MCP) */
    public function run(int $limit = self::PER_RUN): array
    {
        $rows = Suggestion::query()->where('decision_key', ClusterOverlaps::DECISION)->whereIn('status', [Suggestion::OPEN, Suggestion::RECHECK])
            ->whereHas('brand', fn ($b) => $b->operational())->orderBy('priority')->orderBy('id')->get();
        $pages = Page::query()->whereIn('id', $rows->flatMap(fn (Suggestion $s): array => [(int) $s->page_id, (int) data_get($s->action, 'main_page_id')])->filter()->unique()->all() ?: [0])
            ->get(['id', 'url', 'path', 'title', 'h1', 'category', 'content_summary', 'content_hash', 'website_asset_id'])->keyBy('id');
        $todo = $rows->filter(fn (Suggestion $s): bool => data_get($s->action, self::KEY.'.fingerprint') !== self::fingerprint($s, $pages))->take($limit);
        $result = ['checked' => 0, 'closed' => 0, 'waiting' => 0];
        $touched = [];
        foreach ($todo->groupBy('brand_id') as $brandId => $brandRows) {
            $brand = Brand::query()->with('sectorCategory')->find((int) $brandId);
            if ($brand === null || ! SiteScope::aiAllowed($brand)) {
                continue;
            }
            foreach ($brandRows->chunk(self::BATCH) as $batch) {
                $done = $this->check($brand, $batch->values(), $pages);
                if ($done === null) {
                    $result['waiting'] += $batch->count();

                    continue;
                }
                $result['checked'] += $done['checked'];
                $result['closed'] += $done['closed'];
                foreach ($batch as $s) {
                    $touched[(int) data_get($s->action, 'site_id')] = (int) $brand->id;
                }
            }
        }
        // A changed verdict changes the proposal: the sites' overlaps are worked out again with it.
        foreach ($touched as $siteId => $brandId) {
            $site = DigitalAsset::query()->find($siteId);
            $brand = Brand::query()->find($brandId);
            if ($site !== null && $brand !== null) {
                $this->overlaps->refresh($site, $brand);
            }
        }

        return $result;
    }

    /**
     * @param  Collection<int, Suggestion>  $rows
     * @param  Collection<int, Page>  $pages
     * @return array{checked: int, closed: int}|null null while Claude (MCP) has not answered the batch
     */
    private function check(Brand $brand, Collection $rows, Collection $pages): ?array
    {
        $clusters = Cluster::query()->whereIn('id', $rows->pluck('cluster_id')->filter()->unique()->all() ?: [0])->pluck('name', 'id');
        $page = fn (?Page $p): ?array => $p === null ? null : ['url' => (string) $p->url, 'title' => (string) $p->title, 'h1' => (string) $p->h1,
            'kind' => (string) ($p->category ?? ''), 'summary' => mb_substr((string) $p->content_summary, 0, 400)];
        $proposal = [ClusterOverlaps::REDIRECT => 'merge', ClusterOverlaps::DIFFERENTIATE => 'separate', ClusterOverlaps::REVIEW => 'review'];
        $result = app(SiteAi::class)->run(new OverlapCheckAgent, [
            'brand' => ['name' => $brand->name, 'sector' => $brand->sectorCategory?->name],
            'pairs' => $rows->map(fn (Suggestion $s): array => ['id' => (int) $s->id, 'topic' => (string) ($clusters[(int) $s->cluster_id] ?? ''),
                'proposal' => $proposal[(string) data_get($s->action, 'recommendation')] ?? 'separate',
                'page' => $page($pages->get((int) $s->page_id)), 'main_page' => $page($pages->get((int) data_get($s->action, 'main_page_id')))])->values()->all(),
        ], 240, 'overlap-check-'.$brand->id.'-'.md5($rows->map(fn (Suggestion $s): string => $s->id.':'.self::fingerprint($s, $pages))->sort()->implode(',')));
        if ($result['status'] === 'queued') {
            return null;
        }
        if ($result['status'] !== 'ready') {
            return ['checked' => 0, 'closed' => 0]; // no verdict: the next night tries again
        }
        $answers = collect((array) ($result['data']['items'] ?? []))->filter(fn ($i): bool => is_array($i) && is_int($i['id'] ?? null))->keyBy('id');
        $done = ['checked' => 0, 'closed' => 0];
        foreach ($rows as $s) {
            $answer = (array) $answers->get((int) $s->id, []);
            $verdict = in_array($answer['verdict'] ?? null, OverlapCheckAgent::VERDICTS, true) ? (string) $answer['verdict'] : 'unsure';
            $reason = mb_substr(trim((string) ($answer['reason'] ?? '')), 0, 180);
            $action = array_merge((array) $s->action, [self::KEY => ['verdict' => $verdict, 'reason' => $reason, 'at' => now()->toIso8601String(),
                'pair' => ClusterOverlaps::pairKey((int) data_get($s->action, 'main_page_id'), (int) $s->page_id), 'fingerprint' => self::fingerprint($s, $pages)]]);
            if ($verdict === 'unrelated') {
                $s->forceFill(['status' => Suggestion::DISMISSED, 'resolved_at' => now(), 'action' => $action,
                    'operator_note' => mb_substr(ClusterOverlaps::CHECK_CLOSED.($reason !== '' ? ' '.$reason : ''), 0, 1000)])->save();
                $done['closed']++;
            } else {
                $s->forceFill(['action' => $action])->save();
            }
            $done['checked']++;
        }

        return $done;
    }

    /** What the check read: both pages' current text (a changed page is read again). @param  Collection<int, Page>  $pages */
    public static function fingerprint(Suggestion $suggestion, Collection $pages): string
    {
        $main = $pages->get((int) data_get($suggestion->action, 'main_page_id'));
        $page = $pages->get((int) $suggestion->page_id);

        return md5(implode('|', [(int) $suggestion->page_id, (int) data_get($suggestion->action, 'main_page_id'), (string) $page?->content_hash, (string) $main?->content_hash]));
    }
}
