<?php

namespace App\Services\Advisor\GoogleAds;

use App\Models\AdvisorItem;
use App\Models\AdvisorPlan;
use Illuminate\Support\Collection;

/**
 * Google Ads Editor import file from advisor recommendations (value loop). MoxDOP writes nothing to Google Ads:
 * the operator downloads the file, imports it in Google Ads Editor (Hesap → İçe aktar → Dosyadan), reviews the
 * pending changes there and posts them himself.
 *
 * The file uses the Editor's own export format — UTF-16LE with BOM, tab separated — so Turkish characters survive
 * and Editor maps the columns by header. Only recommendations whose evidence names the exact campaign / ad group
 * are mapped; everything else is returned as "elle yapılacak" with the reason.
 */
final class GoogleAdsEditorExport
{
    public const array COLUMNS = ['Campaign', 'Campaign Status', 'Budget', 'Ad group', 'Keyword', 'Criterion Type'];

    /** Extra columns of a responsive search ad row (appended only when a row uses them). */
    public const array RSA_COLUMNS = [
        'Ad type', 'Headline 1', 'Headline 2', 'Headline 3', 'Headline 4', 'Headline 5', 'Headline 6', 'Headline 7', 'Headline 8', 'Headline 9',
        'Headline 10', 'Headline 11', 'Headline 12', 'Headline 13', 'Headline 14', 'Headline 15',
        'Description 1', 'Description 2', 'Description 3', 'Description 4', 'Final URL', 'Path 1', 'Path 2',
    ];

    /** Rules this exporter can turn into Editor rows (when the evidence carries campaign names). */
    public const array SUPPORTED_RULES = ['negative-keywords', 'ngram-waste', 'keyword-opportunities', 'budget-limited-profitable', 'budget-waste'];

    /** A budget-limited campaign's daily budget is raised by this factor (the checklist says step by 20–30 %). */
    public const float BUDGET_STEP = 1.2;

    /**
     * @param  Collection<int, AdvisorItem>  $items
     * @return array{rows: list<array<string, string>>, exported: list<int>, manual: list<array{id: int, title: string, reason: string}>}
     */
    public function build(Collection $items): array
    {
        $rows = [];
        $exported = [];
        $manual = [];
        foreach ($items as $item) {
            if ($item->channel !== AdvisorPlan::CHANNEL_GOOGLE_ADS || ! in_array($item->rule_id, self::SUPPORTED_RULES, true)) {
                $manual[] = ['id' => (int) $item->id, 'title' => (string) $item->title, 'reason' => 'Bu öneri türü Editor dosyasına çevrilemiyor; adımları elle uygula.'];

                continue;
            }
            [$itemRows, $skipped] = $this->rowsFor($item);
            if ($itemRows === []) {
                $manual[] = ['id' => (int) $item->id, 'title' => (string) $item->title, 'reason' => 'Kanıtta kampanya / reklam grubu adı yok (eski çalıştırma ya da Performance Max); listeyi elle ekle veya danışmanı yeniden çalıştır.'];

                continue;
            }
            array_push($rows, ...$itemRows);
            $exported[] = (int) $item->id;
            if ($skipped > 0) {
                $manual[] = ['id' => (int) $item->id, 'title' => (string) $item->title, 'reason' => $skipped.' satırın kampanyası bilinmiyor; bunları elle ekle.'];
            }
        }

        return ['rows' => $this->unique($rows), 'exported' => $exported, 'manual' => $manual];
    }

    public static function supports(AdvisorItem $item): bool
    {
        return $item->channel === AdvisorPlan::CHANNEL_GOOGLE_ADS && in_array($item->rule_id, self::SUPPORTED_RULES, true);
    }

    /**
     * The file body: UTF-16LE with BOM, tab separated, CRLF line ends (what Google Ads Editor itself exports).
     *
     * @param  list<array<string, string>>  $rows
     */
    public function file(array $rows): string
    {
        $columns = self::COLUMNS;
        foreach (self::RSA_COLUMNS as $column) {
            foreach ($rows as $row) {
                if (($row[$column] ?? '') !== '') {
                    $columns[] = $column;

                    break;
                }
            }
        }
        $lines = [implode("\t", $columns)];
        foreach ($rows as $row) {
            $lines[] = implode("\t", array_map(fn (string $column): string => $this->cell($row[$column] ?? ''), $columns));
        }

        return "\xFF\xFE".mb_convert_encoding(implode("\r\n", $lines)."\r\n", 'UTF-16LE', 'UTF-8');
    }

