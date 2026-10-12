<?php

namespace App\Services\Strategy;

use App\Models\Strategy;
use App\Services\GoogleAds\GoogleAdsEditorCsv;
use ZipArchive;

/**
 * Google Ads Editor import file from a strategy's structured plan: campaigns (paused, with daily budget, bid
 * strategy, max CPC, languages), locations with bid modifiers, campaign negatives (shared lists flattened onto the
 * campaigns they apply to), ad groups, keywords, ad group negatives and responsive search ads. Settings the import
 * cannot carry (location option, exclusions, schedule, age) are written as notes beside the rows. The operator imports
 * it in Google Ads Editor (Hesap › İçe aktar › Dosyadan) and posts after review; MoxDOP writes nothing to the account.
 */
final class StrategyAdsEditorExport
{
    private const array MATCH = ['exact' => 'Exact', 'phrase' => 'Phrase', 'broad' => 'Broad'];

    /** @return list<string> */
    public static function header(): array
    {
        $header = ['Campaign', 'Campaign Type', 'Networks', 'Campaign Daily Budget', 'Bid Strategy Type', 'Languages', 'Campaign Status',
            'Location', 'Bid Modifier', 'Ad Group', 'Ad Group Status', 'Max CPC', 'Keyword', 'Criterion Type', 'Ad type'];
        for ($i = 1; $i <= GoogleAdsEditorCsv::HEADLINES; $i++) {
            $header[] = 'Headline '.$i;
        }
        for ($i = 1; $i <= GoogleAdsEditorCsv::DESCRIPTIONS; $i++) {
            $header[] = 'Description '.$i;
        }

        return [...$header, 'Path 1', 'Path 2', 'Final URL', 'Status', 'Comment'];
    }

