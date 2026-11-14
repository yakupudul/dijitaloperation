<?php

namespace App\Services\Assistant;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * A second opinion from outside MoxDOP's own server before a site is called down. The hosts many client sites sit on
 * (Turkish shared hosting) at times drop MoxDOP's server address in their firewall: every request from MoxDOP then
 * times out while visitors open the site normally (2026-11-14 04:51 TR: six sites "down" at once, all open). The
 * public check-host.net service asks the address from a few nodes in other networks.
 *
 * true = at least one outside node got an HTTP answer (the site is up, only MoxDOP cannot reach it), false = every node
 * that answered failed too (a real outage), null = no opinion (the service failed or was too slow): the caller then
 * keeps its own verdict, so a real outage is never hidden because the second opinion was not available.
 */
final class OutsideReachCheck
{
    /** A "reachable from outside" verdict is reused this long, so a site whose host blocks MoxDOP for days is not asked every round. */
    public const REACHABLE_CACHE_MINUTES = 15;

    private const BASE = 'https://check-host.net';

    private const NODES = 3;

    private const POLLS = 5;

    private const POLL_WAIT_SECONDS = 3;

    public function reachable(int $siteId, string $url): ?bool
    {
        $key = 'uptime:outside-ok:'.$siteId;
        if (Cache::get($key) === true) {
            return true;
        }
        $verdict = $this->ask($url);
        if ($verdict === true) {
            Cache::put($key, true, now()->addMinutes(self::REACHABLE_CACHE_MINUTES));
        }

        return $verdict;
    }

    private function ask(string $url): ?bool
    {
        try {
            $start = Http::acceptJson()->connectTimeout(5)->timeout(10)
                ->get(self::BASE.'/check-http', ['host' => $url, 'max_nodes' => self::NODES]);
            $id = $start->successful() ? (string) ($start->json('request_id') ?? '') : '';
            if ($id === '' || preg_match('/^[A-Za-z0-9]+$/', $id) !== 1) {
                return null;
            }
            for ($poll = 0; $poll < self::POLLS; $poll++) {
                Sleep::for(self::POLL_WAIT_SECONDS)->seconds();
                $result = Http::acceptJson()->connectTimeout(5)->timeout(5)->get(self::BASE.'/check-result/'.$id);
                if (! $result->successful() || ! is_array($result->json())) {
                    continue;
                }
                $verdict = self::verdict($result->json());
                if ($verdict !== null) {
                    return $verdict;
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * One node row is [[1|0, seconds, message, "301", ip]] when finished, null while it still works. Any success wins at
     * once; "down" needs every node finished and failed.
     *
     * @param  array<string, mixed>  $nodes
     */
    private static function verdict(array $nodes): ?bool
    {
        if ($nodes === []) {
            return null;
        }
        $pending = false;
        foreach ($nodes as $rows) {
            if (! is_array($rows) || $rows === []) {
                $pending = true;

                continue;
            }
            foreach ($rows as $row) {
                $code = is_array($row) ? (int) ($row[3] ?? 200) : 0;
                if (is_array($row) && (int) ($row[0] ?? 0) === 1 && $code >= 200 && $code < 400) {
                    return true;
                }
            }
        }

        return $pending ? null : false;
    }
}
