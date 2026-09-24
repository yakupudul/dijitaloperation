<?php

namespace App\Services\GoogleAds;

use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\GoogleAdsAuctionInsight;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Faz 14g: reads a Google Ads "Auction insights" report downloaded as CSV (Turkish or English headers, comma,
 * semicolon or tab separated, UTF-8 or UTF-16) and stores one row per domain. Report title lines before the
 * header are skipped. The Google Ads API has no auction insights, so this upload is the only source.
 */
final class AuctionInsightsImporter
{
    public const METRICS = ['impression_share', 'overlap_rate', 'position_above_rate', 'top_of_page_rate', 'abs_top_rate', 'outranking_share'];

    /**
     * Header matching, checked in this order (a header that contains the phrase wins; "abs. top of page"
     * before "top of page").
     *
     * @var array<string, list<string>>
     */
    private const HEADERS = [
        'domain' => ['display url domain', 'gorunen url alani', 'gorunen url alan adi', 'alan adi', 'domain'],
        'abs_top_rate' => ['abs top of page rate', 'absolute top of page rate', 'en ust', 'mutlak ust'],
        'top_of_page_rate' => ['top of page rate', 'sayfanin ust', 'ust kisim', 'sayfa ustu'],
        'position_above_rate' => ['position above rate', 'ust konum', 'yuksek konum', 'ustte konum'],
        'overlap_rate' => ['overlap rate', 'cakisma'],
        'outranking_share' => ['outranking share', 'geride birakma', 'ustte cikma', 'onde olma'],
        'impression_share' => ['impression share', 'impr share', 'gosterim payi'],
    ];

    /** @var list<string> */
    private const OWN = ['you', 'siz', 'sizin', 'sizin hesabiniz'];

    /** @var list<string> */
    private const TOTALS = ['total', 'toplam'];

    /**
     * @return list<array{domain: string, is_own: bool, below_threshold: list<string>}&array<string, float|null>>
     */
    public function parse(string $content): array
    {
        $text = $this->utf8($content);
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $header = null;
        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            if ($header === null) {
                $delimiter = $this->delimiter($line);
                $columns = $this->columns(str_getcsv($line, $delimiter, '"', ''));
                if (isset($columns['domain']) && count($columns) >= 2) {
                    $header = ['delimiter' => $delimiter, 'columns' => $columns];
                }

                continue;
            }
            $cells = str_getcsv($line, $header['delimiter'], '"', '');
            $domain = trim((string) ($cells[$header['columns']['domain']] ?? ''));
            $folded = self::fold($domain);
            if ($domain === '' || in_array($folded, self::TOTALS, true)) {
                continue;
            }
            $row = ['domain' => mb_strtolower($domain), 'is_own' => in_array($folded, self::OWN, true), 'below_threshold' => []];
            foreach (self::METRICS as $metric) {
                $row[$metric] = null;
                if (isset($header['columns'][$metric])) {
                    [$value, $below] = $this->percent((string) ($cells[$header['columns'][$metric]] ?? ''));
                    $row[$metric] = $value;
                    if ($below) {
                        $row['below_threshold'][] = $metric;
                    }
                }
            }
            $rows[] = $row;
            if (count($rows) >= 500) {
                break;
            }
        }
        if ($header === null) {
            throw new InvalidArgumentException('Dosyada Açık artırma analizi başlığı bulunamadı ("Görünen URL alanı" / "Display URL domain" sütunu olmalı).');
        }
        if ($rows === []) {
            throw new InvalidArgumentException('Dosyada rakip satırı yok.');
        }

