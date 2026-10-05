<?php

namespace App\Services\WhatsApp\Backup;

use App\Jobs\WhatsApp\ExtractWhatsAppBackup;
use App\Models\CoreIntegration;
use App\Models\User;
use App\Models\WhatsAppBackupImport;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\WhatsAppBrain;
use App\Services\WhatsApp\WhatsAppConnection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use PDOException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * The phone's WhatsApp Business backup as a second way into the inbox (while the Meta connection waits): the file is
 * uploaded in pieces, "Çıkar" queues the extraction with the 64-digit key, and the chats are stored as conversations
 * of the "backup" line. Uploading a newer backup later adds only the messages that are not there yet; the key that
 * worked is kept (encrypted), so a later upload is extracted without asking for it again.
 */
final class WhatsAppBackupImporter
{
    /** phone_number_id of conversations that came from a backup (live Meta conversations carry the real id). */
    public const LINE = 'backup';

    /** Bytes per upload piece: below PHP's default 8 MB post limit and the server's 32 MB request limit. */
    public const CHUNK_BYTES = 4 * 1024 * 1024;

    public const MAX_BYTES = 2 * 1024 * 1024 * 1024;

    private const BATCH = 500;

    public function __construct(private WhatsAppConnection $connection, private WhatsAppBackupReader $reader) {}

