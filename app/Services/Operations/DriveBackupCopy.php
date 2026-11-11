<?php

namespace App\Services\Operations;

use App\Models\AgencySetting;
use App\Services\Integrations\Google\GoogleWorkspace;
use App\Services\Operator\AgencySettingService;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Sunucu dışı yedek, Google Drive (yakup 2026-11-11: "Google Drive entegre edelim, oraya yedeklensin"): after the
 * nightly backup the same file is uploaded into the "MoxDOP yedekleri" folder of the connected Google account. The
 * permission covers only files this app created, so nothing else in the Drive can be read or changed. The newest
 * KEEP copies stay (a dump is over a gigabyte and a free Drive holds 15 GB); older ones are deleted after a new copy.
 */
final class DriveBackupCopy
{
    public const int KEEP = 3;

    public const string FOLDER = 'MoxDOP yedekleri';

    private const string API = 'https://www.googleapis.com/drive/v3/files';

    private const string UPLOAD = 'https://www.googleapis.com/upload/drive/v3/files';

    public function __construct(private readonly GoogleWorkspace $workspace) {}

    public function configured(): bool
    {
        try {
            return $this->workspace->has(GoogleWorkspace::DRIVE);
        } catch (Throwable) {
            return false;
        }
    }

    public function target(): ?string
    {
        return $this->configured() ? 'Google Drive › '.self::FOLDER : null;
    }

    /** Uploads one backup file; throws with a Turkish reason when Drive does not take it. */
    public function put(string $path): void
    {
        $folder = $this->folder();
        $bytes = (int) filesize($path);
        $start = $this->request()->withHeaders(['X-Upload-Content-Type' => 'application/gzip', 'X-Upload-Content-Length' => (string) $bytes])
            ->post(self::UPLOAD.'?uploadType=resumable&fields=id,size', ['name' => basename($path), 'parents' => [$folder]]);
        $session = (string) $start->header('Location');
        if (! $start->successful() || $session === '') {
            throw new RuntimeException($this->reason($start->status(), (string) $start->json('error.message')));
        }
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Yedek dosyası okunamadı.');
        }
        try {
            // The upload address carries its own permission; it outlives the one-hour access token.
            $response = Http::connectTimeout(15)->timeout(3600)->withHeaders(['Content-Length' => (string) $bytes])
                ->withBody(Utils::streamFor($stream), 'application/gzip')->put($session);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        if (! $response->successful()) {
            throw new RuntimeException($this->reason($response->status(), (string) $response->json('error.message')));
        }
        if ((int) $response->json('size') !== $bytes) {
            throw new RuntimeException('Drive\'daki kopyanın boyutu yedekle aynı değil.');
        }
    }

    /** Keeps the newest $keep backups in the folder. */
    public function prune(int $keep = self::KEEP): int
    {
        $files = $this->files();
        usort($files, fn (array $a, array $b): int => strcmp($b['name'], $a['name']));
        $deleted = 0;
        foreach (array_slice($files, max(1, $keep)) as $file) {
            if ($this->request()->delete(self::API.'/'.rawurlencode($file['id']))->successful()) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /** "Bağlantıyı dene": the folder is there (created if missing) and can be listed. */
    public function check(): string
    {
        try {
            return 'Drive bağlantısı çalışıyor; "'.self::FOLDER.'" klasöründe '.count($this->files()).' yedek var.';
        } catch (Throwable $exception) {
            return 'Drive\'a ulaşılamadı: '.mb_substr($exception->getMessage(), 0, 200);
        }
    }

    /** @return list<array{id: string, name: string}> */
    public function files(): array
    {
        $response = $this->request()->get(self::API, [
            'q' => "'".$this->folder()."' in parents and trashed = false", 'fields' => 'files(id,name)', 'pageSize' => 200,
        ]);
        if (! $response->successful()) {
            throw new RuntimeException($this->reason($response->status(), (string) $response->json('error.message')));
        }

        return array_values(array_filter(array_map(fn ($file): array => ['id' => (string) ($file['id'] ?? ''), 'name' => (string) ($file['name'] ?? '')],
            (array) $response->json('files', [])), fn (array $file): bool => $file['id'] !== '' && preg_match('/^moxdop-.+\.gz$/', $file['name']) === 1));
    }

    /** The backup folder's id: the kept one when it still stands, a new folder otherwise. */
    private function folder(): string
    {
        $setting = AgencySetting::query()->orderBy('id')->first();
        $kept = (string) data_get($setting?->google_workspace, 'drive_folder_id', '');
        if ($kept !== '') {
            $found = $this->request()->get(self::API.'/'.rawurlencode($kept), ['fields' => 'id,trashed']);
            if ($found->successful() && $found->json('trashed') !== true) {
                return $kept;
            }
            if (! in_array($found->status(), [200, 404], true)) {
                throw new RuntimeException($this->reason($found->status(), (string) $found->json('error.message')));
            }
        }
        $made = $this->request()->post(self::API.'?fields=id', ['name' => self::FOLDER, 'mimeType' => 'application/vnd.google-apps.folder']);
        $id = (string) $made->json('id');
        if (! $made->successful() || $id === '') {
            throw new RuntimeException($this->reason($made->status(), (string) $made->json('error.message')));
        }
        $setting?->forceFill(['google_workspace' => ['drive_folder_id' => $id] + (array) ($setting->google_workspace ?? [])])->save();
        rescue(fn () => app(AgencySettingService::class)->forget(), report: false);

        return $id;
    }

    private function reason(int $status, string $message): string
    {
        return match (true) {
            $status === 401 => 'Google izni geçersiz; "Google Takvim ve Drive\'ı bağla" ile yeniden bağla.',
            $status === 403 && str_contains($message, 'has not been used') => 'Google Cloud projesinde Drive API açık değil.',
            $status === 403 && str_contains(mb_strtolower($message), 'quota') => 'Drive\'da yer kalmadı.',
            default => 'Drive dosyayı almadı (HTTP '.$status.').'.($message !== '' ? ' '.mb_substr($message, 0, 160) : ''),
        };
    }

    private function request(): PendingRequest
    {
        return Http::withToken($this->workspace->token())->acceptJson()->connectTimeout(15)->timeout(60);
    }
}
