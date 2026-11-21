<?php

namespace App\Panel\Services;

use App\Panel\Jobs\ImportPanelBackup;
use App\Panel\Models\PanelConversation;
use App\Panel\Models\PanelImport;
use App\Panel\Models\PanelMessage;
use App\Panel\Models\PanelPerson;
use App\Panel\Support\PanelContext;
use App\Panel\Support\PhoneKey;
use App\Services\WhatsApp\Backup\WhatsAppBackupFormatException;
use App\Services\WhatsApp\Backup\WhatsAppBackupKeyException;
use App\Services\WhatsApp\Backup\WhatsAppBackupReader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * A brand brings its own WhatsApp Business backup: the file comes up in pieces, opens with the brand's 64-digit key
 * and its one-to-one chats land in the inbox. Old chats do not become leads; a second backup adds only what is missing.
 */
final class PanelBackups
{
    public const int CHUNK_BYTES = 4 * 1024 * 1024;

    public const int MAX_BYTES = 2 * 1024 * 1024 * 1024;

    public function __construct(private readonly PanelContext $context, private readonly WhatsAppBackupReader $reader) {}

    public function begin(int $brandId, int $userId, string $fileName, int $size): PanelImport
    {
        $name = trim($fileName);
        if (preg_match('/\.crypt1[0-4]$/i', $name) === 1) {
            throw ValidationException::withMessages(['backup' => 'Bu yedek eski biçimde. Telefonda WhatsApp Business › Ayarlar › Sohbetler › Sohbet yedeği › Uçtan uca şifreli yedek bölümünden 64 haneli anahtarla yeni bir yedek alın.']);
        }
        if (preg_match('/\.crypt15$/i', $name) !== 1) {
            throw ValidationException::withMessages(['backup' => 'msgstore.db.crypt15 dosyasını seçin (telefonda WhatsApp Business › Databases klasöründe).']);
        }
        if ($size < 64 || $size > self::MAX_BYTES) {
            throw ValidationException::withMessages(['backup' => 'Dosya boyutu geçersiz (en çok 2 GB).']);
        }

        return $this->context->system(function () use ($brandId, $userId, $name, $size): PanelImport {
            $open = PanelImport::query()->where('brand_id', $brandId)->where('kind', PanelImport::BACKUP);
            if ((clone $open)->whereIn('status', ['queued', 'running'])->exists()) {
                throw ValidationException::withMessages(['backup' => 'Önceki yedek şu an aktarılıyor; bitince yenisini yükleyebilirsiniz.']);
            }
            // One file at a time: an earlier upload nobody finished is dropped.
            foreach ((clone $open)->whereIn('status', ['uploading', 'uploaded'])->get() as $old) {
                File::delete($old->file());
                $old->delete();
            }
            $import = new PanelImport;
            $import->brand_id = $brandId;
            $import->fill(['kind' => PanelImport::BACKUP, 'status' => 'uploading', 'file_name' => mb_substr($name, 0, 190), 'size' => $size, 'created_by' => $userId])->save();
            File::ensureDirectoryExists(dirname($import->file()), 0700);
            File::put($import->file(), '');

            return $import;
        });
    }

    /** Appends one piece at its offset; a piece for another offset is refused with the offset expected (resume). */
    public function chunk(PanelImport $import, int $offset, string $bytes): PanelImport
    {
        return DB::transaction(function () use ($import, $offset, $bytes): PanelImport {
            $import = PanelImport::query()->lockForUpdate()->findOrFail($import->id);
            if ($import->status !== 'uploading' || $import->kind !== PanelImport::BACKUP) {
                throw new HttpException(409, 'Bu yükleme artık açık değil. Dosyayı yeniden seçin.');
            }
            if ($offset !== $import->received_bytes) {
                return $import;
            }
            $length = strlen($bytes);
            if ($length === 0 || $length > self::CHUNK_BYTES || $import->received_bytes + $length > $import->size) {
                throw new HttpException(422, 'Parça boyutu geçersiz.');
            }
            File::append($import->file(), $bytes);
            $import->received_bytes += $length;
            if ($import->received_bytes === $import->size) {
                $import->status = 'uploaded';
            }
            $import->save();

            return $import;
        });
    }

    /** Takes the key for one run and queues the work; the key is wiped when the run ends. */
    public function queue(PanelImport $import, string $key): void
    {
        $hex = strtolower(preg_replace('/[\s-]+/', '', $key) ?? '');
        if (preg_match('/^[0-9a-f]{64}$/', $hex) !== 1) {
            throw ValidationException::withMessages(['key' => 'Anahtar 64 haneli olmalı (0-9 ve a-f). WhatsApp Business yedeği şifrelerken gösterdiği anahtarı girin.']);
        }
        if ($import->kind !== PanelImport::BACKUP || $import->status !== 'uploaded') {
            throw ValidationException::withMessages(['key' => 'Önce yedek dosyasının yüklenmesi bitmeli.']);
        }
        $import->forceFill(['status' => 'queued', 'secret' => $hex, 'error' => null])->save();
        ImportPanelBackup::dispatch($import->id);
    }

