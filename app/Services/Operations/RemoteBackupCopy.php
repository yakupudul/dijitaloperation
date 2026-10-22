<?php

namespace App\Services\Operations;

use App\Models\AgencySetting;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Audit D5 (2026-10-22): a copy of every backup outside the server, in a WebDAV folder (a Hetzner Storage Box or any
 * WebDAV host) set in Ayarlar › Sistem sağlığı. Plain HTTPS: the file is streamed with PUT, the folder keeps the same
 * number of backups as the server (oldest deleted after a new copy). The password never leaves the settings row.
 */
final class RemoteBackupCopy
{
    private const int TIMEOUT = 3600;

    public function configured(): bool
    {
        $setting = $this->setting();

        return $setting !== null && filled($setting->backup_remote_url) && filled($setting->backup_remote_username) && filled($setting->backup_remote_password);
    }

    /** "https://u123.your-storagebox.de/moxdop-backups" without the password, for the page. */
    public function target(): ?string
    {
        return $this->configured() ? $this->folder() : null;
    }

    /** Copies one backup file; throws with a Turkish reason when the folder does not take it. */
    public function put(string $path): void
    {
        $this->ensureFolder();
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Yedek dosyası okunamadı.');
        }
        try {
            $response = $this->request()->withBody(Utils::streamFor($stream), 'application/gzip')->put($this->folder().'/'.rawurlencode(basename($path)));
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        if (! $response->successful()) {
            throw new RuntimeException('Uzak yedek klasörü dosyayı almadı (HTTP '.$response->status().').');
        }
        $size = (int) $this->request()->head($this->folder().'/'.rawurlencode(basename($path)))->header('Content-Length');
        if ($size > 0 && $size !== (int) filesize($path)) {
            throw new RuntimeException('Uzak kopyanın boyutu yedekle aynı değil.');
        }
    }

    /** Keeps the newest $keep backups in the remote folder. */
    public function prune(int $keep): int
    {
        $files = $this->files();
        rsort($files);
        $deleted = 0;
        foreach (array_slice($files, max(1, $keep)) as $file) {
            if ($this->request()->delete($this->folder().'/'.rawurlencode($file))->successful()) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /** "Bağlantıyı dene": the folder can be listed (created if missing). */
    public function check(): string
    {
        try {
            $this->ensureFolder();

            return 'Bağlantı çalışıyor; klasörde '.count($this->files()).' yedek var.';
        } catch (Throwable $exception) {
            return 'Bağlanılamadı: '.mb_substr($exception->getMessage(), 0, 200);
        }
    }

    /** @return list<string> backup file names in the remote folder */
    public function files(): array
    {
        $response = $this->request()->withHeaders(['Depth' => '1'])->send('PROPFIND', $this->folder().'/');
        if ($response->status() === 401) {
            throw new RuntimeException('Kullanıcı adı ya da şifre kabul edilmedi.');
        }
        if (! $response->successful()) {
            throw new RuntimeException('Klasör listelenemedi (HTTP '.$response->status().').');
        }
        preg_match_all('~<(?:[a-z0-9]+:)?href>([^<]+)</(?:[a-z0-9]+:)?href>~i', (string) $response->body(), $m);
        $names = array_map(fn (string $href): string => rawurldecode(basename(rtrim($href, '/'))), $m[1]);

        return array_values(array_unique(array_filter($names, fn (string $name): bool => preg_match('/^moxdop-.+\.gz$/', $name) === 1)));
    }

    private function ensureFolder(): void
    {
        $response = $this->request()->send('MKCOL', $this->folder().'/');
        // 201 created, 405 already there; 401 is the only answer worth naming.
        if ($response->status() === 401) {
            throw new RuntimeException('Kullanıcı adı ya da şifre kabul edilmedi.');
        }
        if (! in_array($response->status(), [200, 201, 204, 301, 405], true) && ! $response->successful()) {
            throw new RuntimeException('Uzak klasör açılamadı (HTTP '.$response->status().').');
        }
    }

    private function folder(): string
    {
        return rtrim((string) $this->setting()?->backup_remote_url, '/');
    }

    private function request(): PendingRequest
    {
        $setting = $this->setting();
        if ($setting === null || ! $this->configured()) {
            throw new RuntimeException('Uzak yedek ayarlı değil.');
        }

        return Http::withBasicAuth((string) $setting->backup_remote_username, (string) $setting->backup_remote_password)
            ->connectTimeout(15)->timeout(self::TIMEOUT)->withOptions(['allow_redirects' => false]);
    }

    private function setting(): ?AgencySetting
    {
        return AgencySetting::query()->orderBy('id')->first();
    }
}
