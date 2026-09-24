<?php

namespace App\Services\Intel;

use App\Models\Brand;
use App\Services\BrandSetup\BrandSetupMatcher;
use App\Services\Demand\DemandPageFetcher;
use Illuminate\Support\Facades\DB;

/**
 * Is the promised link on the page? For opportunities marked waiting / live with a page URL, the page is fetched
 * (safe public fetcher) and searched for a link to the brand's site: found → live, a live one gone → lost.
 */
final class BacklinkLinkChecker
{
    public function __construct(
        private readonly DemandPageFetcher $fetcher,
        private readonly BrandGbpIdentity $identity,
    ) {}

    public function check(object $opportunity): ?bool
    {
        if (blank($opportunity->link_url)) {
            return null;
        }
        $brand = Brand::query()->find($opportunity->brand_id);
        $hosts = $brand !== null ? $this->identity->for($brand)['hosts'] : [];
        $page = $this->fetcher->fetch((string) $opportunity->link_url);
        $found = null;
        if ($page['html'] !== null && $hosts !== []) {
            $found = false;
            preg_match_all('/<a\b[^>]*href\s*=\s*["\']([^"\']+)["\']/i', $page['html'], $matches);
            foreach ($matches[1] as $href) {
                $host = preg_replace('/^www\./', '', BrandSetupMatcher::host(html_entity_decode($href))) ?? '';
                if ($host !== '' && in_array($host, $hosts, true)) {
                    $found = true;
                    break;
                }
            }
        }
        $status = $opportunity->status;
        if ($found === true && in_array($status, ['waiting', 'lost', 'contacted'], true)) {
            $status = 'live';
        } elseif ($found === false && $status === 'live') {
            $status = 'lost';
        }
        DB::table('backlink_opportunities')->where('id', $opportunity->id)->update(['link_found' => $found, 'status' => $status, 'last_checked_at' => now(), 'updated_at' => now()]);

        return $found;
    }

    /** @return array{checked: int, live: int, lost: int} */
    public function runDue(): array
    {
        $stats = ['checked' => 0, 'live' => 0, 'lost' => 0];
        $rows = DB::table('backlink_opportunities')->whereIn('status', ['waiting', 'live'])->whereNotNull('link_url')
            ->where(fn ($q) => $q->whereNull('last_checked_at')->orWhere('last_checked_at', '<', now()->subDays((int) config('moxdop-intel.backlinks.check_every_days', 7))))
            ->orderBy('id')->limit(200)->get();
        foreach ($rows as $row) {
            $before = $row->status;
            $found = $this->check($row);
            $stats['checked']++;
            $stats['live'] += $found === true && $before !== 'live' ? 1 : 0;
            $stats['lost'] += $found === false && $before === 'live' ? 1 : 0;
        }

        return $stats;
    }
}