    /**
     * A new responsive search ad (paused by Editor until posted) from a validated AI draft (headlines ≤ 30,
     * descriptions ≤ 90). Lines the optional filter refuses (e.g. sector rules) are left out.
     *
     * @param  array<string, mixed>  $draft  {headlines, descriptions, path1, path2}
     * @param  (callable(string): bool)|null  $keep
     * @return array<string, string>
     */
    public static function rsaRow(string $campaign, string $adGroup, array $draft, string $finalUrl, ?callable $keep = null): array
    {
        $keep ??= static fn (string $line): bool => true;
        $headlines = array_slice(array_values(array_filter(array_map('strval', (array) ($draft['headlines'] ?? [])), static fn (string $h): bool => $h !== '' && mb_strlen($h) <= 30 && $keep($h))), 0, 15);
        $descriptions = array_slice(array_values(array_filter(array_map('strval', (array) ($draft['descriptions'] ?? [])), static fn (string $d): bool => $d !== '' && mb_strlen($d) <= 90 && $keep($d))), 0, 4);
        $row = ['Campaign' => $campaign, 'Ad group' => $adGroup, 'Ad type' => 'Responsive search ad', 'Final URL' => $finalUrl,
            'Path 1' => (string) ($draft['path1'] ?? ''), 'Path 2' => (string) ($draft['path2'] ?? '')];
        foreach ($headlines as $i => $headline) {
            $row['Headline '.($i + 1)] = $headline;
        }
        foreach ($descriptions as $i => $description) {
            $row['Description '.($i + 1)] = $description;
        }

        return $row;
    }

    /** @return array{0: list<array<string, string>>, 1: int} rows and how many evidence lines had no campaign */
    private function rowsFor(AdvisorItem $item): array
    {
        $evidence = (array) ($item->evidence ?? []);
        $rows = [];
        $skipped = 0;
        $negative = function (array $list, string $textKey, string $criterion) use (&$rows, &$skipped): void {
            foreach ($list as $entry) {
                $text = trim((string) ($entry[$textKey] ?? ''));
                $campaigns = array_filter((array) ($entry['campaigns'] ?? []), 'is_string');
                if ($text === '') {
                    continue;
                }
                if ($campaigns === []) {
                    $skipped++;

                    continue;
                }
                foreach ($campaigns as $campaign) {
                    $rows[] = ['Campaign' => $campaign, 'Keyword' => $text, 'Criterion Type' => $criterion];
                }
            }
        };

        switch ($item->rule_id) {
            case 'negative-keywords':
                $negative((array) ($evidence['words'] ?? []), 'word', 'Campaign Negative Phrase');
                $negative((array) ($evidence['terms'] ?? []), 'term', 'Campaign Negative Exact');
                break;
            case 'ngram-waste':
                $negative((array) ($evidence['phrases'] ?? []), 'phrase', 'Campaign Negative Phrase');
                break;
            case 'keyword-opportunities':
                foreach ((array) ($evidence['terms'] ?? []) as $term) {
                    $target = $term['target'] ?? null;
                    if (! is_array($target) || blank($target['campaign'] ?? null) || blank($target['ad_group'] ?? null) || blank($term['term'] ?? null)) {
                        $skipped++;

                        continue;
                    }
                    $rows[] = ['Campaign' => (string) $target['campaign'], 'Ad group' => (string) $target['ad_group'], 'Keyword' => (string) $term['term'], 'Criterion Type' => 'Exact'];
                }
                break;
            case 'budget-limited-profitable':
                foreach ((array) ($evidence['campaigns'] ?? []) as $campaign) {
                    if (blank($campaign['name'] ?? null) || ! is_numeric($campaign['daily_budget'] ?? null) || (float) $campaign['daily_budget'] <= 0) {
                        $skipped++;

                        continue;
                    }
                    $rows[] = ['Campaign' => (string) $campaign['name'], 'Budget' => number_format((float) $campaign['daily_budget'] * self::BUDGET_STEP, 2, '.', '')];
                }
                break;
            case 'budget-waste':
                foreach ((array) ($evidence['campaigns'] ?? []) as $campaign) {
                    if (blank($campaign['name'] ?? null)) {
                        $skipped++;

                        continue;
                    }
                    $rows[] = ['Campaign' => (string) $campaign['name'], 'Campaign Status' => 'Paused'];
                }
                break;
        }

        return [$rows, $skipped];
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return list<array<string, string>>
     */
    public function unique(array $rows): array
    {
        $seen = [];
        $out = [];
        foreach ($rows as $row) {
            $key = implode("\0", array_map(static fn (string $c): string => mb_strtolower($row[$c] ?? ''), array_merge(self::COLUMNS, self::RSA_COLUMNS)));
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * Tabs / line breaks would break the row. A leading "=" or "@" (never a valid keyword start) is neutralised for
     * spreadsheet apps; "+" and "-" stay because Editor must match campaign names exactly.
     */
    private function cell(string $value): string
    {
        $value = trim(preg_replace('/[\t\r\n]+/u', ' ', $value) ?? '');

        return preg_match('/^[=@]/u', $value) === 1 ? "'".$value : $value;
    }
}
