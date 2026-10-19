<?php

namespace App\Services\Work;

use App\Models\Suggestion;
use App\Services\Brand\BrandDataAudit;
use App\Services\Gbp\Desk\ProfileInfo;
use App\Services\GoogleAds\GoogleAdsChanges;
use App\Services\GoogleAds\GoogleAdsChecks;
use App\Services\Repair\RepairDesk;
use App\Services\Repair\WebHealthAudit;
use App\Services\Site\SiteSuggestionTypes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Açık işler, one reader for every screen and for AI (yakup, 2026-10-11: "Arama 10672" was noise nobody can act on).
 * Open work is counted and listed in packages: the same brand's work of the same type is ONE item ("Başlık / açıklama:
 * 412 sayfa"), grouped with the Onarım masası's own packaging (RepairDesk::packages) and ordered by expected gain
 * (RepairDesk::gain: priority × the page's 90-day Search Console reach; pages Google did not show come last).
 *
 * Work type = channel + action type, and for system checks (Google Ads / Meta / Clarity checks, web health, profile
 * standards) the check itself: "Bütçe kullanımı" and "Dönüşüm izleme" are different work, 400 pages missing a
 * description are one. Open = open, recheck, approved but not yet sent, or a snooze that ran out.
 *
 * Used by: the brand file (BrandDossier "Açık işler", read by AI), the brand page (BrandOverviewReader), the portfolio
 * counters (PortfolioSignalsReader), Bugün and the desk's brand health (both through RepairDesk::packages).
 */
final class OpenWork
{
    /** Rows read per brand for a package list (the packages still count every row through counts()). */
    public const int ROW_LIMIT = 20000;

    public function __construct(private readonly RepairDesk $desk) {}

    /** @return Builder<Suggestion> */
    public function query(?int $brandId = null, ?string $channel = null): Builder
    {
        return Suggestion::query()
            ->when($brandId !== null, fn (Builder $q): Builder => $q->where('brand_id', $brandId))
            ->when($channel !== null, fn (Builder $q): Builder => $q->where('channel', $channel))
            ->where(fn (Builder $q): Builder => $q->whereIn('status', [Suggestion::OPEN, Suggestion::RECHECK])
                ->orWhere(fn (Builder $s): Builder => $s->where('status', Suggestion::SNOOZED)->where('snoozed_until', '<=', now()))
                ->orWhere(fn (Builder $a): Builder => $a->where('status', Suggestion::APPROVED)->whereNull('action->article_write_id')));
    }

    /**
     * The package key of one open row: brand, channel, action type and (system checks) the check.
     *
     * @param  array{brand_id: int|string, channel: ?string, action_type: ?string, check?: ?string}  $row
     */
    public static function key(array $row): string
    {
        return (int) $row['brand_id'].'|'.self::type((string) ($row['channel'] ?? ''), $row['action_type'] ?? null, $row['check'] ?? null);
    }

    public static function type(string $channel, ?string $actionType, ?string $check = null): string
    {
        return $channel.':'.($actionType ?: '-').($check !== null && $check !== '' ? ':'.$check : '');
    }

    /**
     * Open packages per brand: how many packages, how many rows behind them, how many packages hold a critical row, and
     * packages per channel. One aggregate query.
     *
     * @param  list<int>|null  $brandIds  null = every brand
     * @return array<int, array{packages: int, items: int, critical: int, by_channel: array<string, int>}>
     */
    public function counts(?array $brandIds = null): array
    {
        $rows = $this->query()->when($brandIds !== null, fn (Builder $q): Builder => $q->whereIn('brand_id', $brandIds ?: [0]))
            ->select(['brand_id', 'channel', 'action_type', 'action->check as check_id', 'action->standard_id as standard_id'])
            ->selectRaw('count(*) as n, min(priority) as top')
            ->groupBy('brand_id', 'channel', 'action_type', 'action->check', 'action->standard_id')->toBase()->get();
        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            $brandId = (int) $row->brand_id;
            $key = self::key(['brand_id' => $brandId, 'channel' => $row->channel, 'action_type' => $row->action_type, 'check' => self::checkOf($row->check_id, $row->standard_id)]);
            $out[$brandId] ??= ['packages' => 0, 'items' => 0, 'critical' => 0, 'by_channel' => []];
            $out[$brandId]['items'] += (int) $row->n;
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $out[$brandId]['packages']++;
                $out[$brandId]['by_channel'][(string) $row->channel] = ($out[$brandId]['by_channel'][(string) $row->channel] ?? 0) + 1;
            }
            if ($row->top !== null && (int) $row->top <= 1 && ! isset($seen['critical|'.$key])) {
                $seen['critical|'.$key] = true;
                $out[$brandId]['critical']++;
            }
        }

        return $out;
    }

    /**
     * One brand's open packages, the most gain first.
     *
     * @return Collection<int, array{key: string, brand_id: int, channel: string, channel_label: string, type: string, label: string, count: int, critical: int, impressions: int, clicks: int, measured: bool, unseen: bool, gain: float, pages: int, sample: list<array<string, mixed>>}>
     */
    public function packages(int $brandId, ?string $channel = null): Collection
    {
        $rows = $this->query($brandId, $channel)->orderBy('priority')->orderBy('id')->limit(self::ROW_LIMIT)
            ->get(['id', 'brand_id', 'channel', 'action_type', 'title', 'reason', 'priority', 'page_id', 'target_type', 'target_id', 'action'])
            ->map(function (Suggestion $s): array {
                $action = (array) $s->action;
                $check = self::checkOf($action['check'] ?? null, $action['standard_id'] ?? null);

                return ['id' => (int) $s->id, 'brand_id' => (int) $s->brand_id, 'brand' => '', 'channel' => (string) $s->channel,
                    'action_type' => $s->action_type, 'check' => $check, 'kind' => self::type((string) $s->channel, $s->action_type, $check),
                    'title' => (string) $s->title, 'reason' => (string) $s->reason, 'priority' => (int) ($s->priority ?? 3),
                    'page_id' => $s->page_id !== null ? (int) $s->page_id : null, 'target_type' => $s->target_type, 'target_id' => $s->target_id !== null ? (int) $s->target_id : null];
            });

        return $this->desk->packages(RepairDesk::withTraffic($rows), fn (array $r): string => self::key($r))
            ->map(function (array $p): array {
                $first = $p['sample'][0];

                return ['key' => $p['key'], 'brand_id' => $p['brand_id'], 'channel' => (string) $first['channel'],
                    'channel_label' => (string) (Suggestion::CHANNEL_LABELS[$first['channel']] ?? $first['channel']), 'type' => $p['kind'],
                    'label' => self::label($first), 'count' => $p['count'], 'critical' => $p['critical'], 'impressions' => $p['impressions'], 'clicks' => $p['clicks'],
                    'measured' => $p['measured'], 'unseen' => $p['unseen'], 'gain' => $p['gain'],
                    'pages' => collect($p['sample'])->pluck('page_id')->filter()->isNotEmpty() ? $p['count'] : 0, 'sample' => $p['sample']];
            })->values();
    }

    /**
     * "Başlık / açıklama: 412 sayfa", "Google Ads · Bütçe kullanımı", or the work's own title when it is one item.
     *
     * @param  array<string, mixed>  $p  a package from packages()
     */
    public static function line(array $p): string
    {
        if ($p['count'] === 1) {
            return (string) $p['sample'][0]['title'];
        }

        return $p['label'].': '.number_format($p['count'], 0, ',', '.').($p['pages'] > 0 ? ' sayfa' : ' iş');
    }

    /** @param  array<string, mixed>  $row */
    public static function label(array $row): string
    {
        $type = (string) ($row['action_type'] ?? '');
        $check = (string) ($row['check'] ?? '');
        $label = match (true) {
            $type === WebHealthAudit::TYPE && isset(WebHealthAudit::CHECKS[$check]) => WebHealthAudit::CHECKS[$check],
            $type === 'ads_check' && isset(GoogleAdsChecks::LABELS[$check]) => GoogleAdsChecks::LABELS[$check],
            $type === 'brand_gap' => 'Kurulum eksiği',
            $type === 'brand_audit' => 'Şef denetimi',
            $type === BrandDataAudit::TYPE => RepairDesk::KINDS[RepairDesk::BRAND_DATA],
            $type === 'ads_negative' => RepairDesk::KINDS[RepairDesk::ADS_NEGATIVE],
            $type === 'gbp_description' => RepairDesk::KINDS[RepairDesk::GBP_DESCRIPTION],
            $type === GoogleAdsChanges::TYPE => RepairDesk::KINDS[RepairDesk::ADS_CHANGE],
            $type === ProfileInfo::TYPE => RepairDesk::KINDS[RepairDesk::GBP_FIELDS],
            // A Business Profile standard is packaged per standard: its own title names the work ("Çalışma saatleri").
            $type === 'gbp_standard' => trim((string) ($row['title'] ?? '')) !== '' ? trim(Str::before((string) $row['title'], ':')) : 'İşletme Profili standardı',
            ($row['channel'] ?? null) === 'search' && $type !== '' && SiteSuggestionTypes::label($type) !== $type => SiteSuggestionTypes::label($type),
            default => null,
        };
        if ($label === null) {
            // The generators title a row "<work>: <where>"; the part before the colon names the work.
            $title = trim((string) ($row['title'] ?? ''));
            $label = str_contains($title, ':') ? trim(Str::before($title, ':')) : ($type !== '' ? $type : $title);
        }
        // Never an internal code ("gbp_standard", "ads_change") on screen: an unknown type is named by its channel.
        if ($label === $type && preg_match('/^[a-z0-9]+(_[a-z0-9]+)+$/', $label) === 1) {
            $label = (Suggestion::CHANNEL_LABELS[$row['channel'] ?? ''] ?? 'Sistem').' işi';
        }

        return Str::ucfirst($label);
    }

    /** The check part of a work type: `action.check`, else `action.standard_id` (as read from JSON). */
    public static function checkOf(mixed $check, mixed $standard): ?string
    {
        $value = is_scalar($check) && (string) $check !== '' ? (string) $check : (is_scalar($standard) && (string) $standard !== '' ? (string) $standard : null);

        // A per-page check ("rage_click:812", Clarity) is one work type: the part before the colon.
        return $value === null ? null : Str::before(trim($value, '"'), ':');
    }
}
