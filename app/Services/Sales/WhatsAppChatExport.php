<?php

namespace App\Services\Sales;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Reads a chat exported from the phone (WhatsApp › ⋮ › Diğer › Sohbeti dışa aktar, "medya olmadan"): one line per
 * message, "d.m.Y H:i - Name: text" in Turkish phones, "[d/m/Y, H:i:ss] Name: text" on iPhone; continuation lines
 * belong to the message above. The exporting side's own name is read from the file name or the operator's name.
 */
final class WhatsAppChatExport
{
    /**
     * @return array{contact: ?string, messages: list<array{at: CarbonImmutable, sender: string, body: string}>}
     */
    public static function parse(string $text, string $fileName = ''): array
    {
        $text = str_replace(["\xEF\xBB\xBF", "\r\n", "\r", "\u{200E}", "\u{202F}", "\u{00A0}"], ['', "\n", "\n", '', ' ', ' '], $text);
        $messages = [];
        $current = null;
        $pattern = '/^\[?(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{2,4}),?\s+(\d{1,2}):(\d{2})(?::(\d{2}))?\s*(?:[ap]\.?m\.?|ÖÖ|ÖS)?\]?\s*(?:-\s*)?([^:]{1,80}?):\s(.*)$/u';
        foreach (explode("\n", $text) as $line) {
            if (preg_match($pattern, $line, $m)) {
                if ($current !== null) {
                    $messages[] = $current;
                }
                $year = (int) $m[3];
                $year = $year < 100 ? 2000 + $year : $year;
                try {
                    $at = CarbonImmutable::create($year, (int) $m[2], (int) $m[1], (int) $m[4], (int) $m[5], (int) ($m[6] ?? 0), 'Europe/Istanbul');
                } catch (Throwable) {
                    $current = null;

                    continue;
                }
                $current = ['at' => $at, 'sender' => trim($m[7]), 'body' => trim($m[8])];

                continue;
            }
            if ($current !== null && trim($line) !== '') {
                $current['body'] .= "\n".trim($line);
            }
        }
        if ($current !== null) {
            $messages[] = $current;
        }
        $messages = array_values(array_filter($messages, fn (array $m): bool => ! preg_match('/uçtan uca şifreli|end-to-end encrypted|Messages and calls are end-to-end/iu', $m['body'])));
        $contact = null;
        if (preg_match('/(?:WhatsApp\s+)?(?:Chat with|Sohbet)\s+(.+?)(?:\.txt)?$/iu', trim($fileName), $m)) {
            $contact = trim(str_replace('_', ' ', $m[1]));
        } elseif (preg_match('/^(.+?)\s+(?:ile|with)\s+(?:WhatsApp\s+)?(?:sohbeti|chat)/iu', trim($fileName), $m)) {
            $contact = trim($m[1]);
        }

        return ['contact' => $contact !== '' ? $contact : null, 'messages' => $messages];
    }

    /**
     * Which sender is the business: the sender named like the agency, else the one who appears in the file name
     * least (the exported chat is named after the OTHER person).
     *
     * @param  list<array{at: CarbonImmutable, sender: string, body: string}>  $messages
     */
    public static function ownSender(array $messages, ?string $contact, array $ownNames): ?string
    {
        $senders = array_count_values(array_column($messages, 'sender'));
        foreach (array_keys($senders) as $sender) {
            foreach ($ownNames as $own) {
                if ($own !== '' && mb_strtolower(trim($own)) === mb_strtolower($sender)) {
                    return $sender;
                }
            }
        }
        if ($contact !== null && count($senders) === 2) {
            foreach (array_keys($senders) as $sender) {
                if (mb_strtolower($sender) !== mb_strtolower($contact) && ! str_contains(mb_strtolower($contact), mb_strtolower($sender))) {
                    return $sender;
                }
            }
        }

        return null;
    }
}