    /**
     * @return list<array<string, string>>
     */
    public static function rows(Strategy $strategy): array
    {
        $plan = (array) ($strategy->plan ?? []);
        $rows = [];
        $sharedByCampaign = [];
        foreach ((array) ($plan['shared_negative_lists'] ?? []) as $list) {
            foreach ((array) ($list['campaigns'] ?? []) as $campaignName) {
                foreach ((array) ($list['keywords'] ?? []) as $kw) {
                    $sharedByCampaign[$campaignName][] = $kw + ['list' => (string) ($list['name'] ?? '')];
                }
            }
        }
        foreach ((array) ($plan['campaigns'] ?? []) as $c) {
            $name = (string) $c['name'];
            $comment = array_filter([
                ! empty($c['excluded_locations']) ? 'Hariç konum: '.implode(', ', (array) $c['excluded_locations']) : null,
                ! empty($c['ad_schedule']) ? 'Reklam zamanlaması: '.$c['ad_schedule'] : null,
                ! empty($c['age']) ? 'Yaş: '.$c['age'] : null,
                $c['note'] ?? null,
            ]);
            $rows[] = ['Campaign' => $name, 'Campaign Type' => 'Search', 'Networks' => 'Google search',
                'Campaign Daily Budget' => isset($c['daily_budget']) ? number_format((float) $c['daily_budget'], 2, '.', '') : '',
                'Bid Strategy Type' => (string) ($c['bid_strategy'] ?? ''), 'Languages' => implode(';', (array) ($c['languages'] ?? [])),
                'Campaign Status' => 'Paused', 'Comment' => implode(' · ', $comment)];
            $bids = [];
            foreach ((array) ($c['location_bids'] ?? []) as $bid) {
                $bids[(string) $bid['location']] = (int) $bid['modifier'];
            }
            foreach ((array) ($c['locations'] ?? []) as $location) {
                $rows[] = ['Campaign' => $name, 'Location' => (string) $location, 'Bid Modifier' => self::modifier($bids[$location] ?? null)];
                unset($bids[$location]);
            }
            foreach ($bids as $location => $modifier) {
                $rows[] = ['Campaign' => $name, 'Location' => $location, 'Bid Modifier' => self::modifier($modifier)];
            }
            foreach ([...(array) ($c['negatives'] ?? []), ...($sharedByCampaign[$name] ?? [])] as $kw) {
                $rows[] = ['Campaign' => $name, 'Keyword' => (string) $kw['text'], 'Criterion Type' => 'Campaign Negative '.self::match($kw),
                    'Comment' => isset($kw['list']) ? 'Liste: '.$kw['list'] : ''];
            }
            foreach ((array) ($c['ad_groups'] ?? []) as $g) {
                $group = (string) $g['name'];
                $rows[] = ['Campaign' => $name, 'Ad Group' => $group, 'Ad Group Status' => 'Enabled',
                    'Max CPC' => isset($g['max_cpc']) ? number_format((float) $g['max_cpc'], 2, '.', '') : (isset($c['max_cpc']) ? number_format((float) $c['max_cpc'], 2, '.', '') : '')];
                foreach ((array) ($g['keywords'] ?? []) as $kw) {
                    $rows[] = ['Campaign' => $name, 'Ad Group' => $group, 'Keyword' => (string) $kw['text'], 'Criterion Type' => self::match($kw),
                        'Final URL' => (string) ($g['final_url'] ?? '')];
                }
                foreach ((array) ($g['negatives'] ?? []) as $kw) {
                    $rows[] = ['Campaign' => $name, 'Ad Group' => $group, 'Keyword' => (string) $kw['text'], 'Criterion Type' => 'Negative '.self::match($kw)];
                }
                foreach ((array) ($g['ads'] ?? []) as $ad) {
                    $row = ['Campaign' => $name, 'Ad Group' => $group, 'Ad type' => 'Responsive search ad', 'Status' => 'Enabled',
                        'Path 1' => (string) ($ad['path1'] ?? ''), 'Path 2' => (string) ($ad['path2'] ?? ''),
                        'Final URL' => (string) ($ad['final_url'] ?? $g['final_url'] ?? '')];
                    foreach (array_values((array) $ad['headlines']) as $i => $text) {
                        $row['Headline '.($i + 1)] = (string) $text;
                    }
                    foreach (array_values((array) $ad['descriptions']) as $i => $text) {
                        $row['Description '.($i + 1)] = (string) $text;
                    }
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    /** @return array{campaigns: int, ad_groups: int, keywords: int, negatives: int, ads: int} */
    public static function counts(Strategy $strategy): array
    {
        $rows = self::rows($strategy);
        $count = fn (callable $test): int => count(array_filter($rows, $test));

        return [
            'campaigns' => $count(fn (array $r): bool => isset($r['Campaign Type'])),
            'ad_groups' => $count(fn (array $r): bool => isset($r['Ad Group Status'])),
            'keywords' => $count(fn (array $r): bool => isset($r['Criterion Type']) && ! str_contains($r['Criterion Type'], 'Negative')),
            'negatives' => $count(fn (array $r): bool => isset($r['Criterion Type']) && str_contains($r['Criterion Type'], 'Negative')),
            'ads' => $count(fn (array $r): bool => isset($r['Ad type'])),
        ];
    }

    /** UTF-8 CSV with BOM, the Editor's import format. */
    public static function csv(Strategy $strategy): string
    {
        $header = self::header();
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $header, ',', '"', '');
        foreach (self::rows($strategy) as $row) {
            fputcsv($out, array_map(fn (string $column): string => $row[$column] ?? '', $header), ',', '"', '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /**
     * The same rows as an Excel workbook (sheet "Ads Editor", to paste into the Editor or save as CSV) plus a "Notlar"
     * sheet with what the import cannot carry. Written as plain OOXML with inline strings; no library needed.
     */
    public static function xlsx(Strategy $strategy): string
    {
        $header = self::header();
        $data = [$header];
        foreach (self::rows($strategy) as $row) {
            $data[] = array_map(fn (string $column): string => $row[$column] ?? '', $header);
        }
        $notes = [['Not']];
        foreach ((array) ($strategy->plan['notes'] ?? []) as $note) {
            $notes[] = [(string) $note];
        }
        foreach ((array) ($strategy->plan['campaigns'] ?? []) as $c) {
            foreach (['excluded_locations' => 'Hariç konum', 'ad_schedule' => 'Reklam zamanlaması', 'age' => 'Yaş', 'note' => 'Not'] as $key => $label) {
                if (! empty($c[$key])) {
                    $notes[] = [$c['name'].' · '.$label.': '.(is_array($c[$key]) ? implode(', ', $c[$key]) : $c[$key])];
                }
            }
        }
        if (count($notes) === 1) {
            $notes[] = ['Ek ayar yok.'];
        }

        $path = tempnam(sys_get_temp_dir(), 'ads-editor-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Ads Editor" sheetId="1" r:id="rId1"/><sheet name="Notlar" sheetId="2" r:id="rId2"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', self::sheet($data));
        $zip->addFromString('xl/worksheets/sheet2.xml', self::sheet($notes));
        $zip->close();
        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    /** @param  list<list<string>>  $rows */
    private static function sheet(array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($rows as $r => $cells) {
            $xml .= '<row r="'.($r + 1).'">';
            foreach ($cells as $c => $value) {
                if ($value === '') {
                    continue;
                }
                $xml .= '<c r="'.self::column($c).($r + 1).'" t="inlineStr"><is><t xml:space="preserve">'.htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></is></c>';
            }
            $xml .= '</row>';
        }

        return $xml.'</sheetData></worksheet>';
    }

    private static function column(int $index): string
    {
        $name = '';
        for ($i = $index + 1; $i > 0; $i = intdiv($i - 1, 26)) {
            $name = chr(65 + (($i - 1) % 26)).$name;
        }

        return $name;
    }

    /** @param  array<string, mixed>  $kw */
    private static function match(array $kw): string
    {
        return self::MATCH[strtolower((string) ($kw['match'] ?? 'phrase'))] ?? 'Phrase';
    }

    private static function modifier(?int $modifier): string
    {
        if ($modifier === null || $modifier === 0) {
            return '';
        }

        return ($modifier > 0 ? '+' : '').$modifier.'%';
    }
}
