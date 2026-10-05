<?php

namespace App\Services\WhatsApp\Backup;

use Generator;
use PDO;

/**
 * Opens an Android WhatsApp Business end-to-end encrypted backup (msgstore.db.crypt15) with its 64-digit key and reads
 * the one-to-one chats out of the SQLite database inside. Groups, broadcasts, status updates and system notices are
 * left out; attachments are not read (a placeholder with the caption stands in).
 *
 * crypt15 layout: [protobuf size][optional 0x01 feature flag][BackupPrefix protobuf with the IV][AES-256-GCM data]
 * [16-byte tag][optional 16-byte MD5]. The data is zlib-compressed SQLite; the AES key is derived from the 64-digit
 * key with HMAC-SHA256 ("backup encryption").
 */
final class WhatsAppBackupReader
{
    public const KEY_ERROR = 'Anahtar bu yedeği açmadı. WhatsApp Business\'ta yedeği şifrelerken verilen 64 haneli anahtarı girin. Parola ile şifrelenmiş yedek açılamaz; telefonda yedeği 64 haneli anahtarla yeniden alın.';

    public const FORMAT_ERROR = 'Dosya WhatsApp\'ın uçtan uca şifreli yedeği (msgstore.db.crypt15) gibi görünmüyor. Telefonda Android › media › com.whatsapp.w4b › WhatsApp Business › Databases klasöründeki msgstore.db.crypt15 dosyasını yükleyin.';

    public const SQLITE_MISSING = 'Anahtar doğru, yedek açıldı; ama sunucuda PHP\'nin SQLite eklentisi olmadığı için içindeki sohbetler okunamıyor. Sunucuda bir kez şunu çalıştırın: sudo apt install -y phpX.Y-sqlite3 && sudo systemctl restart phpX.Y-fpm && sudo supervisorctl restart moxdop-staging-horizon (X.Y: php -v ile görünen sürüm). Sonra anahtar alanını boş bırakıp Çıkar\'a basın; dosya ve anahtar duruyor.';

    /** Whether this PHP can read the SQLite database inside the backup (pdo_sqlite). */
    public static function canReadDatabase(): bool
    {
        return in_array('sqlite', PDO::getAvailableDrivers(), true);
    }

    /** Message types in msgstore that are not conversation (system notices, calls, polls' internals). */
    private const SKIPPED_TYPES = [7, 8, 10, 11, 12, 14, 17, 18, 19, 21, 22, 27, 28, 36, 38, 39, 45, 46, 54, 64, 66, 90, 112];

    /** msgstore message type => [stored type, Turkish placeholder]. */
    private const MEDIA = [
        1 => ['image', 'Fotoğraf'], 2 => ['audio', 'Ses kaydı'], 3 => ['video', 'Video'], 4 => ['contacts', 'Kişi kartı'],
        5 => ['location', 'Konum'], 9 => ['document', 'Belge'], 13 => ['gif', 'GIF'], 16 => ['location', 'Canlı konum'],
        20 => ['sticker', 'Çıkartma'], 42 => ['image', 'Tek seferlik fotoğraf'], 43 => ['video', 'Tek seferlik video'],
    ];

    /** The 64 hex digits WhatsApp shows (spaces and dashes allowed), or null. */
    public static function normaliseKey(string $key): ?string
    {
        $hex = strtolower((string) preg_replace('/[\s\-]+/', '', $key));

        return preg_match('/^[0-9a-f]{64}$/', $hex) === 1 ? $hex : null;
    }

    /** AES key of a crypt15 backup: HMAC-SHA256(HMAC-SHA256(zeros, root), "backup encryption" + 0x01). */
    public static function encryptionKey(string $hexKey): string
    {
        $private = hash_hmac('sha256', (string) hex2bin($hexKey), str_repeat("\0", 32), true);

        return hash_hmac('sha256', "backup encryption\x01", $private, true);
    }

    /**
     * Decrypts the backup into a plain SQLite file.
     *
     * @throws WhatsAppBackupKeyException when the key does not open the file
     * @throws WhatsAppBackupFormatException when the file is not a crypt15 backup
     */
    public function decrypt(string $source, string $hexKey, string $target): void
    {
        $data = @file_get_contents($source);
        if ($data === false || strlen($data) < 64) {
            throw new WhatsAppBackupFormatException(self::FORMAT_ERROR);
        }
        $size = ord($data[0]);
        $offset = $data[1] === "\x01" ? 2 : 1;
        $iv = self::iv(substr($data, $offset, $size));
        if ($iv === null) {
            throw new WhatsAppBackupFormatException(self::FORMAT_ERROR);
        }
        $payload = substr($data, $offset + $size);
        unset($data);
        $key = self::encryptionKey($hexKey);
        $plain = false;
        // With the trailing MD5 checksum (single-file backups) first, then without it.
        foreach ([32, 16] as $trailer) {
            if (strlen($payload) <= $trailer) {
                continue;
            }
            $plain = openssl_decrypt(substr($payload, 0, -$trailer), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, substr($payload, -$trailer, 16));
            if ($plain !== false) {
                break;
            }
        }
        unset($payload);
        if ($plain === false) {
            throw new WhatsAppBackupKeyException(self::KEY_ERROR);
        }
        $this->inflate($plain, $target);
    }

