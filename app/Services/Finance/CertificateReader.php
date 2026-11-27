<?php

namespace App\Services\Finance;

use App\Support\Finance\TaxNumber;
use Smalot\PdfParser\Parser as PdfParser;
use Throwable;

/**
 * Vergi levhası, read by rule (no AI): the e-levha PDF the GİB gives carries its words as text, so the unvan, the
 * vergi dairesi and the vergi / TC kimlik no can be lifted from it and written into the empty fields. A number is
 * taken only when its check digit is right; a photo or a scan gives nothing and the operator types the fields.
 */
final class CertificateReader
{
    /** @return array{title: ?string, tax_office: ?string, tax_no: ?string} */
    public function read(string $bytes, string $mime = 'application/pdf'): array
    {
        $empty = ['title' => null, 'tax_office' => null, 'tax_no' => null];
        if (! str_contains($mime, 'pdf') && ! str_starts_with($bytes, '%PDF')) {
            return $empty;
        }
        try {
            $text = (new PdfParser)->parseContent($bytes)->getText();
        } catch (Throwable) {
            return $empty;
        }

        return $this->fromText($text);
    }

    /** @return array{title: ?string, tax_office: ?string, tax_no: ?string} */
    public function fromText(string $text): array
    {
        $lines = array_values(array_filter(array_map(fn (string $l): string => trim((string) preg_replace('/\s+/u', ' ', $l)), preg_split('/\R/u', $text) ?: [])));
        $upper = fn (string $s): string => mb_strtoupper(str_replace(['i', 'ı'], ['İ', 'I'], $s));

        // The vergi no: a 10-digit number with a right check digit (a TC kimlik no for a şahıs firm when no VKN is there).
        preg_match_all('/(?<!\d)(\d{10,11})(?!\d)/', (string) preg_replace('/(\d)\s(?=\d)/', '$1', $text), $m);
        $numbers = array_values(array_unique($m[1] ?? []));
        $taxNo = collect($numbers)->first(fn (string $n): bool => strlen($n) === 10 && TaxNumber::valid($n))
            ?? collect($numbers)->first(fn (string $n): bool => strlen($n) === 11 && TaxNumber::valid($n));

        $after = function (array $labels) use ($lines, $upper): ?string {
            foreach ($lines as $i => $line) {
                $u = $upper($line);
                foreach ($labels as $label) {
                    $at = mb_strpos($u, $label);
                    if ($at === false) {
                        continue;
                    }
                    $rest = trim(mb_substr($line, $at + mb_strlen($label)), " :\t-");
                    $value = $rest !== '' ? $rest : ($lines[$i + 1] ?? '');
                    $value = trim((string) preg_replace('/\b(VERGİ|VERGI)\s*(KİMLİK|KIMLIK|DAİRESİ|DAIRESI).*$/iu', '', $value), " :\t-");
                    if ($value !== '' && preg_match('/\d{6,}/', $value) !== 1 && mb_strlen($value) >= 3) {
                        return mb_substr($value, 0, 180);
                    }
                }
            }

            return null;
        };

        $office = $after(['VERGİ DAİRESİ', 'VERGI DAIRESI']);
        if ($office !== null) {
            $office = trim((string) preg_replace('/\s*(V\.?\s?D\.?|VERGİ DAİRESİ( MÜDÜRLÜĞÜ)?)$/iu', '', $office));
        }

        return [
            'title' => $after(['TİCARET ÜNVANI', 'TICARET UNVANI', 'ADI SOYADI']),
            'tax_office' => $office !== '' ? $office : null,
            'tax_no' => $taxNo,
        ];
    }
}
