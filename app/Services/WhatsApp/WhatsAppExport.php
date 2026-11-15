<?php

namespace App\Services\WhatsApp;

use App\Models\Customer;
use App\Models\WhatsAppBackupLine;
use App\Models\WhatsAppConversation;
use Carbon\Carbon;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * The WhatsApp Excel ("Mesajlar": one row per message, oldest first within each chat; "Görüşmeler": one row per chat).
 * Small exports are written inside the request; a big one (a whole phone backup: 1860 chats, 394k encrypted messages
 * ran past PHP's 30 s limit and gave a 500, 2026-11-15) is written by a heavy-queue job while a page waits and then
 * starts the download. The finished file lives only until it is downloaded (or a day at most): it holds private chats.
 */
final class WhatsAppExport
{
    /** Exports with more messages than this are written in the background. */
    public static function inlineLimit(): int
    {
        return (int) config('whatsapp.export_inline_limit', 15000);
    }

    private const DIRECTIONS = ['incoming' => 'Gelen', 'outgoing' => 'Giden'];

    private const TYPES = [
        'text' => 'Metin', 'image' => 'Fotoğraf', 'audio' => 'Ses', 'video' => 'Video', 'document' => 'Belge',
        'sticker' => 'Çıkartma', 'location' => 'Konum', 'contacts' => 'Kişi kartı', 'gif' => 'GIF',
    ];

    /** @return Builder<WhatsAppConversation> */
    public static function conversations(?WhatsAppBackupLine $line, ?int $integrationId, ?int $only = null): Builder
    {
        return ($line !== null ? $line->conversations() : WhatsAppConversation::query()->where('integration_id', $integrationId))
            ->when($only, fn ($query) => $query->whereKey($only));
    }

    /** @param  Builder<WhatsAppConversation>  $conversations */
    public static function messageCount(Builder $conversations): int
    {
        return (int) DB::table('whatsapp_messages')->whereIn('conversation_id', (clone $conversations)->select('id'))->count();
    }

    public static function fileName(?WhatsAppBackupLine $line, ?int $only = null): string
    {
        return 'whatsapp-'.($line !== null ? Str::slug($line->name).'-' : '').($only ? 'gorusme-'.$only : 'konusmalar')
            .'-'.now('Europe/Istanbul')->format('Y-m-d').'.xlsx';
    }

    /**
     * Writes the workbook to $path. Messages are read as plain rows and decrypted here (no model per message), one
     * chat at a time, so memory stays flat whatever the backup size.
     *
     * @param  Builder<WhatsAppConversation>  $conversations
     */
    public static function write(Builder $conversations, string $path): void
    {
        /** @var Collection<int, WhatsAppConversation> $chats */
        $chats = (clone $conversations)->orderByDesc('last_message_at')->orderByDesc('id')
            ->get(['id', 'contact_id', 'contact_name', 'customer_id', 'last_message_at', 'last_incoming_at', 'opted_out_at']);
        $customers = Customer::query()->whereIn('id', $chats->pluck('customer_id')->filter()->unique())->pluck('name', 'id');
        $time = fn ($at): string => $at ? Carbon::parse($at)->timezone('Europe/Istanbul')->format('Y-m-d H:i') : '';
        $label = fn (WhatsAppConversation $chat): array => [
            (string) ($chat->contact_name ?? ''),
            ctype_digit((string) $chat->contact_id) ? '+'.$chat->contact_id : 'Gizli numara',
        ];

        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Mesajlar');
        $writer->addRow(Row::fromValues(['Kişi', 'Numara', 'Müşteri', 'Tarih', 'Yön', 'Tür', 'Mesaj']));
        $counts = [];
        foreach ($chats as $chat) {
            [$name, $number] = $label($chat);
            $customer = (string) ($customers[$chat->customer_id] ?? '');
            $counts[$chat->id] = 0;
            DB::table('whatsapp_messages')->where('conversation_id', $chat->id)
                ->orderBy('sent_at')->orderBy('id')->select(['id', 'sent_at', 'direction', 'message_type', 'body'])
                ->chunk(2000, function (Collection $rows) use ($writer, $name, $number, $customer, $time, $chat, &$counts): void {
                    foreach ($rows as $row) {
                        $counts[$chat->id]++;
                        $writer->addRow(Row::fromValues([
                            $name, $number, $customer, $time($row->sent_at),
                            self::DIRECTIONS[$row->direction] ?? (string) $row->direction,
                            self::TYPES[$row->message_type] ?? (string) $row->message_type,
                            self::body($row->body),
                        ]));
                    }
                });
        }
        $writer->addNewSheetAndMakeItCurrent()->setName('Görüşmeler');
        $writer->addRow(Row::fromValues(['Kişi', 'Numara', 'Müşteri', 'Son mesaj', 'Son gelen mesaj', 'Mesaj sayısı', 'Mesaj istemiyor']));
        foreach ($chats as $chat) {
            [$name, $number] = $label($chat);
            $writer->addRow(Row::fromValues([
                $name, $number, (string) ($customers[$chat->customer_id] ?? ''), $time($chat->last_message_at),
                $time($chat->last_incoming_at), $counts[$chat->id] ?? 0, $chat->opted_out_at ? 'Evet' : '',
            ]));
        }
        $writer->close();
    }

    // Background exports -------------------------------------------------------------------------------------------

    /** @return array{user_id: int, status: string, name: string, at: string, line_id: ?int}|null */
    public static function state(string $token): ?array
    {
        return Cache::get(self::cacheKey($token));
    }

    /** @param  array<string, mixed>  $state */
    public static function remember(string $token, array $state): void
    {
        Cache::put(self::cacheKey($token), $state, now()->addDay());
    }

    public static function path(string $token): string
    {
        return storage_path('app/private/whatsapp-exports/'.$token.'.xlsx');
    }

    /** Removes finished files nobody downloaded within a day. */
    public static function prune(): void
    {
        $dir = storage_path('app/private/whatsapp-exports');
        if (! File::isDirectory($dir)) {
            return;
        }
        foreach (File::files($dir) as $file) {
            if ($file->getMTime() < now()->subDay()->getTimestamp()) {
                File::delete($file->getPathname());
            }
        }
    }

    private static function cacheKey(string $token): string
    {
        return 'whatsapp-export:'.$token;
    }

    private static function body(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return '';
        }
    }
}
