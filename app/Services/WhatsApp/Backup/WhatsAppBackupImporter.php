<?php

namespace App\Services\WhatsApp\Backup;

use App\Jobs\WhatsApp\ExtractWhatsAppBackup;
use App\Models\CoreIntegration;
use App\Models\User;
use App\Models\WhatsAppBackupImport;
use App\Models\WhatsAppBackupLine;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\WhatsAppBrain;
use App\Services\WhatsApp\WhatsAppConnection;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use PDOException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * The phone's WhatsApp Business backup as a second way into the inbox (while the Meta connection waits): the file is
 * uploaded in pieces, "Çıkar" queues the extraction with the 64-digit key, and the chats are stored as conversations
 * of the "backup" line. Uploading a newer backup later adds only the messages that are not there yet; the key that
 * worked is kept (encrypted), so a later upload is extracted without asking for it again. Extra backups (other phones or
 * numbers, WhatsAppBackupLine) go through the same steps with their own key and land on the archive integration; the
 * last extracted file of each is kept so the operator can see and download it.
 */
final class WhatsAppBackupImporter
{
    /** phone_number_id of conversations that came from a backup (live Meta conversations carry the real id). */
    public const LINE = 'backup';

    /** Bytes per upload piece: below PHP's default 8 MB post limit and the server's 32 MB request limit. */
    public const CHUNK_BYTES = 4 * 1024 * 1024;

    public const MAX_BYTES = 2 * 1024 * 1024 * 1024;

    private const BATCH = 500;

    /** A queued extraction that has not started after this long is queued again (the job was lost). */
    public const STALL_QUEUED_MINUTES = 20;

    /** A running extraction without progress for this long was cut off: the job's own limit is 14.5 minutes. */
    public const STALL_RUNNING_MINUTES = 16;

    /** The operator's "Yeniden başlat" appears after this long without progress. */
    public const RESTART_AFTER_MINUTES = 5;

    /** Attempts cut off mid-way before the import fails for good. */
    public const MAX_ATTEMPTS = 3;

    public function __construct(private WhatsAppConnection $connection, private WhatsAppBackupReader $reader) {}

    public function begin(User $user, string $fileName, int $size, ?int $lineId = null, bool $excel = false): WhatsAppBackupImport
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

        $this->recoverStalled();

        $lineId = $excel ? null : $this->line($lineId, true)?->id;

