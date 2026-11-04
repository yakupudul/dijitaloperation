<?php

namespace App\Services\Sales;

use App\Models\User;
use App\Models\WhatsAppBackupLine;
use App\Services\WhatsApp\Backup\WhatsAppBackupImporter;
use App\Support\Roles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * "Telefon bağlantısı": the phone puts its WhatsApp backup on a WebDAV address of MoxDOP by itself (FolderSync, every
 * morning), the file goes through the same upload pieces as a hand-picked one and, with the saved key, is extracted
 * at once. One address and one password per backup row; the password is shown once.
 */
final class PhoneBackupSync
{
    public const string USERNAME = 'moxdop';

    public function __construct(private WhatsAppBackupImporter $importer) {}

    /** A new password for the row (the old one stops working); returned once, kept encrypted. */
    public function issue(User $user, WhatsAppBackupLine $line): string
    {
        abort_unless($user->hasRole(Roles::ADMIN), 403);
        $plain = Str::lower(Str::random(28));
        $line->forceFill(['phone_sync_token' => Crypt::encryptString($plain)])->save();

        return $plain;
    }

    public function revoke(User $user, WhatsAppBackupLine $line): void
    {
        abort_unless($user->hasRole(Roles::ADMIN), 403);
        $line->forceFill(['phone_sync_token' => null])->save();
    }

    public function enabled(WhatsAppBackupLine $line): bool
    {
        return filled($line->phone_sync_token);
    }

    /** The row whose password the request carries (HTTP Basic), or null. */
    public function authenticate(Request $request): ?WhatsAppBackupLine
    {
        $password = (string) $request->getPassword();
        if ($password === '') {
            return null;
        }
        foreach (WhatsAppBackupLine::query()->whereNotNull('phone_sync_token')->get() as $line) {
            try {
                $plain = Crypt::decryptString((string) $line->phone_sync_token);
            } catch (Throwable) {
                continue;
            }
            if (hash_equals($plain, $password)) {
                return $line;
            }
        }

        return null;
    }

    /**
     * A file the phone put on the address: handed to the importer in pieces; with a saved key the extraction starts
     * by itself. A file identical to the last one received (same size) is taken but not extracted again.
     *
     * @return array{status: int, message: string}
     */
    public function receive(WhatsAppBackupLine $line, string $tempPath, string $originalName): array
    {
        clearstatcache(true, $tempPath);
        $size = (int) @filesize($tempPath);
        if ($size < 64) {
            return ['status' => 422, 'message' => 'Boş dosya.'];
        }
        $user = User::query()->where('is_active', true)->get()->first(fn (User $u): bool => $u->hasRole(Roles::ADMIN));
        if ($user === null) {
            return ['status' => 503, 'message' => 'Yönetici hesabı yok.'];
        }
        $last = $this->lastReceived($line);
        if ($last !== null && (int) ($last['size'] ?? 0) === $size && ($last['name'] ?? '') === $originalName) {
            $line->forceFill(['phone_sync_received_at' => now()])->save();

            return ['status' => 204, 'message' => 'Aynı dosya; yeniden çıkarılmadı.'];
        }
        try {
            $import = $this->importer->begin($user, 'msgstore.db.crypt15', $size, $line->id);
            $handle = fopen($tempPath, 'rb');
            if ($handle === false) {
                return ['status' => 500, 'message' => 'Geçici dosya okunamadı.'];
            }
            $offset = 0;
            try {
                while (! feof($handle)) {
                    $bytes = fread($handle, WhatsAppBackupImporter::CHUNK_BYTES);
                    if ($bytes === false || $bytes === '') {
                        break;
                    }
                    $import = $this->importer->chunk($user, $import->id, $offset, $bytes);
                    $offset += strlen($bytes);
                }
            } finally {
                fclose($handle);
            }
        } catch (ValidationException $exception) {
            return ['status' => 423, 'message' => (string) collect($exception->errors())->flatten()->first()];
        }
        $line->forceFill([
            'phone_sync_received_at' => now(),
            'phone_sync_last' => json_encode(['name' => mb_substr($originalName, 0, 120), 'size' => $size, 'at' => now()->toIso8601String(), 'import' => $import->id]),
        ])->save();
        $extracting = in_array($import->fresh()?->status, ['queued', 'running'], true);

        return ['status' => 201, 'message' => $extracting ? 'Yedek alındı, çıkarılıyor.' : 'Yedek alındı; anahtar kayıtlı olmadığı için WhatsApp ekranından Çıkar gerekiyor.'];
    }

    /** @return array{name?: string, size?: int, at?: string, import?: string}|null */
    public function lastReceived(WhatsAppBackupLine $line): ?array
    {
        $json = $line->phone_sync_last;

        return is_string($json) && $json !== '' ? (array) json_decode($json, true) : null;
    }

    public static function url(): string
    {
        return rtrim((string) config('app.url'), '/').'/api/telefon-yedek/';
    }
}
