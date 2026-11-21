<?php

namespace App\Panel\Support;

use RuntimeException;
use XMLReader;
use ZipArchive;

/** Reads the first sheet of an .xlsx file, or a .csv file, as rows of text. Nothing is evaluated: formulas are ignored. */
final class SheetReader
{
    public const int MAX_ROWS = 5000;

    /**
     * @return list<list<string>> rows, the first one being the head; empty rows are dropped
     */
    public static function read(string $path, string $name): array
    {
        $rows = preg_match('/\.xlsx$/i', $name) === 1 ? self::xlsx($path) : self::csv($path);
        $rows = array_values(array_filter($rows, fn (array $row): bool => trim(implode('', $row)) !== ''));
        if (count($rows) < 2) {
            throw new RuntimeException('Dosyada başlık satırı ve en az bir kayıt olmalı.');
        }
        if (count($rows) > self::MAX_ROWS + 1) {
            throw new RuntimeException('Bir dosyada en çok '.self::MAX_ROWS.' satır olabilir. Listeyi bölüp ayrı ayrı yükleyin.');
        }
        $width = max(array_map('count', $rows));

        return array_map(fn (array $row): array => array_pad(array_map(fn ($cell): string => trim((string) $cell), $row), $width, ''), $rows);
    }

    /** @return list<list<string>> */
    private static function csv(string $path): array
    {
        $text = (string) file_get_contents($path);
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = (string) iconv('CP1254', 'UTF-8//IGNORE', $text);
        }
        $first = (string) strtok($text, "\n");
        $delimiter = collect([';', ',', "\t"])->sortByDesc(fn (string $d): int => substr_count($first, $d))->first();
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $text);
        rewind($handle);
        $rows = [];
        while (($row = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            $rows[] = array_map(fn ($cell): string => (string) $cell, $row);
            if (count($rows) > self::MAX_ROWS + 50) {
                break;
            }
        }
        fclose($handle);

        return $rows;
    }

    /** @return list<list<string>> */
    private static function xlsx(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Excel dosyası açılamadı. Dosyayı .xlsx olarak kaydedip yeniden deneyin.');
        }
        $shared = [];
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml !== false) {
            $reader = new XMLReader;
            $reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT);
            $current = null;
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'si') {
                    $current = '';
                } elseif ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 't' && $current !== null) {
                    $current .= $reader->readString();
                } elseif ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'si') {
                    $shared[] = (string) $current;
                    $current = null;
                }
            }
        }
        $sheet = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string) $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet(\d+)\.xml$#', $entry, $m) === 1 && ($sheet === null || (int) $m[1] < $sheet[0])) {
                $sheet = [(int) $m[1], $entry];
            }
        }
        $xml = $sheet !== null ? $zip->getFromName($sheet[1]) : false;
        $zip->close();
        if ($xml === false) {
            throw new RuntimeException('Excel dosyasında sayfa bulunamadı.');
        }
        $rows = [];
        $reader = new XMLReader;
        $reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT);
        $row = null;
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'row') {
                $row = [];
                if ($reader->isEmptyElement) {
                    $row = null;
                }
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'row' && $row !== null) {
                $filled = $row === [] ? [] : array_replace(array_fill(0, max(array_keys($row)) + 1, ''), $row);
                $rows[] = array_values($filled);
                $row = null;
                if (count($rows) > self::MAX_ROWS + 50) {
                    break;
                }
            } elseif ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'c' && $row !== null) {
                $index = self::column((string) $reader->getAttribute('r'), count($row));
                $type = (string) $reader->getAttribute('t');
                $value = '';
                if (! $reader->isEmptyElement) {
                    $cell = $reader->readOuterXml();
                    if ($type === 'inlineStr') {
                        $value = html_entity_decode(strip_tags(preg_replace('#<rPh.*?</rPh>#s', '', $cell) ?? ''), ENT_QUOTES | ENT_XML1, 'UTF-8');
                    } elseif (preg_match('#<v[^>]*>(.*?)</v>#s', $cell, $m) === 1) {
                        $raw = html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
                        $value = $type === 's' ? (string) ($shared[(int) $raw] ?? '') : $raw;
                    }
                }
                if ($index < 200) {
                    $row[$index] = $value;
                }
            }
        }

        return $rows;
    }

    /** "C7" → 2; a cell without a reference takes the next free column. */
    private static function column(string $ref, int $next): int
    {
        if (preg_match('/^([A-Z]+)\d+$/', $ref, $m) !== 1) {
            return $next;
        }
        $index = 0;
        foreach (str_split($m[1]) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index - 1;
    }
}
