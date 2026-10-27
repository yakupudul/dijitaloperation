<?php

namespace App\Services\Repair;

use App\Ai\Agents\Site\RepairReviewAgent;
use App\Models\Brand;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\Compliance\ForbiddenTerms;
use App\Services\Site\SiteAi;
use Illuminate\Support\Collection;

/**
 * Otomatik onarım (yakup, 2026-10-14; "Günde 1 tık"): every night the prepared rows of the Onarım masası are read once
 * more by AI (`repair.review`, 25 rows per call), the most useful first. The operator's daily click approves the rows
 * that passed through the desk's own approval (ApproveRepairRowsJob on the writes queue, logged and undoable).
 *
 * Never in it: Google Ads and Meta, 301 merges, page text, high-risk and manual rows, Business Profile opening hours.
 * Başlık only on pages Google shows (impressions), açıklama only on pages that bring clicks; SEO plugins fill the rest.
 *
 * The check can wait for Claude (MCP): a batch Claude has not answered stays unchecked, the other batches are still
 * asked, and ReviewRepairRowsJob runs again once Claude answered them all.
 */
final class RepairAutopilot
{
    public const array KINDS = [RepairDesk::SITE_FIELDS, RepairDesk::IMAGE_ALT, RepairDesk::GBP_DESCRIPTION, RepairDesk::GBP_FIELDS, RepairDesk::WEB_FIX, RepairDesk::BRAND_DATA, RepairDesk::SITE_QA];

    public const string KEY = 'repair_review';

    public const int BATCH = 25;

    /** Rows one nightly job reads at most. */
    public const int PER_RUN = 75;

    public function __construct(private readonly RepairDesk $desk) {}

    /** @param  array<string, mixed>  $row */
    public static function eligible(array $row): bool
    {
        if (! in_array($row['kind'] ?? null, self::KINDS, true) || ! in_array($row['risk'] ?? null, [RepairDesk::LOW, RepairDesk::MEDIUM], true)) {
            return false;
        }
        $editable = (array) ($row['editable'] ?? []);
        if (in_array('seo_title', $editable, true) && (int) ($row['impressions'] ?? 0) <= 0) {
            return false;
        }

        return ! in_array('meta_description', $editable, true) || (int) ($row['clicks'] ?? 0) > 0;
    }

    /** What the check read: a changed proposal needs a new check. */
    public static function fingerprint(array $row): string
    {
        return md5((string) json_encode([$row['before'] ?? [], $row['after'] ?? [], $row['target'] ?? '']));
    }

    /** @return Collection<int, array<string, mixed>> eligible desk rows, most useful first */
    public function rows(?int $brandId = null): Collection
    {
        return $this->desk->rows($brandId)->filter(fn (array $r): bool => self::eligible($r))
            ->sortByDesc(fn (array $r): float => RepairDesk::gain($r))->values();
    }

