<?php

namespace App\Services\Analyst\Meta;

use App\Models\Brand;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Meta change plan as a CSV file (UTF-8 with BOM, ";" separated for Turkish Excel): one row per open Meta card
 * — what to pause / exclude / move / fix, where (account, level, name, Meta id), why and the numbers. MoxDOP never
 * writes to Meta; the operator applies the plan in Ads Manager.
 */
final class MetaPlanExport
{
    public const array COLUMNS = ['Öncelik', 'Hesap', 'Düzey', 'Ad', 'Kimlik', 'Yapılacak', 'Neden', 'Veri', 'Kaynak'];

    /** @param  list<array<string, string>>  $rows */
    public function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, self::COLUMNS, ';', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn (string $column): string => self::safe((string) ($row[$column] ?? '')), self::COLUMNS), ';', '"', '');
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /** @param  list<array<string, string>>  $rows */
    public function download(Brand $brand, array $rows): StreamedResponse
    {
        $csv = $this->csv($rows);

        return response()->streamDownload(function () use ($csv): void {
            echo $csv;
        }, self::filename($brand), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public static function filename(Brand $brand): string
    {
        return 'meta-plan-'.(Str::slug((string) $brand->name) ?: 'marka').'-'.now()->format('Y-m-d').'.csv';
    }

    /** Spreadsheet formula injection guard. */
    private static function safe(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