        return $rows;
    }

    /** Stores one upload; an upload for the same account and period replaces the earlier one. */
    public function import(DigitalAsset $asset, string $content, ?string $periodStart, ?string $periodEnd, ?User $by): int
    {
        abort_unless($asset->type === 'google_ads', 422);
        $rows = $this->parse($content);
        $uploadId = (string) Str::uuid();

        DB::transaction(function () use ($asset, $rows, $uploadId, $periodStart, $periodEnd, $by): void {
            if ($periodStart !== null && $periodEnd !== null) {
                GoogleAdsAuctionInsight::query()->where('digital_asset_id', $asset->id)
                    ->whereDate('period_start', $periodStart)->whereDate('period_end', $periodEnd)->delete();
            }
            foreach ($rows as $row) {
                GoogleAdsAuctionInsight::query()->create($row + [
                    'digital_asset_id' => $asset->id, 'upload_id' => $uploadId, 'period_start' => $periodStart, 'period_end' => $periodEnd,
                    'uploaded_by' => $by?->id,
                ]);
            }
        });

        return count($rows);
    }

    /**
     * The latest upload of an account, with each domain's change against the upload before it.
     *
     * @return array{upload: ?array{id: string, period_start: ?string, period_end: ?string, uploaded_at: string}, rows: list<array<string, mixed>>, previous: ?array{period_start: ?string, period_end: ?string, uploaded_at: string}}
     */
    public function latest(DigitalAsset $asset): array
    {
        $uploads = GoogleAdsAuctionInsight::query()->where('digital_asset_id', $asset->id)
            ->selectRaw('upload_id, min(period_start) as period_start, min(period_end) as period_end, max(created_at) as uploaded_at')
            ->groupBy('upload_id')->orderByDesc('uploaded_at')->limit(2)->get();
        if ($uploads->isEmpty()) {
            return ['upload' => null, 'rows' => [], 'previous' => null];
        }
        $current = $uploads[0];
        $previous = $uploads[1] ?? null;
        $before = $previous !== null ? GoogleAdsAuctionInsight::query()->where('upload_id', $previous->upload_id)->get()->keyBy('domain') : collect();
        $rows = GoogleAdsAuctionInsight::query()->where('upload_id', $current->upload_id)->get()
            ->sortByDesc(fn (GoogleAdsAuctionInsight $row): string => ($row->is_own ? '1' : '0').sprintf('%08.4f', (float) $row->impression_share))
            ->map(function (GoogleAdsAuctionInsight $row) use ($before): array {
                $old = $before->get($row->domain);

                return [
                    'domain' => $row->domain, 'is_own' => $row->is_own, 'below_threshold' => (array) $row->below_threshold,
                    'impression_share_change' => $old !== null && $row->impression_share !== null && $old->impression_share !== null ? round($row->impression_share - $old->impression_share, 4) : null,
                    'is_new' => $before->isNotEmpty() && $old === null,
                ] + collect(self::METRICS)->mapWithKeys(fn (string $m): array => [$m => $row->{$m}])->all();
            })->values()->all();
        $date = static fn ($value): ?string => $value !== null ? substr((string) $value, 0, 10) : null;

        return [
            'upload' => ['id' => (string) $current->upload_id, 'period_start' => $date($current->period_start), 'period_end' => $date($current->period_end), 'uploaded_at' => (string) $current->uploaded_at],
            'rows' => $rows,
            'previous' => $previous !== null ? ['period_start' => $date($previous->period_start), 'period_end' => $date($previous->period_end), 'uploaded_at' => (string) $previous->uploaded_at] : null,
        ];
    }

    /**
     * The brand's Google Ads accounts with their latest upload (for the competitor screen).
     *
     * @return list<array{asset_id: int, asset_name: string, latest: array<string, mixed>}>
     */
    public function forBrand(Brand $brand): array
    {
        return DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'google_ads')->orderBy('name')->get()
            ->map(fn (DigitalAsset $asset): array => ['asset_id' => (int) $asset->id, 'asset_name' => (string) $asset->name, 'latest' => $this->latest($asset)])
            ->values()->all();
    }

    public static function fold(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = strtr($text, ['ı' => 'i', 'ğ' => 'g', 'ü' => 'u', 'ş' => 's', 'ö' => 'o', 'ç' => 'c', 'i̇' => 'i']);

        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9 ]+/', ' ', $text)));
    }

    /**
     * @param  list<string>  $cells
     * @return array<string, int>
     */
    private function columns(array $cells): array
    {
        $folded = array_map(static fn (string $cell): string => self::fold($cell), $cells);
        $columns = [];
        foreach (self::HEADERS as $key => $phrases) {
            foreach ($folded as $index => $cell) {
                if ($cell === '' || in_array($index, $columns, true)) {
                    continue;
                }
                foreach ($phrases as $phrase) {
                    if ($cell === $phrase || str_contains($cell, $phrase)) {
                        $columns[$key] = $index;

                        continue 3;
                    }
                }
            }
        }

        return $columns;
    }

    /** @return array{0: ?float, 1: bool} value as a share (0–1) and whether Google showed "< 10%" */
    private function percent(string $raw): array
    {
        $value = trim(str_replace(["\u{00a0}", ' '], '', $raw));
        if ($value === '' || $value === '--' || $value === '-') {
            return [null, false];
        }
        $below = str_starts_with($value, '<');
        $number = str_replace(['<', '%', '>'], '', $value);
        if (str_contains($number, ',') && ! str_contains($number, '.')) {
            $number = str_replace(',', '.', $number);
        }
        if (! is_numeric($number)) {
            return [null, false];
        }

        return [round(min(100, max(0, (float) $number)) / 100, 4), $below];
    }

    private function delimiter(string $line): string
    {
        $counts = ["\t" => substr_count($line, "\t"), ';' => substr_count($line, ';'), ',' => substr_count($line, ',')];
        arsort($counts);

        return (string) array_key_first($counts);
    }

    private function utf8(string $content): string
    {
        if (str_starts_with($content, "\xFF\xFE")) {
            return (string) mb_convert_encoding(substr($content, 2), 'UTF-8', 'UTF-16LE');
        }
        if (str_starts_with($content, "\xFE\xFF")) {
            return (string) mb_convert_encoding(substr($content, 2), 'UTF-8', 'UTF-16BE');
        }
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        if (substr_count(substr($content, 0, 200), "\0") > 20) {
            return (string) mb_convert_encoding($content, 'UTF-8', 'UTF-16LE');
        }

        return mb_check_encoding($content, 'UTF-8') ? $content : (string) mb_convert_encoding($content, 'UTF-8', 'Windows-1254');
    }
}