    public function run(int $importId): void
    {
        $this->context->system(function () use ($importId): void {
            $import = PanelImport::query()->find($importId);
            if ($import === null || $import->status !== 'queued') {
                return;
            }
            $import->forceFill(['status' => 'running', 'started_at' => now()])->save();
            $limit = (string) ini_get('memory_limit');
            if ((int) $limit > 0) {
                ini_set('memory_limit', (string) min(4096, (int) ceil((3 * $import->size) / 1048576) + 512).'M');
            }
            $sqlite = $import->file().'.sqlite';
            $keep = false;
            try {
                $this->reader->decrypt($import->file(), (string) $import->secret, $sqlite);
                $import->forceFill(['status' => 'completed', 'stats' => $this->store($import, $sqlite), 'error' => null]);
                if ($import->created_by) {
                    app(PanelNotifier::class)->user((int) $import->brand_id, (int) $import->created_by, 'import', 'WhatsApp yedeği aktarıldı: '.($import->stats['chats'] ?? 0).' sohbet', route('panel.inbox', ['kanal' => 'whatsapp']));
                }
            } catch (WhatsAppBackupKeyException) {
                // The file is fine, the key is not: it waits for another try.
                $keep = true;
                $import->forceFill(['status' => 'uploaded', 'error' => 'Anahtar bu yedeği açmadı. Yedeği şifrelerken WhatsApp Business\'ın gösterdiği 64 haneli anahtarı girin; parola ile şifrelenmiş yedek açılamaz.']);
            } catch (WhatsAppBackupFormatException) {
                $import->forceFill(['status' => 'failed', 'error' => 'Dosya WhatsApp\'ın şifreli yedeği (msgstore.db.crypt15) gibi görünmüyor.']);
            } catch (Throwable $exception) {
                report($exception);
                $import->forceFill(['status' => 'failed', 'error' => 'Yedek okunurken bir sorun çıktı. Destek ekranından bize yazın, birlikte bakalım.']);
            } finally {
                File::delete($sqlite);
                if (! $keep) {
                    File::delete($import->file());
                }
                $import->forceFill(['secret' => null, 'finished_at' => now()])->save();
            }
        });
    }

    /** @return array<string, int> */
    private function store(PanelImport $import, string $sqlite): array
    {
        $stats = ['chats' => 0, 'new_chats' => 0, 'messages' => 0, 'linked' => 0, 'groups' => 0, 'other' => 0];
        $skipped = null;
        foreach ($this->reader->chats($sqlite, $skipped) as $chat) {
            $contact = (string) $chat['contact'];
            $isNumber = ctype_digit($contact);
            $key = $isNumber ? (PhoneKey::of($contact) ?? $contact) : mb_substr($contact, 0, 64);
            $conversation = PanelConversation::query()->where('brand_id', $import->brand_id)->where('channel', 'whatsapp')->where('contact_key', $key)->first();
            if ($conversation === null) {
                $conversation = new PanelConversation;
                $conversation->brand_id = $import->brand_id;
                // An old chat is history, not a request waiting for an answer.
                $conversation->fill(['channel' => 'whatsapp', 'contact_key' => $key, 'contact_phone' => $isNumber ? '+'.$contact : null, 'origin' => 'backup', 'handled_at' => now()]);
                $stats['new_chats']++;
            }
            if ($conversation->person_id === null && $isNumber) {
                $conversation->person_id = PanelPerson::query()->where('brand_id', $import->brand_id)->where('phone_key', $key)->value('id');
                $stats['linked'] += $conversation->person_id ? 1 : 0;
            }
            $conversation->save();

            $known = $conversation->wasRecentlyCreated ? [] : array_flip(PanelMessage::query()->where('conversation_id', $conversation->id)->pluck('external_id')->all());
            $rows = [];
            foreach ($chat['messages'] as $message) {
                if (isset($known[$message['id']])) {
                    continue;
                }
                $known[$message['id']] = true;
                $rows[] = [
                    'brand_id' => $import->brand_id, 'conversation_id' => $conversation->id, 'external_id' => $message['id'], 'outgoing' => $message['outgoing'],
                    'type' => mb_substr((string) $message['type'], 0, 24), 'body' => Crypt::encryptString((string) $message['body']),
                    'sent_at' => CarbonImmutable::createFromTimestampMs($message['at'])->utc()->format('Y-m-d H:i:s'), 'import_id' => $import->id,
                    'created_at' => now(), 'updated_at' => now(),
                ];
            }
            foreach (array_chunk($rows, 400) as $part) {
                PanelMessage::query()->insert($part);
            }
            $stats['messages'] += count($rows);
            $stats['chats']++;
            $this->refresh($conversation);
        }
        $stats['groups'] = (int) ($skipped['groups'] ?? 0);
        $stats['other'] = (int) ($skipped['other'] ?? 0);

        return $stats;
    }

    /** Recounts a conversation from its messages: the last line, the last incoming one, how many there are. */
    public function refresh(PanelConversation $conversation): void
    {
        $messages = PanelMessage::query()->where('conversation_id', $conversation->id);
        $last = (clone $messages)->orderByDesc('sent_at')->orderByDesc('id')->first();
        $conversation->forceFill([
            'message_count' => (clone $messages)->count(),
            'last_message_at' => $last?->sent_at,
            'last_outgoing' => (bool) $last?->outgoing,
            'last_preview' => $last ? mb_substr((string) $last->body, 0, 140) : null,
            'last_incoming_at' => (clone $messages)->where('outgoing', false)->max('sent_at'),
        ])->save();
    }

    /** Takes back what one backup added: its messages, and the chats left empty by that. Leads stay. */
    public function undo(PanelImport $import): void
    {
        if ($import->kind !== PanelImport::BACKUP || $import->status !== 'completed') {
            return;
        }
        $this->context->system(function () use ($import): void {
            $ids = PanelMessage::query()->where('import_id', $import->id)->distinct()->pluck('conversation_id');
            PanelMessage::query()->where('import_id', $import->id)->delete();
            foreach (PanelConversation::query()->whereIn('id', $ids)->get() as $conversation) {
                if (! PanelMessage::query()->where('conversation_id', $conversation->id)->exists()) {
                    $conversation->delete();
                } else {
                    $this->refresh($conversation);
                }
            }
            $import->forceFill(['status' => 'undone'])->save();
        });
    }
}
