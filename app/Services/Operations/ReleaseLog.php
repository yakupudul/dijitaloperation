<?php

namespace App\Services\Operations;

use Throwable;

/**
 * Sürümler (yakup, 2026-10-06: "çıkıp çıkmadığını görebileceğim bir yer"): what went live and what is still waiting.
 * deploy/staging/deploy.sh appends every deploy with the commits it brought live to storage/app/deploy-history.jsonl;
 * deploy/staging/auto-deploy.sh lists the watched branches' commits that are not live yet in
 * storage/app/auto-deploy-pending.tsv (sha, branch, time, subject).
 */
final class ReleaseLog
{
    public static function historyPath(): string
    {
        return storage_path('app/deploy-history.jsonl');
    }

    public static function pendingPath(): string
    {
        return storage_path('app/auto-deploy-pending.tsv');
    }

    /** @return list<array{sha: string, deployed_at: ?string, commits: list<array{sha: string, at: ?string, subject: string}>}> newest first */
    public static function deploys(int $limit = 20): array
    {
        $deploys = [];
        foreach (array_reverse(self::lines(self::historyPath())) as $line) {
            $data = json_decode($line, true);
            if (! is_array($data) || ! self::isSha($data['sha'] ?? null)) {
                continue;
            }
            $commits = [];
            foreach ((array) ($data['commits'] ?? []) as $commit) {
                if (is_array($commit) && self::isSha($commit['sha'] ?? null)) {
                    $commits[] = ['sha' => (string) $commit['sha'], 'at' => is_string($commit['at'] ?? null) ? $commit['at'] : null, 'subject' => (string) ($commit['subject'] ?? '')];
                }
            }
            $deploys[] = ['sha' => (string) $data['sha'], 'deployed_at' => is_string($data['deployed_at'] ?? null) ? $data['deployed_at'] : null, 'commits' => $commits];
            if (count($deploys) >= $limit) {
                break;
            }
        }

        return $deploys;
    }

    /** @return list<array{sha: string, branch: string, at: ?string, subject: string}> newest first; empty without auto deploy */
    public static function pending(): array
    {
        $pending = [];
        foreach (self::lines(self::pendingPath()) as $line) {
            [$sha, $branch, $at, $subject] = array_pad(explode("\t", $line, 4), 4, '');
            if (self::isSha($sha)) {
                $pending[] = ['sha' => $sha, 'branch' => $branch, 'at' => $at !== '' ? $at : null, 'subject' => $subject];
            }
        }
        usort($pending, fn (array $a, array $b): int => strcmp((string) $b['at'], (string) $a['at']));

        return $pending;
    }

    /** @return list<string> */
    private static function lines(string $path): array
    {
        try {
            return is_file($path) ? (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
        } catch (Throwable) {
            return [];
        }
    }

    private static function isSha(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{40}$/', $value) === 1;
    }
}
