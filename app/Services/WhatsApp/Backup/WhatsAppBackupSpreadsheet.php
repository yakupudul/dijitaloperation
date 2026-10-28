<?php

namespace App\Services\WhatsApp\Backup;

use App\Models\WhatsAppBackupImport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * The Excel of one uploaded backup, written while it is read: only the chats inside that file, so two businesses'
 * backups never share a sheet (yakup, 2026-10-28). "Mesajlar" has one row per message, "Görüşmeler" one per chat.
 * The finished file is kept encrypted next to the backups until the operator deletes it.
 */
final class WhatsAppBackupSpreadsheet
{
    public const DIRECTIONS = ['incoming' => 'Gelen', 'outgoing' => 'Giden'];

    public const TYPES = [
        'text' => 'Metin', 'image' => 'Fotoğraf', 'audio' => 'Ses', 'video' => 'Video', 'document' => 'Belge',
        'sticker' => 'Çıkartma', 'location' => 'Konum', 'contacts' => 'Kişi kartı', 'gif' => 'GIF',
    ];

    private Writer $writer;

    private string $temp;

    /** @var list<list<int|string>> */
    private array $summary = [];

    public function __construct()
    {
        $this->temp = (string) tempnam(sys_get_temp_dir(), 'wa-backup-xlsx');
        $this->writer = new Writer;
        $this->writer->openToFile($this->temp);
        $this->writer->getCurrentSheet()->setName('Mesajlar');
        $this->writer->addRow(Row::fromValues(['Numara', 'Tarih', 'Yön', 'Tür', 'Mesaj']));
    }

    /** @param  list<array{id: string, outgoing: bool, at: int, type: string, body: string}>  $messages */
    public function add(string $contact, array $messages): void
    {
        $number = self::number($contact);
        $incoming = 0;
        foreach ($messages as $message) {
            $incoming = $message['outgoing'] ? $incoming : max($incoming, $message['at']);
            $this->writer->addRow(Row::fromValues([
                $number, self::time($message['at']), self::DIRECTIONS[$message['outgoing'] ? 'outgoing' : 'incoming'],
                self::TYPES[$message['type']] ?? $message['type'], $message['body'],
            ]));
        }
        $last = $messages === [] ? 0 : max(array_column($messages, 'at'));
        $this->summary[] = [$number, self::time($last), $incoming > 0 ? self::time($incoming) : '', count($messages)];
    }

    /** Finishes the file and stores it encrypted as the import's Excel. */
    public function save(WhatsAppBackupImport $import): void
    {
        $this->writer->addNewSheetAndMakeItCurrent()->setName('Görüşmeler');
        $this->writer->addRow(Row::fromValues(['Numara', 'Son mesaj', 'Son gelen mesaj', 'Mesaj sayısı']));
        usort($this->summary, fn (array $a, array $b): int => strcmp((string) $b[1], (string) $a[1]));
        foreach ($this->summary as $row) {
            $this->writer->addRow(Row::fromValues($row));
        }
        $this->writer->close();
        File::ensureDirectoryExists(dirname($import->excelPath()), 0700);
        File::put($import->excelPath(), Crypt::encryptString((string) file_get_contents($this->temp)));
        File::delete($this->temp);
    }

    public function discard(): void
    {
        try {
            $this->writer->close();
        } catch (\Throwable) {
        }
        File::delete($this->temp);
    }

    /** The stored Excel of an import as a plain temporary file to download, or null when there is none. */
    public static function open(WhatsAppBackupImport $import): ?string
    {
        if (! is_file($import->excelPath())) {
            return null;
        }
        $path = (string) tempnam(sys_get_temp_dir(), 'wa-backup-xlsx');
        File::put($path, Crypt::decryptString((string) file_get_contents($import->excelPath())));

        return $path;
    }

    public static function number(string $contact): string
    {
        return ctype_digit($contact) ? '+'.$contact : 'Gizli numara';
    }

    private static function time(int $ms): string
    {
        return $ms > 0 ? CarbonImmutable::createFromTimestampMsUTC($ms)->timezone('Europe/Istanbul')->format('Y-m-d H:i') : '';
    }
}