        return DB::transaction(function () use ($user, $name, $size, $lineId, $excel): WhatsAppBackupImport {
            if (WhatsAppBackupImport::query()->whereIn('status', ['queued', 'running'])->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['backup' => 'Önceki yedek şu an çıkarılıyor; bitince yeni dosya yükleyebilirsiniz.']);
            }
            // One file at a time per backup: an unfinished or unused earlier upload is dropped.
            foreach (self::forLine(WhatsAppBackupImport::query(), $lineId, $excel)->whereIn('status', ['uploading', 'uploaded'])->get() as $old) {
                $this->discard($old);
            }
            File::ensureDirectoryExists(dirname((new WhatsAppBackupImport)->forceFill(['id' => 'x'])->path()), 0700);
            $import = WhatsAppBackupImport::query()->create([
                'user_id' => $user->id, 'line_id' => $lineId, 'mode' => $excel ? 'excel' : 'inbox', 'status' => 'uploading', 'file_name' => mb_substr($name, 0, 255), 'size' => $size,
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
        if ($import->status === 'uploaded' && ! $import->excelOnly() && $this->savedKey($import->line_id) !== null) {
            $this->extract($user, null, $import->line_id);
        }

        return $import->fresh() ?? $import;
    }

    /**
     * "Çıkar": the uploaded file is opened in the background. An empty key uses the key saved by the last successful
     * extraction, so later backups need only the file.
     */
    public function extract(User $user, ?string $key = null, ?int $lineId = null, bool $excel = false): WhatsAppBackupImport
    {
        $this->connection->authorize($user);
        // An Excel-only upload is opened with the key typed for it: each business's backup has its own.
        $hex = trim((string) $key) === '' ? ($excel ? null : $this->savedKey($lineId)) : WhatsAppBackupReader::normaliseKey((string) $key);
        if ($hex === null) {
            throw ValidationException::withMessages(['backup_key' => trim((string) $key) === '' && $excel ? 'Bu yedeğin 64 haneli anahtarını girin.' : (trim((string) $key) === ''
                ? 'Bu ilk yedek: 64 haneli anahtarı girin. Doğru anahtar kaydedilir, sonraki yedeklerde sorulmaz.'
                : 'Anahtar 64 karakter olmalı (0-9 ve a-f). Boşluklar sorun değil.')]);
        }
        $lineId = $excel ? null : $this->line($lineId)?->id;
        $import = DB::transaction(function () use ($hex, $lineId, $excel): WhatsAppBackupImport {
            $import = self::forLine(WhatsAppBackupImport::query(), $lineId, $excel)->where('status', 'uploaded')->latest()->lockForUpdate()->first();
            if ($import === null || ! is_file($import->path())) {
                throw ValidationException::withMessages(['backup' => 'Önce yedek dosyasını yükleyin.']);
            }
            $import->update(['status' => 'queued', 'backup_key' => $hex, 'error' => null, 'stats' => null,
                'attempt' => $import->attempt + 1, 'queued_at' => now(), 'started_at' => null]);

            return $import;
        });
        $this->dispatch($import, $user->id);

        return $import;
    }

    /**
     * Finds extractions that stopped moving and starts them again: a job that never started (lost from the queue) is
     * queued once more, one that stopped mid-way (worker killed, server restarted: its failure hook never ran) gets a
     * new attempt from the start, which is safe because messages already stored are skipped. After MAX_ATTEMPTS
     * stopped attempts, or with the file gone, the import fails with the reason. Run by the scheduler and the page.
     */
    public function recoverStalled(): void
    {
        foreach (WhatsAppBackupImport::query()->whereIn('status', ['queued', 'running'])->get() as $import) {
            $last = $import->lastActivityAt();
            if ($import->status === 'queued' && $last !== null && $last->greaterThan(now()->subMinutes(self::STALL_QUEUED_MINUTES))) {
                continue;
            }
            if ($import->status === 'running' && $last !== null && $last->greaterThan(now()->subMinutes(self::STALL_RUNNING_MINUTES))) {
                continue;
            }
            $this->revive($import, $import->status === 'running');
        }
    }

    /** "Yeniden başlat": the operator starts an extraction again once it has shown no progress for a few minutes. */
    public function restart(User $user): void
    {
        $this->connection->authorize($user);
        $import = WhatsAppBackupImport::query()->whereIn('status', ['queued', 'running'])->latest()->first();
        if ($import === null) {
            return;
        }
        if (! self::canRestart($import)) {
            throw ValidationException::withMessages(['backup' => 'Çıkarma şu an ilerliyor; birkaç dakika ilerleme olmazsa yeniden başlatabilirsiniz.']);
        }
        $this->revive($import, true, $user->id, 'Yeniden başlattınız.');
    }

    /** Whether the operator may press "Yeniden başlat": the attempt has shown no progress for RESTART_AFTER_MINUTES. */
    public static function canRestart(WhatsAppBackupImport $import): bool
    {
        $last = $import->lastActivityAt();

        return $import->isBusy() && ($last === null || $last->lessThanOrEqualTo(now()->subMinutes(self::RESTART_AFTER_MINUTES)));
    }

    /** The job reported it was cut off: a new attempt (or the failure with its reason). */
    public function recoverInterrupted(WhatsAppBackupImport $import): void
    {
        $this->revive($import, true);
    }

    private function revive(WhatsAppBackupImport $import, bool $newAttempt, ?int $userId = null, ?string $note = null): void
    {
        $key = filled($import->backup_key) ? (string) $import->backup_key : ($import->excelOnly() ? null : $this->savedKey($import->line_id));
        $reason = $import->status === 'running' || $newAttempt ? 'yarıda kesildi' : 'kuyrukta başlamadı';
        $failure = match (true) {
            ! is_file($import->path()) => 'Çıkarma '.$reason.' ve yedek dosyası sunucuda artık yok. Dosyayı yeniden yükleyin.',
            $key === null => 'Çıkarma '.$reason.'. Anahtar kayıtlı değil: dosyayı yeniden yükleyip 64 haneli anahtarla Çıkar\'a basın.',
            $newAttempt && $import->attempt >= self::MAX_ATTEMPTS => 'Çıkarma '.$import->attempt.' denemede de bitmedi (iş her seferinde yarıda kesildi; sunucunun belleği ya da süresi yetmiyor olabilir). Dosyayı yeniden yükleyip deneyin; sürerse bu mesajı iletin.',
            default => null,
        };
        if ($failure !== null) {
            $import->update(['status' => 'failed', 'backup_key' => null, 'finished_at' => now(), 'error' => $failure]);
            $this->discardFiles($import);

            return;
        }
        $import->update([
            'status' => 'queued', 'backup_key' => $key, 'queued_at' => now(), 'started_at' => null, 'stats' => null,
            'attempt' => $newAttempt ? $import->attempt + 1 : $import->attempt,
            'error' => $note ?? 'Önceki deneme '.$reason.'; çıkarma kendiliğinden yeniden başlatıldı.',
        ]);
        File::delete($import->path().'.db');
        $this->dispatch($import, $userId ?? $import->user_id);
    }

    private function dispatch(WhatsAppBackupImport $import, ?int $userId): void
    {
        try {
            ExtractWhatsAppBackup::dispatch($import->id, $userId, $import->attempt);
        } catch (Throwable $exception) {
            report($exception);
            $import->update(['status' => 'uploaded', 'backup_key' => null, 'error' => 'Çıkarma işi sıraya alınamadı; kuyruk hizmetini kontrol edip tekrar deneyin.']);
        }
    }

    /** The key of a row's last successful extraction (none given: the row shown in the inbox), or null. */
    public function savedKey(?int $lineId = null): ?string
    {
        try {
            $plain = ($lineId !== null ? WhatsAppBackupLine::query()->find($lineId) : WhatsAppBackupLine::shown())?->backup_key;

            return is_string($plain) && $plain !== '' ? WhatsAppBackupReader::normaliseKey($plain) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** "Kayıtlı anahtarı sil". */
    public function forgetKey(User $user, ?int $lineId = null): void
    {
        $this->connection->authorize($user);
        $this->line($lineId)?->update(['backup_key' => null]);
    }

    /**
     * The row an action is for: the one given, else the one shown in the inbox. An upload with neither starts a
     * shown row of its own, as the single backup did before rows existed.
     */
    private function line(?int $lineId, bool $create = false): ?WhatsAppBackupLine
    {
        if ($lineId !== null) {
            return WhatsAppBackupLine::query()->find($lineId)
                ?? throw ValidationException::withMessages(['backup' => 'Bu yedek artık yok; sayfayı yenileyin.']);
        }
        $shown = WhatsAppBackupLine::shown();
        if ($shown !== null || ! $create) {
            return $shown;
        }
        $line = WhatsAppBackupLine::query()->create(['name' => $this->freeName('WhatsApp yedeği')]);
        $this->show($line);

        return $line->fresh();
    }

    private function freeName(string $base): string
    {
        for ($n = 1; ; $n++) {
            $name = $n === 1 ? $base : $base.' '.$n;
            if (! WhatsAppBackupLine::query()->where('name', $name)->exists()) {
                return $name;
            }
        }
    }

    /**
     * "Görüşmelerde göster": this row's chats move into the inbox and the row shown so far goes back beside it (null:
     * no backup in the inbox). Nothing is deleted; the brain learns again from the chats now shown.
     */
    public function activate(User $user, ?int $lineId): void
    {
        $this->connection->authorize($user);
        if (WhatsAppBackupImport::query()->whereIn('status', ['queued', 'running'])->exists()) {
            throw ValidationException::withMessages(['backup' => 'Bir yedek şu an çıkarılıyor; bitince değiştirebilirsiniz.']);
        }
        $line = $lineId === null ? null : WhatsAppBackupLine::query()->findOrFail($lineId);
        if ($line?->active || ($line === null && WhatsAppBackupLine::shown() === null)) {
            return;
        }
        $this->show($line);
        if ($line !== null) {
            app(WhatsAppBrain::class)->learnAfterFirstImport($user->id);
        }
    }

    private function show(?WhatsAppBackupLine $line): void
    {
        $inbox = CoreIntegration::query()->firstOrCreate(['provider' => WhatsAppConnection::PROVIDER], [
            'name' => 'WhatsApp Business', 'status' => CoreIntegration::STATUS_ACTIVE, 'config' => [],
        ]);
        $archive = WhatsAppBackupLine::archive();
        DB::transaction(function () use ($line, $inbox, $archive): void {
            foreach (WhatsAppBackupLine::query()->where('active', true)->lockForUpdate()->get() as $old) {
                WhatsAppConversation::query()->where('integration_id', $inbox->id)->where('phone_number_id', self::LINE)
                    ->update(['integration_id' => $archive->id, 'phone_number_id' => $old->conversationLine(), 'updated_at' => now()]);
                $old->update(['active' => false]);
            }
            if ($line !== null) {
                WhatsAppConversation::query()->where('integration_id', $archive->id)->where('phone_number_id', $line->conversationLine())
                    ->update(['integration_id' => $inbox->id, 'phone_number_id' => self::LINE, 'updated_at' => now()]);
                $line->update(['active' => true]);
            }
            $snapshot = $line === null ? null : $line->imports()->where('status', 'completed')->get()->pluck('stats.snapshot_at')->filter()->max();
            $row = CoreIntegration::query()->lockForUpdate()->findOrFail($inbox->id);
            $config = $row->config ?? [];
            // What the brain learned belongs to the chats shown before.
            foreach (['brain', 'brain_status', 'brain_error', 'backup_snapshot_at', 'backup_key'] as $key) {
                unset($config[$key]);
            }
            $row->update(['config' => $snapshot !== null ? [...$config, 'backup_snapshot_at' => $snapshot] : $config]);
        });
    }

    /** "Yedek ekle": a new, empty row the operator then uploads a file and its key to. */
    public function createLine(User $user, string $name): WhatsAppBackupLine
    {
        $this->connection->authorize($user);
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            throw ValidationException::withMessages(['line_name' => 'Yedeğe 2-120 karakterlik bir ad verin (örneğin numara ya da işletme adı).']);
        }
        if (WhatsAppBackupLine::query()->where('name', $name)->exists()) {
            throw ValidationException::withMessages(['line_name' => 'Bu adla bir yedek zaten var.']);
        }

        return WhatsAppBackupLine::query()->create(['name' => $name]);
    }

    /** "Sil": a row with its chats, files and key; a row shown in the inbox leaves the inbox without backup chats. */
    public function deleteLine(User $user, int $lineId): void
    {
        $this->connection->authorize($user);
        $line = WhatsAppBackupLine::query()->findOrFail($lineId);
        if ($line->imports()->whereIn('status', ['queued', 'running'])->exists()) {
            throw ValidationException::withMessages(['backup' => 'Bu yedek şu an çıkarılıyor; bitince silebilirsiniz.']);
        }
        if ($line->active) {
            $this->show(null);
            $line->refresh();
        }
        DB::transaction(function () use ($line): void {
            WhatsAppMessage::query()->whereIn('conversation_id', $line->conversations()->select('id'))->delete();
            $line->conversations()->delete();
            foreach ($line->imports()->get() as $import) {
                $this->discard($import);
            }
            File::delete(WhatsAppBackupImport::keptPath($line->id));
            $line->delete();
        });
    }

    /**
     * The backup file and its extraction as the screen shows them: the last extracted import of the line (null: the
     * inbox's own) and whether its file is still on the server to download.
     *
     * @return array{import: ?WhatsAppBackupImport, file_kept: bool}
     */
    public function current(?int $lineId): array
    {
        $lineId ??= WhatsAppBackupLine::shown()?->id;
        $import = self::forLine(WhatsAppBackupImport::query(), $lineId)->where('status', 'completed')->latest('finished_at')->latest()->first();

        return ['import' => $import, 'file_kept' => $import !== null && is_file(WhatsAppBackupImport::keptPath($lineId))];
    }

    /**
     * @param  Builder<WhatsAppBackupImport>  $query
     * @return Builder<WhatsAppBackupImport>
     */
    public static function forLine(Builder $query, ?int $lineId, bool $excel = false): Builder
    {
        return $lineId === null ? $query->whereNull('line_id')->where('mode', $excel ? 'excel' : 'inbox') : $query->where('line_id', $lineId);
    }

    /** "Sil" on a backup's Excel: the file goes; an Excel-only upload goes with it. */
    public function deleteExcel(User $user, string $id): void
    {
        $this->connection->authorize($user);
        $import = WhatsAppBackupImport::query()->findOrFail($id);
        File::delete($import->excelPath());
        if ($import->excelOnly()) {
            $this->discard($import);
        }
    }

    /** The background extraction. A wrong key keeps the file so another key can be tried without uploading again. */
    public function run(string $id, ?int $attempt = null): void
    {
        // Claimed in one statement: a duplicate or a late job of an earlier attempt finds nothing to do.
        $claimed = WhatsAppBackupImport::query()->whereKey($id)->where('status', 'queued')->whereNotNull('backup_key')
            ->when($attempt !== null, fn ($query) => $query->where('attempt', $attempt))
            ->update(['status' => 'running', 'started_at' => now(), 'updated_at' => now(), 'stats' => json_encode(['phase' => 'decrypt'])]);
        $import = $claimed === 1 ? WhatsAppBackupImport::query()->find($id) : null;
        if ($import === null) {
            return;
        }
        $database = $import->path().'.db';
        try {
            if ((int) ini_get('memory_limit') > 0 && $this->bytes((string) ini_get('memory_limit')) < 3 * $import->size + 256 * 1024 * 1024) {
                ini_set('memory_limit', (string) min(4096, (int) ceil((3 * $import->size) / 1048576) + 512).'M');
            }
            $key = (string) $import->backup_key;
            $this->reader->decrypt($import->path(), $key, $database);
            $import->update(['backup_key' => null, 'stats' => ['phase' => 'import']]);
            if (! $import->excelOnly()) {
                // An Excel-only upload keeps no key: the next one may be another business's backup.
                ($import->line_id !== null ? WhatsAppBackupLine::query()->find($import->line_id) : WhatsAppBackupLine::shown())?->update(['backup_key' => $key]);
            }
            if (! WhatsAppBackupReader::canReadDatabase()) {
                // The key worked and is saved: the file stays so "Çıkar" can run again once the server can read SQLite.
                $import->update(['status' => 'uploaded', 'stats' => null, 'error' => WhatsAppBackupReader::SQLITE_MISSING]);

                return;
            }
            $sheet = new WhatsAppBackupSpreadsheet;
            try {
                $stats = $this->import($import, $database, $sheet);
                $sheet->save($import);
            } catch (Throwable $exception) {
                $sheet->discard();
                throw $exception;
            }
            $import->update(['status' => 'completed', 'stats' => $stats, 'finished_at' => now(), 'error' => null, 'backup_key' => null]);
            if (! $import->excelOnly()) {
                // The extracted file replaces the line's previously kept one, so the screen can show and hand it back.
                $kept = WhatsAppBackupImport::keptPath($import->line_id);
                File::ensureDirectoryExists(dirname($kept), 0700);
                File::move($import->path(), $kept);
            }
            $this->discardFiles($import);
            if (! $import->excelOnly() && ($import->line_id === null || WhatsAppBackupLine::query()->whereKey($import->line_id)->value('active'))) {
                app(WhatsAppBrain::class)->learnAfterFirstImport($import->user_id);
            }
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
    public function import(WhatsAppBackupImport $import, string $database, ?WhatsAppBackupSpreadsheet $sheet = null): array
    {
        if ($import->excelOnly()) {
            return $this->sheetOnly($import, $database, $sheet);
        }
        $line = $import->line_id === null ? WhatsAppBackupLine::shown() : WhatsAppBackupLine::query()->find($import->line_id);
        if ($line === null) {
            throw new RuntimeException('Yedek silinmiş.');
        }
        // Only the row shown in the inbox lands there; the others stay apart from the inbox, the brain and the drafts.
        $integration = ! $line->active ? WhatsAppBackupLine::archive() : CoreIntegration::query()->firstOrCreate(['provider' => WhatsAppConnection::PROVIDER], [
            'name' => 'WhatsApp Business', 'status' => CoreIntegration::STATUS_ACTIVE, 'config' => [],
        ]);
        $phoneLine = $line->active ? self::LINE : $line->conversationLine();
        $stats = ['chats' => 0, 'new_chats' => 0, 'messages' => 0, 'new_messages' => 0, 'skipped_groups' => 0, 'snapshot_at' => null];
        $latest = 0;
        $skipped = null;
        foreach ($this->reader->chats($database, $skipped) as $chat) {
            [$created, $added] = $this->store($integration, $phoneLine, $chat['contact'], $chat['messages']);
            $sheet?->add($chat['contact'], $chat['messages']);
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
        if (! $line->active) {
            return $stats;
        }
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
     * "Yedekten Excel al": the backup is only read into its Excel; nothing is stored in the inbox.
     *
     * @return array{chats: int, new_chats: int, messages: int, new_messages: int, skipped_groups: int, snapshot_at: ?string}
     */
    private function sheetOnly(WhatsAppBackupImport $import, string $database, ?WhatsAppBackupSpreadsheet $sheet): array
    {
        $stats = ['chats' => 0, 'new_chats' => 0, 'messages' => 0, 'new_messages' => 0, 'skipped_groups' => 0, 'snapshot_at' => null];
        $latest = 0;
        $skipped = null;
        foreach ($this->reader->chats($database, $skipped) as $chat) {
            $sheet?->add($chat['contact'], $chat['messages']);
            $stats['chats']++;
            $stats['messages'] += count($chat['messages']);
            $latest = max($latest, ...array_column($chat['messages'], 'at'));
            if ($stats['chats'] % 25 === 0) {
                $import->update(['stats' => [...$stats, 'phase' => 'import']]);
            }
        }
        $stats['skipped_groups'] = (int) ($skipped['groups'] ?? 0);
        $stats['snapshot_at'] = $latest > 0 ? CarbonImmutable::createFromTimestampMsUTC($latest)->toIso8601String() : null;

        return $stats;
    }

    /**
     * @param  list<array{id: string, outgoing: bool, at: int, type: string, body: string}>  $messages
     * @return array{0: bool, 1: int} [conversation created, messages added]
     */
    private function store(CoreIntegration $integration, string $phoneLine, string $contact, array $messages): array
    {
        return DB::transaction(function () use ($integration, $phoneLine, $contact, $messages): array {
            // Imported chats are drafted only on the operator's "Mesaj üret" click, never automatically.
            $conversation = WhatsAppConversation::query()->firstOrCreate([
                'integration_id' => $integration->id, 'phone_number_id' => $phoneLine, 'contact_id' => $contact,
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
        File::delete($import->excelPath());
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
