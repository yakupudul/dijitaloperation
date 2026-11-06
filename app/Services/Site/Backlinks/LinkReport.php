<?php

namespace App\Services\Site\Backlinks;

use App\Models\BacklinkSource;
use App\Models\Brand;
use App\Models\LinkTarget;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Aylık bağlantı raporu (Backlink hizmeti mimarisi, katman 8): what the month brought for one brand, by rules from the
 * desk (no AI text): the links that came and were verified (site, our page, link type), the applications made and
 * waiting, listings that show a different phone / address than the Business Profile, links that were lost, and how
 * the target pages moved since they were picked (60 / 90 days after their first link when that time has come).
 * The operator prints it to PDF or shares it with the client.
 */
final class LinkReport
{
    /**
     * @return array{brand: Brand, month: CarbonImmutable, won: Collection<int, BacklinkSource>, applied: int, waiting: int, lost: Collection<int, BacklinkSource>,
     *               nap: Collection<int, BacklinkSource>, targets: Collection<int, LinkTarget>, total: int, package: string, budget: int}
     */
    public static function build(Brand $brand, CarbonImmutable $month): array
    {
        $from = $month->startOfMonth()->utc();
        $to = $month->endOfMonth()->utc();
        $rows = BacklinkSource::query()->with('target:id,url,title')->where('brand_id', $brand->id)->get();

        return [
            'brand' => $brand,
            'month' => $month,
            'won' => $rows->filter(fn (BacklinkSource $r): bool => in_array($r->status, [BacklinkSource::VERIFIED, BacklinkSource::REMOVED], true)
                && $r->verified_at !== null && $r->verified_at->between($from, $to))->sortBy('verified_at')->values(),
            'applied' => $rows->filter(fn (BacklinkSource $r): bool => $r->sent_at !== null && $r->sent_at->between($from, $to))->count(),
            'waiting' => $rows->where('status', BacklinkSource::APPLIED)->count(),
            'lost' => $rows->filter(fn (BacklinkSource $r): bool => $r->status === BacklinkSource::REMOVED && $r->checked_at !== null && $r->checked_at->between($from, $to))->values(),
            'nap' => $rows->filter(fn (BacklinkSource $r): bool => $r->status === BacklinkSource::VERIFIED && LinkNapCheck::line($r) !== null)->values(),
            'targets' => LinkTarget::query()->where('brand_id', $brand->id)->whereIn('status', [LinkTarget::ACTIVE, LinkTarget::DONE])->where('picked_at', '<=', $to)
                ->orderBy('status')->orderBy('rank')->get(),
            'total' => $rows->where('status', BacklinkSource::VERIFIED)->count(),
            'package' => LinkDesk::PACKAGE_LABELS[$brand->link_package ?? 'temel'] ?? (string) $brand->link_package,
            'budget' => LinkDesk::budget($brand),
        ];
    }
}