    /**
     * Reads the next unchecked rows (at most PER_RUN).
     *
     * @return array{checked: int, waiting: int} waiting: rows whose batch waits for Claude (MCP)
     */
    public function review(int $limit = self::PER_RUN): array
    {
        $rows = $this->rows();
        $reviews = Suggestion::query()->whereIn('id', $rows->pluck('id'))->get(['id', 'action'])->mapWithKeys(fn (Suggestion $s): array => [(int) $s->id => data_get($s->action, self::KEY)]);
        $todo = $rows->filter(fn (array $r): bool => ($reviews[$r['id']]['fingerprint'] ?? null) !== self::fingerprint($r))->take($limit);
        $checked = 0;
        $waiting = 0;
        foreach ($todo->groupBy('brand_id') as $brandId => $brandRows) {
            $brand = Brand::query()->with('sectorCategory')->find((int) $brandId);
            if ($brand === null) {
                continue;
            }
            foreach ($brandRows->chunk(self::BATCH) as $batch) {
                $done = $this->check($brand, $batch->values());
                if ($done === null) {
                    $waiting += $batch->count();

                    continue;
                }
                $checked += $done;
            }
        }

        return ['checked' => $checked, 'waiting' => $waiting];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return int|null rows checked; null while Claude (MCP) has not answered the batch
     */
    private function check(Brand $brand, Collection $rows): ?int
    {
        $result = app(SiteAi::class)->run(new RepairReviewAgent, [
            'brand' => ['name' => $brand->name, 'sector' => $brand->sectorCategory?->name],
            'forbidden' => ForbiddenTerms::forBrand($brand)->phrases(),
            'rows' => $rows->map(fn (array $r): array => ['id' => (int) $r['id'], 'kind' => RepairDesk::KINDS[$r['kind']] ?? $r['kind'], 'url' => (string) ($r['target'] ?? ''),
                'title' => (string) ($r['page_title'] ?? ''), 'current' => (array) ($r['before'] ?? []), 'proposed' => (array) ($r['after'] ?? []), 'why' => trim($r['title'].' · '.$r['reason'], ' ·')])->all(),
        ], 240, self::slot((int) $brand->id, $rows));
        if ($result['status'] === 'queued') {
            return null;
        }
        if ($result['status'] !== 'ready') {
            return 0; // no verdict: the next night tries again
        }
        $answers = collect((array) ($result['data']['items'] ?? []))->filter(fn ($i): bool => is_array($i) && is_int($i['id'] ?? null))->keyBy('id');
        $suggestions = Suggestion::query()->whereIn('id', $rows->pluck('id'))->get()->keyBy('id');
        foreach ($rows as $row) {
            $answer = (array) $answers->get((int) $row['id'], []);
            $suggestion = $suggestions->get((int) $row['id']);
            if ($suggestion === null) {
                continue;
            }
            $verdict = in_array($answer['verdict'] ?? null, ['ok', 'wrong', 'unsure'], true) ? (string) $answer['verdict'] : 'unsure';
            $suggestion->forceFill(['action' => array_merge((array) $suggestion->action, [self::KEY => ['verdict' => $verdict,
                'reason' => mb_substr(trim((string) ($answer['reason'] ?? 'AI yanıt vermedi.')), 0, 180), 'at' => now()->toIso8601String(), 'fingerprint' => self::fingerprint($row)]])])->save();
        }

        return $rows->count();
    }

    /**
     * The batch's stable name while it waits for Claude: its rows and what they propose (a row's live numbers in "why"
     * may move; a changed proposal is a new batch).
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private static function slot(int $brandId, Collection $rows): string
    {
        return 'repair-'.$brandId.'-'.md5($rows->map(fn (array $r): string => $r['id'].':'.self::fingerprint($r))->sort()->implode(','));
    }

    /**
     * @param  'ok'|'held'  $which
     * @return Collection<int, array<string, mixed>> rows whose current proposal passed (ok) or was held back (held), with the check
     */
    public function checked(string $which, ?int $brandId = null): Collection
    {
        return $this->sorted($this->withReviews($this->rows($brandId)))[$which === 'ok' ? 'ok' : 'held'];
    }

    /**
     * Both lists from one read of the desk (the daily strip shows both; the desk builds thousands of rows).
     *
     * @return array{ok: Collection<int, array<string, mixed>>, held: Collection<int, array<string, mixed>>}
     */
    public function checkedBoth(?int $brandId = null): array
    {
        return $this->sorted($this->withReviews($this->rows($brandId)));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{ok: Collection<int, array<string, mixed>>, held: Collection<int, array<string, mixed>>}
     */
    private function sorted(Collection $rows): array
    {
        $current = $rows->filter(fn (array $r): bool => ($r['review']['fingerprint'] ?? null) === self::fingerprint($r));

        return [
            'ok' => $current->filter(fn (array $r): bool => ($r['review']['verdict'] ?? null) === 'ok')->values(),
            'held' => $current->filter(fn (array $r): bool => in_array($r['review']['verdict'] ?? null, ['wrong', 'unsure'], true))->values(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function withReviews(Collection $rows): Collection
    {
        $reviews = [];
        foreach ($rows->pluck('id')->chunk(1000) as $ids) {
            Suggestion::query()->whereIn('id', $ids->all())->get(['id', 'action'])
                ->each(function (Suggestion $s) use (&$reviews): void {
                    $reviews[(int) $s->id] = data_get($s->action, self::KEY);
                });
        }

        return $rows->map(fn (array $r): array => $r + ['review' => $reviews[$r['id']] ?? null]);
    }

    /**
     * The daily click: the rows that passed go through the desk's own queued approval.
     *
     * @return array{applied: int, skipped_high: int, failed: list<string>}
     */
    public function approveReady(User $user, ?int $brandId = null): array
    {
        $ids = $this->checked('ok', $brandId)->pluck('id')->map(fn ($id): int => (int) $id)->all();

        return $ids === [] ? ['applied' => 0, 'skipped_high' => 0, 'failed' => []] : $this->desk->queueApproval($ids, $user);
    }
}
