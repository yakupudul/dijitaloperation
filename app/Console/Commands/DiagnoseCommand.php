<?php

namespace App\Console\Commands;

use App\Services\Operations\Diagnostics\DiagnosticMasker;
use App\Services\Operations\Diagnostics\PortfolioDiagnostics;
use Illuminate\Console\Command;

/**
 * moxdop:diagnose — read-only diagnosis the operator runs on the production server and pastes to the developer:
 * environment, ownership / bindings, integrations, data collection freshness, websites, alerts,
 * AI and application errors. Only SELECT queries; no job, provider call, cache or database write. Secrets, e-mail
 * addresses and phone numbers are masked; account ids show only their last four characters.
 */
final class DiagnoseCommand extends Command
{
    protected $signature = 'moxdop:diagnose
        {--brand= : Marka id veya adının bir parçası (ör. Panorama); eşleşen markalar için ayrıntılı bölümler}
        {--asset= : Tek varlık id}
        {--days=14 : Hata / çalıştırma penceresi (gün)}
        {--format=text : text veya json}
        {--section= : Yalnız bu bölümler (virgülle): environment,ownership,integrations,collection,website,advisors,ai,errors}';

    protected $description = 'Salt okunur tanı raporu: veri toplama, bağlama, ekran hatalarını bulmak için yapıştırılacak çıktı.';

    public function handle(PortfolioDiagnostics $diagnostics): int
    {
        $format = strtolower((string) $this->option('format'));
        if (! in_array($format, ['text', 'json'], true)) {
            $this->error('--format text veya json olmalı.');

            return self::INVALID;
        }
        $sections = array_values(array_filter(array_map('trim', explode(',', strtolower((string) $this->option('section'))))));
        $unknown = array_diff($sections, array_keys(PortfolioDiagnostics::SECTIONS));
        if ($unknown !== []) {
            $this->error('Bilinmeyen bölüm: '.implode(', ', $unknown).'. Geçerli: '.implode(',', array_keys(PortfolioDiagnostics::SECTIONS)));

            return self::INVALID;
        }

        $report = $diagnostics->run([
            'brand' => $this->option('brand') !== null ? (string) $this->option('brand') : null,
            'asset' => $this->option('asset') !== null ? (string) $this->option('asset') : null,
            'days' => (int) $this->option('days'),
            'sections' => $sections,
        ]);

        if ($format === 'json') {
            $this->line((string) json_encode(DiagnosticMasker::walk($report), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));

            return self::SUCCESS;
        }

        $scope = $report['scope'];
        $scopeText = match ($scope['mode']) {
            'asset' => 'varlık #'.$scope['asset_id'],
            'brand' => 'marka '.implode(', ', array_map(fn (array $b): string => '#'.$b['id'].' '.$b['name'], $scope['brands'])),
            default => 'tüm portföy',
        };
        $this->out(sprintf('MoxDOP tanı raporu · %s · kapsam: %s · pencere %d gün', $report['generated_at'], $scopeText, $scope['days']));
        if (($scope['note'] ?? null) !== null) {
            $this->out('!! '.$scope['note']);
        }
        foreach ($report['sections'] as $key => $section) {
            $this->out('');
            $this->out(sprintf('== %s [%s] (%d ms) ==', $section['title'], $key, $section['ms']));
            foreach ($section['lines'] as $line) {
                $this->out($line);
            }
        }
        $this->out('');
        $this->out(sprintf('== ÖZET (%d sorun, %.1f sn) ==', count($report['summary']), $report['elapsed_ms'] / 1000));
        foreach (array_slice($report['summary'], 0, 40) as $problem) {
            $this->out('!! '.$problem);
        }
        if (count($report['summary']) > 40) {
            $this->out('   … +'.(count($report['summary']) - 40).' sorun (yukarıdaki bölümlerde)');
        }
        if ($report['summary'] === []) {
            $this->out('   Sorun bulunmadı.');
        }

        return self::SUCCESS;
    }

    private function out(string $line): void
    {
        $this->line(trim($line) === '' ? '' : $this->keepPrefix($line));
    }

    /** Masks the line but keeps the leading "!! " / indentation that DiagnosticMasker::text() would collapse. */
    private function keepPrefix(string $line): string
    {
        preg_match('/^(!! \s*|\s*)/', $line, $m);
        $prefix = $m[1] ?? '';

        return $prefix.DiagnosticMasker::text(substr($line, strlen($prefix)));
    }
}
