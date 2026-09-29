<?php

namespace App\Services\Site\Backlinks;

use App\Models\Backlink;
use App\Models\Brand;
use App\Services\Site\SiteDomains;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use RuntimeException;
use Throwable;

/**
 * Search Console "Bağlantılar" export (CSV or XLSX; Top linking sites · Top linking pages · Latest links, Turkish or
 * English headers) → `backlinks` (source gsc_import). One row per brand × (linking page or site) × target page; a
 * re-import keeps the earliest first-seen date. Rows without a linking page / site are skipped.
 */
final class BacklinkImporter
{
    private const int MAX_ROWS = 20000;

    /** @return array{imported: int, updated: int, skipped: int} */
    public function import(Brand $brand, string $path, string $originalName, string $source = 'gsc_import'): array
    {
        $rows = str_ends_with(mb_strtolower($originalName), '.xlsx') ? $this->xlsx($path) : $this->csv($path);
        if ($rows === []) {
            throw new RuntimeException('Dosyada satır yok.');
        }
        $columns = self::columns(array_shift($rows));
        if ($columns['source_url'] === null && $columns['domain'] === null) {
            throw new RuntimeException('Bağlantı veren sayfa / site sütunu bulunamadı.');
        }
        $own = SiteDomains::ownDomains($brand);
        $stats = ['imported' => 0, 'updated' => 0, 'skipped' => 0];
        foreach (array_slice($rows, 0, self::MAX_ROWS) as $row) {
            $link = self::link($row, $columns);
            if ($link === null || SiteDomains::isOwn($link['source_domain'], $own)) {
                $stats['skipped']++;

                continue;
            }
            $hash = hash('sha256', ($link['source_url'] ?? $link['source_domain']).'|'.($link['target_url'] ?? ''));
            $existing = Backlink::query()->where('brand_id', $brand->id)->where('link_hash', $hash)->first();
            if ($existing !== null) {
                $first = $existing->first_seen;
                if ($link['first_seen'] !== null && ($first === null || $link['first_seen']->lessThan($first))) {
                    $existing->first_seen = $link['first_seen'];
                }
                $existing->status = 'aktif';
                $existing->save();
                $stats['updated']++;

                continue;
            }
            Backlink::query()->create($link + ['brand_id' => $brand->id, 'link_hash' => $hash, 'source' => $source, 'status' => 'aktif']);
            $stats['imported']++;
        }

        return $stats;
    }

    /**
     * Column indexes by header (folded, case-insensitive).
     *
     * @param  list<string>  $header
     * @return array{source_url: ?int, domain: ?int, target_url: ?int, date: ?int}
     */
    public static function columns(array $header): array
    {
        $out = ['source_url' => null, 'domain' => null, 'target_url' => null, 'date' => null];
        foreach ($header as $i => $label) {
            $key = trim(Str::ascii(mb_strtolower(str_replace(['İ', 'I'], ['i', 'ı'], trim((string) $label, " \t\n\r\0\x0B\u{FEFF}\"")))));
            match (true) {
                in_array($key, ['linking page', 'linking pages url', 'baglanti veren sayfa', 'baglanti kuran sayfa', 'source url'], true) => $out['source_url'] ??= $i,
                in_array($key, ['site', 'linking site', 'baglanti veren site', 'baglanti kuran site', 'domain', 'alan adi'], true) => $out['domain'] ??= $i,
                in_array($key, ['target page', 'hedef sayfa', 'target url'], true) => $out['target_url'] ??= $i,
                str_contains($key, 'crawl') || str_contains($key, 'tarama') || str_contains($key, 'first seen') || str_contains($key, 'ilk gorul') => $out['date'] ??= $i,
                default => null,
            };
        }

        return $out;
    }

    /**
     * @param  list<string>  $row
     * @param  array{source_url: ?int, domain: ?int, target_url: ?int, date: ?int}  $columns
     * @return array{source_url: ?string, source_domain: string, target_url: ?string, first_seen: ?CarbonImmutable}|null
     */
    private static function link(array $row, array $columns): ?array
    {
        $url = $columns['source_url'] !== null ? trim((string) ($row[$columns['source_url']] ?? '')) : '';
        $url = preg_match('#^https?://#i', $url) === 1 ? mb_substr($url, 0, 2000) : null;
        $domain = SiteDomains::host($url) ?? ($columns['domain'] !== null ? SiteDomains::host(trim((string) ($row[$columns['domain']] ?? ''))) : null);
        if ($domain === null || preg_match('/\s/', $domain) === 1) {
            return null;
        }
        $target = $columns['target_url'] !== null ? trim((string) ($row[$columns['target_url']] ?? '')) : '';

        return [
            'source_url' => $url,
            'source_domain' => $domain,
            'target_url' => preg_match('#^https?://#i', $target) === 1 ? mb_substr($target, 0, 2000) : null,
            'first_seen' => $columns['date'] !== null ? self::date((string) ($row[$columns['date']] ?? '')) : null,
        ];
    }

    private static function date(string $value): ?CarbonImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        foreach (['Y-m-d', 'd.m.Y', 'd/m/Y', 'M j, Y', 'j M Y'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat('!'.$format, $value);
                if ($date !== false && $date->year > 2000) {
                    return $date;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }

    /** @return list<list<string>> */
    private function csv(string $path): array
    {
        $content = (string) file_get_contents($path);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'UTF-16LE,Windows-1254,ISO-8859-9');
        }
        $lines = preg_split('/\r\n|\n|\r/', $content) ?: [];
        $first = (string) ($lines[0] ?? '');
        $delimiter = collect([',', ';', "\t"])->sortByDesc(fn (string $d): int => substr_count($first, $d))->first();
        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) !== '') {
                $rows[] = array_map(fn ($v): string => (string) $v, str_getcsv($line, $delimiter, '"', ''));
            }
        }

        return $rows;
    }

    /** @return list<list<string>> */
    private function xlsx(string $path): array
    {
        if (! class_exists(XlsxReader::class)) {
            throw new RuntimeException('XLSX okunamıyor; CSV olarak dışa aktarın.');
        }
        $reader = new XlsxReader;
        $reader->open($path);
        $rows = [];
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = array_map(fn ($cell): string => $cell instanceof \DateTimeInterface ? $cell->format('Y-m-d') : (string) $cell, $row->toArray());
                    if (count($rows) > self::MAX_ROWS) {
                        break 2;
                    }
                }
                break; // first sheet only
            }
        } finally {
            $reader->close();
        }

        return $rows;
    }
}