    /**
     * One-to-one chats, one entry per chat in the database (a contact's phone and hidden-number chats come separately).
     *
     * @return Generator<int, array{contact: string, messages: list<array{id: string, outgoing: bool, at: int, type: string, body: string}>}>
     */
    public function chats(string $sqlitePath, ?array &$skipped = null): Generator
    {
        $skipped = ['groups' => 0, 'other' => 0];
        $pdo = new PDO('sqlite:'.$sqlitePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
        $rows = match (true) {
            in_array('message', $tables, true) && in_array('chat', $tables, true) && in_array('jid', $tables, true) => $this->modernRows($pdo, $tables),
            in_array('messages', $tables, true) => $this->legacyRows($pdo),
            default => throw new WhatsAppBackupFormatException('Yedeğin içinde mesaj tablosu bulunamadı; WhatsApp sürümü desteklenmiyor olabilir.'),
        };
        $chat = null;
        $contact = null;
        $messages = [];
        $limit = now()->addDay()->getTimestampMs();
        foreach ($rows as $row) {
            if ($row['chat'] !== $chat) {
                if ($contact !== null && $messages !== []) {
                    yield ['contact' => $contact, 'messages' => $messages];
                }
                $chat = $row['chat'];
                $messages = [];
                $contact = $this->contact($row);
                if ($contact === null) {
                    $skipped[in_array($row['server'], ['g.us', 'broadcast', 'newsletter'], true) ? 'groups' : 'other']++;
                }
            }
            if ($contact === null) {
                continue;
            }
            $at = (int) $row['timestamp'];
            $message = $this->message((int) $row['type'], (string) ($row['text'] ?? ''));
            if ($message === null || $at <= 0 || $at > $limit) {
                continue;
            }
            $id = trim((string) $row['key_id']);
            $messages[] = [
                'id' => mb_substr($id !== '' ? $id : 'row-'.$row['row_id'], 0, 255), 'outgoing' => (int) $row['from_me'] === 1,
                'at' => $at, 'type' => $message[0], 'body' => $message[1],
            ];
        }
        if ($contact !== null && $messages !== []) {
            yield ['contact' => $contact, 'messages' => $messages];
        }
    }

    /** The IV inside BackupPrefix: field 3 (C15_IV) → field 1 (16 bytes). */
    private static function iv(string $prefix): ?string
    {
        $value = self::field($prefix, 3);

        return $value !== null ? self::field($value, 1) : null;
    }

    /** First length-delimited field with this number in a protobuf message, skipping the others. */
    private static function field(string $message, int $number): ?string
    {
        $position = 0;
        $length = strlen($message);
        while ($position < $length) {
            $tag = self::varint($message, $position);
            if ($tag === null) {
                return null;
            }
            $wire = $tag & 7;
            $field = $tag >> 3;
            if ($wire === 0) {
                if (self::varint($message, $position) === null) {
                    return null;
                }
            } elseif ($wire === 2) {
                $size = self::varint($message, $position);
                if ($size === null || $position + $size > $length) {
                    return null;
                }
                if ($field === $number) {
                    return substr($message, $position, $size);
                }
                $position += $size;
            } elseif ($wire === 5) {
                $position += 4;
            } elseif ($wire === 1) {
                $position += 8;
            } else {
                return null;
            }
        }

        return null;
    }

    private static function varint(string $data, int &$position): ?int
    {
        $result = 0;
        for ($shift = 0; $shift < 64; $shift += 7) {
            if ($position >= strlen($data)) {
                return null;
            }
            $byte = ord($data[$position++]);
            $result |= ($byte & 0x7F) << $shift;
            if ($byte < 0x80) {
                return $result;
            }
        }

        return null;
    }

    private function inflate(string $compressed, string $target): void
    {
        $context = inflate_init(ZLIB_ENCODING_DEFLATE);
        $out = fopen($target, 'wb');
        if ($context === false || $out === false) {
            throw new WhatsAppBackupFormatException('Yedek açılırken geçici dosya yazılamadı.');
        }
        try {
            $length = strlen($compressed);
            for ($position = 0; $position < $length; $position += 1048576) {
                $chunk = @inflate_add($context, substr($compressed, $position, 1048576), $position + 1048576 >= $length ? ZLIB_FINISH : ZLIB_SYNC_FLUSH);
                if ($chunk === false) {
                    throw new WhatsAppBackupFormatException(self::FORMAT_ERROR);
                }
                fwrite($out, $chunk);
            }
        } finally {
            fclose($out);
        }
        $head = (string) file_get_contents($target, false, null, 0, 16);
        if ($head !== "SQLite format 3\0") {
            throw new WhatsAppBackupFormatException(self::FORMAT_ERROR);
        }
    }

    /** @return Generator<int, array<string, mixed>> */
    private function modernRows(PDO $pdo, array $tables): Generator
    {
        $columns = $this->columns($pdo, 'message');
        $text = in_array('text_data', $columns, true) ? 'm.text_data' : (in_array('data', $columns, true) ? 'm.data' : 'NULL');
        $type = in_array('message_type', $columns, true) ? 'm.message_type' : '0';
        // Newer versions keep some chats under a hidden-number id (lid); jid_map leads back to the phone number.
        $lid = in_array('jid_map', $tables, true) && array_intersect(['lid_row_id', 'jid_row_id'], $this->columns($pdo, 'jid_map')) === ['lid_row_id', 'jid_row_id'];
        $sql = 'SELECT m._id AS row_id, c._id AS chat, m.key_id, m.from_me, m.timestamp, '.$type.' AS type, '.$text.' AS text, '
            .'j.user AS user, j.server AS server'.($lid ? ', pj.user AS phone_user, pj.server AS phone_server' : '')
            .' FROM message m JOIN chat c ON c._id = m.chat_row_id JOIN jid j ON j._id = c.jid_row_id'
            .($lid ? ' LEFT JOIN jid_map jm ON jm.lid_row_id = j._id LEFT JOIN jid pj ON pj._id = jm.jid_row_id' : '')
            .' ORDER BY c._id, m.timestamp, m._id';
        foreach ($pdo->query($sql, PDO::FETCH_ASSOC) as $row) {
            yield $row;
        }
    }

    /** @return Generator<int, array<string, mixed>> */
    private function legacyRows(PDO $pdo): Generator
    {
        $columns = $this->columns($pdo, 'messages');
        $caption = in_array('media_caption', $columns, true) ? "COALESCE(data, media_caption, '')" : 'data';
        $type = in_array('media_wa_type', $columns, true) ? 'CAST(media_wa_type AS INTEGER)' : '0';
        $sql = 'SELECT _id AS row_id, key_remote_jid AS chat, key_id, key_from_me AS from_me, timestamp, '.$type.' AS type, '
            .$caption.' AS text, key_remote_jid AS jid FROM messages ORDER BY key_remote_jid, timestamp, _id';
        foreach ($pdo->query($sql, PDO::FETCH_ASSOC) as $row) {
            [$user, $server] = array_pad(explode('@', (string) $row['jid'], 2), 2, '');
            yield [...$row, 'user' => $user, 'server' => $server];
        }
    }

    /** @return list<string> */
    private function columns(PDO $pdo, string $table): array
    {
        return array_column($pdo->query('PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_ASSOC), 'name');
    }

    /** The contact's phone digits, "lid…" for a hidden number, or null for a group / broadcast / status chat. */
    private function contact(array $row): ?string
    {
        $user = (string) ($row['user'] ?? '');
        $server = (string) ($row['server'] ?? '');
        if ($server === 'lid') {
            if (($row['phone_server'] ?? null) === 's.whatsapp.net' && preg_match('/^[0-9]{7,20}$/', (string) $row['phone_user']) === 1) {
                return (string) $row['phone_user'];
            }

            return preg_match('/^[0-9]{5,30}$/', $user) === 1 ? 'lid'.$user : null;
        }

        return $server === 's.whatsapp.net' && preg_match('/^[0-9]{7,20}$/', $user) === 1 ? $user : null;
    }

    /** @return array{0: string, 1: string}|null [type, body] */
    private function message(int $type, string $text): ?array
    {
        $text = trim(mb_substr($text, 0, 20000));
        if (in_array($type, self::SKIPPED_TYPES, true)) {
            return null;
        }
        if ($type === 15) {
            return ['deleted', '[Bu mesaj silindi]'];
        }
        if (isset(self::MEDIA[$type])) {
            [$kind, $label] = self::MEDIA[$type];

            return [$kind, '['.$label.' — içeriği okunmadı]'.($text !== '' ? ' '.$text : '')];
        }

        return $text !== '' ? ['text', $text] : null;
    }
}
