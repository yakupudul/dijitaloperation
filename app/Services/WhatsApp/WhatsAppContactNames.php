<?php

namespace App\Services\WhatsApp;

use App\Models\CoreIntegration;
use App\Models\WhatsAppConversation;

/**
 * Names for the numbers: the phone backup has no contact names (WhatsApp keeps them outside the backup), so the
 * operator uploads the phone's address book as vCard (.vcf) and the names of matching numbers are written on the
 * conversations. Only names and phone numbers are read; nothing else from the address book is kept.
 */
final class WhatsAppContactNames
{
    public const MAX_NAME = 120;

    /**
     * @return array{contacts: int, matched: int}
     */
    public function apply(CoreIntegration $integration, string $vcard): array
    {
        $names = $this->parse($vcard);
        $matched = 0;
        foreach (array_chunk(array_keys($names), 500) as $numbers) {
            $rows = WhatsAppConversation::query()->where('integration_id', $integration->id)
                ->whereIn('contact_id', array_map('strval', $numbers))->get(['id', 'contact_id', 'contact_name']);
            foreach ($rows as $row) {
                $name = $names[$row->contact_id];
                if ($row->contact_name !== $name) {
                    $row->update(['contact_name' => $name]);
                }
                $matched++;
            }
        }

        return ['contacts' => count($names), 'matched' => $matched];
    }

    /**
     * Number (international digits, as WhatsApp stores them) => name.
     *
     * @return array<string, string>
     */
    public function parse(string $vcard): array
    {
        // Unfold: a line starting with a space or tab continues the previous one; "=" ends a quoted-printable line.
        $text = (string) preg_replace("/\r\n|\r/", "\n", $vcard);
        $text = (string) preg_replace("/\n[ \t]/", '', $text);
        $text = (string) preg_replace("/=\n/", '', $text);
        $names = [];
        foreach (preg_split('/^BEGIN:VCARD\s*$/mi', $text) ?: [] as $card) {
            $name = null;
            $fallback = null;
            $phones = [];
            foreach (explode("\n", $card) as $line) {
                if (! str_contains($line, ':')) {
                    continue;
                }
                [$head, $value] = explode(':', $line, 2);
                $parts = explode(';', $head);
                $property = strtoupper((string) preg_replace('/^item\d+\./i', '', $parts[0]));
                $value = $this->decode($parts, trim($value));
                if ($property === 'FN' && $value !== '') {
                    $name = $value;
                } elseif ($property === 'N' && $value !== '') {
                    $pieces = array_map('trim', explode(';', $value));
                    $fallback = trim(implode(' ', array_filter([$pieces[3] ?? '', $pieces[1] ?? '', $pieces[2] ?? '', $pieces[0] ?? ''])));
                } elseif ($property === 'TEL' && ($number = self::number($value)) !== null) {
                    $phones[] = $number;
                }
            }
            $name = trim((string) ($name ?: $fallback));
            if ($name === '') {
                continue;
            }
            foreach ($phones as $number) {
                $names[$number] ??= mb_substr($name, 0, self::MAX_NAME);
            }
        }

        return $names;
    }

    /** A phone number as WhatsApp keeps it (country code + number, digits only); Turkish local forms get 90. */
    public static function number(string $value): ?string
    {
        $digits = (string) preg_replace('/\D+/', '', $value);
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        } elseif (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = '9'.$digits;
        } elseif (strlen($digits) === 10 && str_starts_with($digits, '5')) {
            $digits = '90'.$digits;
        }

        return strlen($digits) >= 8 && strlen($digits) <= 15 ? $digits : null;
    }

    /** @param  list<string>  $parameters */
    private function decode(array $parameters, string $value): string
    {
        $upper = strtoupper(implode(';', $parameters));
        if (str_contains($upper, 'QUOTED-PRINTABLE')) {
            $value = quoted_printable_decode($value);
        }
        $value = str_replace(['\\,', '\;', '\\n', '\\N'], [',', ';', ' ', ' '], $value);
        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1254');
        }

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