    public function begin(User $user, string $fileName, int $size): WhatsAppBackupImport
    {
        $this->connection->authorize($user);
        $name = trim($fileName);
        if (preg_match('/\.crypt1[0-4]$/i', $name) === 1) {
            throw ValidationException::withMessages(['backup' => 'Bu yedek eski biçimde ('.strtolower((string) strrchr($name, '.')).'); açmak için 64 haneli anahtar değil telefondaki anahtar dosyası gerekir. Telefonda WhatsApp Business › Ayarlar › Sohbetler › Sohbet yedeği › Uçtan uca şifreli yedek bölümünden 64 haneli anahtarla yeni bir yedek alın ve msgstore.db.crypt15 dosyasını yükleyin.']);
        }
        if (preg_match('/\.crypt15$/i', $name) !== 1) {
            throw ValidationException::withMessages(['backup' => 'msgstore.db.crypt15 dosyasını seçin (WhatsApp Business › Databases klasöründe).']);
        }
        if ($size < 64 || $size > self::MAX_BYTES) {
            throw ValidationException::withMessages(['backup' => 'Dosya boyutu geçersiz (en çok 2 GB).']);
        }

        return DB::transaction(function () use ($user, $name, $size): WhatsAppBackupImport {
            if (WhatsAppBackupImport::query()->whereIn('status', ['queued', 'running'])->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['backup' => 'Önceki yedek şu an çıkarılıyor; bitince yeni dosya yükleyebilirsiniz.']);
            }
            // One file at a time: an unfinished or unused earlier upload is dropped.
            foreach (WhatsAppBackupImport::query()->whereIn('status', ['uploading', 'uploaded'])->get() as $old) {
                $this->discard($old);
            }
            File::ensureDirectoryExists(dirname((new WhatsAppBackupImport)->forceFill(['id' => 'x'])->path()), 0700);
            $import = WhatsAppBackupImport::query()->create([
                'user_id' => $user->id, 'status' => 'uploading', 'file_name' => mb_substr($name, 0, 255), 'size' => $size,
            ]);
            File::put($import->path(), '');

            return $import;
        });
    }

    /** Appends one piece at its offset; a piece for another offset is refused with the offset expected (resume). */
    public function chunk(User $user, string $id, int $offset, string $bytes): WhatsAppBackupImport
    {
        $this->connection->authorize($user);

        $import = DB::transaction(function () use ($id, $offset, $bytes): WhatsAppBackupImport {
            $import = WhatsAppBackupImport::query()->lockForUpdate()->findOrFail($id);
            if ($import->status !== 'uploading') {
                throw new HttpException(409, 'Bu yükleme artık açık değil. Dosyayı yeniden seçin.');
            }
            if ($offset !== $import->received_bytes) {
                throw new HttpException(409, 'expected_offset:'.$import->received_bytes);
            }
            if ($bytes === '' || strlen($bytes) > self::CHUNK_BYTES || $import->received_bytes + strlen($bytes) > $import->size) {
                throw new HttpException(422, 'Dosya parçası geçersiz. Dosyayı yeniden seçin.');
            }
            if (file_put_contents($import->path(), $bytes, FILE_APPEND | LOCK_EX) !== strlen($bytes)) {
                throw new HttpException(507, 'Sunucuda dosya için yer kalmadı.');
            }
            $received = $import->received_bytes + strlen($bytes);
            $import->update(['received_bytes' => $received, 'status' => $received === $import->size ? 'uploaded' : 'uploading']);

            return $import;
        });
        // With a saved key the operator only uploads: extraction starts as soon as the last piece arrives.
        if ($import->status === 'uploaded' && $this->savedKey() !== null) {
            $this->extract($user);
        }

        return $import->fresh() ?? $import;
    }

    /**
     * "Çıkar": the uploaded file is opened in the background. An empty key uses the key saved by the last successful
     * extraction, so later backups need only the file.
     */
    public function extract(User $user, ?string $key = null): WhatsAppBackupImport
    {
        $this->connection->authorize($user);
        $hex = trim((string) $key) === '' ? $this->savedKey() : WhatsAppBackupReader::normaliseKey((string) $key);
        if ($hex === null) {
            throw ValidationException::withMessages(['backup_key' => trim((string) $key) === ''
                ? 'Bu ilk yedek: 64 haneli anahtarı girin. Doğru anahtar kaydedilir, sonraki yedeklerde sorulmaz.'
                : 'Anahtar 64 karakter olmalı (0-9 ve a-f). Boşluklar sorun değil.']);
        }
        $import = DB::transaction(function () use ($hex): WhatsAppBackupImport {
            $import = WhatsAppBackupImport::query()->where('status', 'uploaded')->latest()->lockForUpdate()->first();
            if ($import === null || ! is_file($import->path())) {
                throw ValidationException::withMessages(['backup' => 'Önce yedek dosyasını yükleyin.']);
            }
            $import->update(['status' => 'queued', 'backup_key' => $hex, 'error' => null]);

            return $import;
        });
        try {
            ExtractWhatsAppBackup::dispatch($import->id, $user->id);
        } catch (Throwable $exception) {
            report($exception);
            $import->update(['status' => 'uploaded', 'backup_key' => null, 'error' => 'Çıkarma işi sıraya alınamadı; kuyruk hizmetini kontrol edip tekrar deneyin.']);
        }

        return $import;
    }

    /** The key of the last successful extraction (stored encrypted on the integration), or null. */
    public function savedKey(): ?string
    {
        $stored = data_get($this->connection->integration()?->config, 'backup_key');
        if (! is_string($stored) || $stored === '') {
            return null;
        }
        try {
            return WhatsAppBackupReader::normaliseKey(Crypt::decryptString($stored));
        } catch (Throwable) {
            return null;
        }
    }

    /** "Kayıtlı anahtarı sil". */
    public function forgetKey(User $user): void
    {
        $this->connection->authorize($user);
        $this->updateConfig(function (array $config): array {
            unset($config['backup_key']);

            return $config;
        });
    }

    /** The background extraction. A wrong key keeps the file so another key can be tried without uploading again. */
    public function run(string $id): void
    {
        $import = WhatsAppBackupImport::query()->find($id);
        if ($import === null || $import->status !== 'queued' || ! filled($import->backup_key)) {
            return;
        }
        $import->update(['status' => 'running', 'started_at' => now(), 'stats' => ['phase' => 'decrypt']]);
        $database = $import->path().'.db';
        try {
            if ((int) ini_get('memory_limit') > 0 && $this->bytes((string) ini_get('memory_limit')) < 3 * $import->size + 256 * 1024 * 1024) {
                ini_set('memory_limit', (string) min(4096, (int) ceil((3 * $import->size) / 1048576) + 512).'M');
            }
            $key = (string) $import->backup_key;
            $this->reader->decrypt($import->path(), $key, $database);
            $import->update(['backup_key' => null, 'stats' => ['phase' => 'import']]);
            $this->updateConfig(fn (array $config): array => [...$config, 'backup_key' => Crypt::encryptString($key)]);
            if (! WhatsAppBackupReader::canReadDatabase()) {
                // The key worked and is saved: the file stays so "Çıkar" can run again once the server can read SQLite.
                $import->update(['status' => 'uploaded', 'stats' => null, 'error' => WhatsAppBackupReader::SQLITE_MISSING]);

                return;
            }
            $stats = $this->import($import, $database);
            $import->update(['status' => 'completed', 'stats' => $stats, 'finished_at' => now(), 'error' => null]);
            $this->discardFiles($import);
            app(WhatsAppBrain::class)->learnAfterFirstImport($import->user_id);
        } catch (WhatsAppBackupKeyException $exception) {
            $import->update(['status' => 'uploaded', 'backup_key' => null, 'stats' => null, 'error' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            report($exception);
            $import->update(['status' => 'failed', 'backup_key' => null, 'finished_at' => now(),
                'error' => $exception instanceof WhatsAppBackupFormatException ? $exception->getMessage() : 'Yedek okunamadı ('.class_basename($exception).($exception instanceof PDOException ? ': '.mb_substr($exception->getMessage(), 0, 300) : '').'). Dosyayı yeniden yükleyip deneyin; sürerse bu mesajı iletin.']);
            $this->discardFiles($import);
        } finally {
            File::delete($database);
        }
    }

    /**
     * @return array{chats: int, new_chats: int, messages: int, new_messages: int, skipped_groups: int, snapshot_at: ?string}
     */
    public function import(WhatsAppBackupImport $import, string $database): array
    {
        $integration = CoreIntegration::query()->firstOrCreate(['provider' => WhatsAppConnection::PROVIDER], [
            'name' => 'WhatsApp Business', 'status' => CoreIntegration::STATUS_ACTIVE, 'config' => [],
        ]);
        $stats = ['chats' => 0, 'new_chats' => 0, 'messages' => 0, 'new_messages' => 0, 'skipped_groups' => 0, 'snapshot_at' => null];
        $latest = 0;
        $skipped = null;
        foreach ($this->reader->chats($database, $skipped) as $chat) {
            [$created, $added] = $this->store($integration, $chat['contact'], $chat['messages']);
            $stats['chats']++;
            $stats['new_chats'] += $created ? 1 : 0;
            $stats['messages'] += count($chat['messages']);
            $stats['new_messages'] += $added;
            $latest = max($latest, ...array_column($chat['messages'], 'at'));
            if ($stats['chats'] % 25 === 0) {
                $import->update(['stats' => [...$stats, 'phase' => 'import']]);
            }
        }
        $stats['skipped_groups'] = (int) ($skipped['groups'] ?? 0);
        $stats['snapshot_at'] = $latest > 0 ? CarbonImmutable::createFromTimestampMsUTC($latest)->toIso8601String() : null;
        DB::transaction(function () use ($integration, $stats): void {
            $row = CoreIntegration::query()->lockForUpdate()->findOrFail($integration->id);
            $config = $row->config ?? [];
            $config['backup_imported_at'] = now()->toIso8601String();
            if ($stats['snapshot_at'] !== null && ($config['backup_snapshot_at'] ?? '') < $stats['snapshot_at']) {
                $config['backup_snapshot_at'] = $stats['snapshot_at'];
            }
            $row->update(['config' => $config]);
        });

        return $stats;
    }

    /**
     * @param  list<array{id: string, outgoing: bool, at: int, type: string, body: string}>  $messages
     * @return array{0: bool, 1: int} [conversation created, messages added]
     */
    private function store(CoreIntegration $integration, string $contact, array $messages): array
    {
        return DB::transaction(function () use ($integration, $contact, $messages): array {
            // Imported chats are drafted only on the operator's "Mesaj üret" click, never automatically.
            $conversation = WhatsAppConversation::query()->firstOrCreate([
                'integration_id' => $integration->id, 'phone_number_id' => self::LINE, 'contact_id' => $contact,
            ], ['suggestion_status' => 'idle']);
            $created = $conversation->wasRecentlyCreated;
            $conversation = WhatsAppConversation::query()->lockForUpdate()->findOrFail($conversation->id);
            $known = $conversation->messages()->pluck('message_id')->flip();
            $rows = [];
            $lastAt = $conversation->last_message_at;
            $lastIncoming = $conversation->last_incoming_at;
            $now = now();
            foreach ($messages as $message) {
                if (isset($known[$message['id']])) {
                    continue;
                }
                $known[$message['id']] = true;
                $sentAt = CarbonImmutable::createFromTimestampMsUTC($message['at']);
                $lastAt = $lastAt?->greaterThan($sentAt) ? $lastAt : $sentAt;
                if (! $message['outgoing']) {
                    $lastIncoming = $lastIncoming?->greaterThan($sentAt) ? $lastIncoming : $sentAt;
                }
                $rows[] = [
                    'conversation_id' => $conversation->id, 'message_id' => $message['id'],
                    'direction' => $message['outgoing'] ? 'outgoing' : 'incoming', 'message_type' => $message['type'],
                    'body' => Crypt::encryptString($message['body']), 'is_history' => true,
                    'sent_at' => $sentAt, 'created_at' => $now, 'updated_at' => $now,
                ];
            }
            foreach (array_chunk($rows, self::BATCH) as $batch) {
                WhatsAppMessage::query()->insert($batch);
            }
            if ($rows !== []) {
                $conversation->update([
                    'last_message_at' => $lastAt, 'last_incoming_at' => $lastIncoming, 'revision' => $conversation->revision + 1,
                    // Marked done, then the customer wrote again: back to the open list.
                    'done_at' => $conversation->done_at && $lastIncoming?->greaterThan($conversation->done_at) ? null : $conversation->done_at,
                    'suggestion_status' => in_array($conversation->suggestion_status, ['requested', 'running'], true) ? $conversation->suggestion_status : 'idle',
                ]);
            }

            return [$created, count($rows)];
        });
    }

    /** Removes an upload that will not be extracted. */
    public function discard(WhatsAppBackupImport $import): void
    {
        $this->discardFiles($import);
        $import->delete();
    }

    public function discardFiles(WhatsAppBackupImport $import): void
    {
        File::delete([$import->path(), $import->path().'.db']);
    }

    /** @param  callable(array<string, mixed>): array<string, mixed>  $change */
    private function updateConfig(callable $change): void
    {
        DB::transaction(function () use ($change): void {
            $row = CoreIntegration::query()->firstOrCreate(['provider' => WhatsAppConnection::PROVIDER], [
                'name' => 'WhatsApp Business', 'status' => CoreIntegration::STATUS_ACTIVE, 'config' => [],
            ]);
            $row = CoreIntegration::query()->lockForUpdate()->findOrFail($row->id);
            $row->update(['config' => $change($row->config ?? [])]);
        });
    }

    private function bytes(string $limit): int
    {
        $value = (int) $limit;

        return match (strtolower(substr(trim($limit), -1))) {
            'g' => $value * 1073741824,
            'm' => $value * 1048576,
            'k' => $value * 1024,
            default => $value,
        };
    }
}
