<?php

namespace App\Services\Operations;

use Throwable;

/**
 * Otomatik deploy (yakup, 2026-10-06): deploy/staging/auto-deploy.sh writes storage/app/auto-deploy.json after every
 * check, so Ayarlar › Geliştirme havuzu shows whether new code went live, waits for tests or was stopped and why.
 */
final class AutoDeployStatus
{
    public const array LABELS = [
        'installed' => 'Kuruldu', 'idle' => 'Yeni commit yok', 'testing' => 'Testler çalışıyor', 'deploying' => 'Deploy ediliyor', 'deployed' => 'Canlıya alındı',
        'tests_failed' => 'Testler geçmedi, canlıya alınmadı', 'deploy_failed' => 'Deploy durdu', 'blocked' => 'Beklemede', 'paused' => 'Durduruldu',
    ];

    public const array PROBLEMS = ['tests_failed', 'deploy_failed', 'blocked'];

    public const string CRON_FILE = '/etc/cron.d/moxdop-autodeploy';

    public static function path(): string
    {
        return storage_path('app/auto-deploy.json');
    }

    /** @return array{state: string, label: string, problem: bool, branch: string, sha: string, message: string, checked_at: ?string}|null null when auto deploy was never installed */
    public static function current(): ?array
    {
        try {
            if (! is_file(self::path())) {
                // Installed (cron file present) but the first check has not run yet.
                return is_file(self::CRON_FILE) ? ['state' => 'installed', 'label' => self::LABELS['installed'], 'problem' => false, 'branch' => '', 'sha' => '',
                    'message' => 'İlk kontrol en geç 15 dakika içinde.', 'checked_at' => null] : null;
            }
            $raw = (string) file_get_contents(self::path());
        } catch (Throwable) {
            return null;
        }
        // A test log cut into the message can carry control characters or half a UTF-8 letter: read it anyway rather
        // than reporting "not installed" over a real (often failed) check.
        $data = json_decode((string) preg_replace('/[\x00-\x1F\x7F]/', ' ', $raw), true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
        if (! is_array($data) && preg_match('/"state":"([a-z_]+)"/', $raw, $state) === 1) {
            $data = ['state' => $state[1], 'message' => 'Son kontrolün ayrıntısı okunamadı; sunucuda storage/logs/auto-deploy.log.'];
        }
        if (! is_array($data) || ! isset(self::LABELS[$data['state'] ?? ''])) {
            return null;
        }
        $sha = (string) ($data['sha'] ?? '');

        return ['state' => $data['state'], 'label' => self::LABELS[$data['state']], 'problem' => in_array($data['state'], self::PROBLEMS, true),
            'branch' => (string) ($data['branch'] ?? ''), 'sha' => preg_match('/^[0-9a-f]{7,40}$/', $sha) === 1 ? substr($sha, 0, 8) : '',
            'message' => (string) ($data['message'] ?? ''), 'checked_at' => is_string($data['checked_at'] ?? null) ? $data['checked_at'] : null];
    }
}
