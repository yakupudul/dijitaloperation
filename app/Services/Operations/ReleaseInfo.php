<?php

namespace App\Services\Operations;

use Throwable;

/**
 * The deployed release: deploy/staging/deploy.sh writes storage/app/release.json ({"sha", "deployed_at"}) so
 * errors and health readings can be tied to a Git SHA. Read once per process; missing file means "unknown".
 */
final class ReleaseInfo
{
    /** @var array{sha: ?string, deployed_at: ?string}|null */
    private static ?array $current = null;

    public static function path(): string
    {
        return storage_path('app/release.json');
    }

    /** @return array{sha: ?string, deployed_at: ?string} */
    public static function current(): array
    {
        if (self::$current !== null) {
            return self::$current;
        }

        $release = ['sha' => null, 'deployed_at' => null];
        try {
            if (is_file(self::path())) {
                $data = json_decode((string) file_get_contents(self::path()), true);
                if (is_array($data)) {
                    $sha = is_string($data['sha'] ?? null) ? trim($data['sha']) : '';
                    $release = [
                        'sha' => preg_match('/^[0-9a-f]{7,40}$/', $sha) === 1 ? $sha : null,
                        'deployed_at' => is_string($data['deployed_at'] ?? null) ? $data['deployed_at'] : null,
                    ];
                }
            }
        } catch (Throwable) {
            // unreadable release file: stay "unknown"
        }

        return self::$current = $release;
    }

    public static function shortSha(): ?string
    {
        $sha = self::current()['sha'];

        return $sha !== null ? substr($sha, 0, 12) : null;
    }

    /** Test / long-running process hook: read the file again on next access. */
    public static function forget(): void
    {
        self::$current = null;
    }
}
