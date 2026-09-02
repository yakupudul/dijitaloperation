<?php

namespace App\Services\SearchDemand;

use Illuminate\Support\Str;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

final class TabularSearchQueryReader
{
    /** @return list<array<string, mixed>> */
    public function read(string $path, string $filename): array
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match ($extension) {
            'csv', 'tsv' => $this->readDelimited($path, $extension === 'tsv' ? "\t" : null),
            'txt' => $this->readText($path),
            'xlsx' => $this->readXlsx($path),
            default => throw new RuntimeException('Desteklenen dosya türleri: CSV, TSV, TXT ve XLSX.'),
        };
    }

    /** @return list<array<string, mixed>> */
    private function readText(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new RuntimeException('Metin dosyası okunamadı.');
        }

        return collect($lines)->map(fn (string $line): array => ['query' => trim($line)])->filter(fn (array $row): bool => $row['query'] !== '')->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function readDelimited(string $path, ?string $forcedDelimiter = null): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Dosya açılamadı.');
        }

        try {
            $sample = fgets($handle) ?: '';
            rewind($handle);
            $delimiter = $forcedDelimiter ?? $this->detectDelimiter($sample);
            $rows = [];
            while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                $rows[] = array_map(fn (mixed $value): string => trim((string) $value), $row);
            }
        } finally {
            fclose($handle);
        }

        return $this->associateRows($rows);
    }

    /** @return list<array<string, mixed>> */
    private function readXlsx(string $path): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('Sunucuda XLSX okuyabilmek için PHP Zip eklentisi etkin olmalıdır.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Excel dosyası açılamadı.');
        }

        try {
            $shared = $this->sharedStrings($zip->getFromName('xl/sharedStrings.xml') ?: null);
            $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
            if (! is_string($sheetXml)) {
                throw new RuntimeException('Excel dosyasının ilk çalışma sayfası bulunamadı.');
            }
            $sheet = simplexml_load_string($sheetXml);
            if (! $sheet instanceof SimpleXMLElement) {
                throw new RuntimeException('Excel çalışma sayfası okunamadı.');
            }

            $sheetNamespaces = $sheet->getNamespaces(true);
            $sheetMain = isset($sheetNamespaces['']) ? $sheet->children($sheetNamespaces['']) : $sheet;
            $rows = [];
            foreach ($sheetMain->sheetData->row as $xmlRow) {
                $rowMain = isset($sheetNamespaces['']) ? $xmlRow->children($sheetNamespaces['']) : $xmlRow;
                $row = [];
                foreach ($rowMain->c as $cell) {
                    $cellMain = isset($sheetNamespaces['']) ? $cell->children($sheetNamespaces['']) : $cell;
                    $reference = (string) $cell['r'];
                    $column = $this->columnIndex($reference);
                    $type = (string) $cell['t'];
                    $value = $type === 'inlineStr'
                        ? (string) $cellMain->is->t
                        : (string) $cellMain->v;
                    if ($type === 's') {
                        $value = $shared[(int) $value] ?? '';
                    }
                    $row[$column] = trim($value);
                }
                if ($row !== []) {
                    ksort($row);
                    $max = max(array_keys($row));
                    $rows[] = array_map(fn (int $index): string => (string) ($row[$index] ?? ''), range(0, $max));
                }
            }
        } finally {
            $zip->close();
        }

        return $this->associateRows($rows);
    }

    /** @return list<string> */
    private function sharedStrings(string|false|null $xml): array
    {
        if (! is_string($xml) || $xml === '') {
            return [];
        }
        $document = simplexml_load_string($xml);
        if (! $document instanceof SimpleXMLElement) {
            return [];
        }

        $namespaces = $document->getNamespaces(true);
        $main = isset($namespaces['']) ? $document->children($namespaces['']) : $document;
        $strings = [];
        foreach ($main->si as $item) {
            $itemMain = isset($namespaces['']) ? $item->children($namespaces['']) : $item;
            if (isset($itemMain->t)) {
                $strings[] = (string) $itemMain->t;
                continue;
            }
            $parts = [];
            foreach ($itemMain->r as $run) {
                $runMain = isset($namespaces['']) ? $run->children($namespaces['']) : $run;
                $parts[] = (string) $runMain->t;
            }
            $strings[] = implode('', $parts);
        }

        return $strings;
    }

    /** @param list<list<string>> $rows @return list<array<string, mixed>> */
    private function associateRows(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $headerRow = array_shift($rows);
        $headers = array_map(fn (string $header): string => $this->header($header), $headerRow);
        $hasQueryHeader = count(array_intersect($headers, ['query', 'search_term', 'keyword', 'sorgu', 'arama_terimi'])) > 0;
        if (! $hasQueryHeader) {
            array_unshift($rows, $headerRow);
            $headers = ['query'];
        }

        $result = [];
        foreach ($rows as $row) {
            $associated = [];
            foreach ($headers as $index => $header) {
                if ($header !== '') {
                    $associated[$header] = $row[$index] ?? null;
                }
            }
            if (collect($associated)->filter(fn ($value): bool => trim((string) $value) !== '')->isNotEmpty()) {
                $result[] = $associated;
            }
        }

        return $result;
    }

    private function header(string $header): string
    {
        $header = mb_strtolower(Str::ascii(trim($header), 'tr'), 'UTF-8');
        $header = preg_replace('/[^a-z0-9]+/', '_', $header) ?? $header;

        return trim($header, '_');
    }

    private function detectDelimiter(string $sample): string
    {
        $scores = [',' => substr_count($sample, ','), ';' => substr_count($sample, ';'), "\t" => substr_count($sample, "\t")];
        arsort($scores);

        return (string) array_key_first($scores);
    }

    private function columnIndex(string $reference): int
    {
        preg_match('/^[A-Z]+/i', $reference, $match);
        $letters = strtoupper($match[0] ?? 'A');
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return max(0, $index - 1);
    }
}
