<?php

namespace App\Console\Commands;

use App\Services\Operations\PilotRefresh;
use App\Support\Console\ConsoleScope;
use App\Support\Console\ConsoleScopeException;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * moxdop:pilot:refresh --brand=Panorama — the brand's whole analysis chain in dependency order (query pipeline →
 * clustering → demand hub → topic map → SEO plan → URL karnesi → analysts) as one queued chain on the heavy queue.
 * Without --run it only lists the steps and the current state (dry run).
 */
final class PilotRefreshCommand extends Command
{
    protected $signature = 'moxdop:pilot:refresh
        {--brand= : Marka id veya adının bir parçası (ör. Panorama)}
        {--run : Zinciri kuyruğa al (yoksa yalnız adımları listeler)}
        {--status : Son çalıştırmanın adım adım durumu}';

    protected $description = 'Bir markanın sorgu hattı → kümeleme → talep tablosu → konu haritası → SEO planı → Sayfa Karnesi → analist zincirini sırayla kuyruğa alır (varsayılan: kuru çalıştırma).';

    public function handle(PilotRefresh $pilot): int
    {
        if ($this->option('brand') === null) {
            $this->error('--brand gerekli (id veya adın bir parçası, ör. --brand=Panorama).');

            return self::INVALID;
        }
        try {
            $brand = ConsoleScope::brand((string) $this->option('brand'));
        } catch (ConsoleScopeException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }
        $this->line(sprintf('Marka #%d %s · kuyruk: %s', $brand->id, $brand->name, (string) config('queue.heavy_queue', 'default')));

        if ($this->option('status')) {
            $state = PilotRefresh::state($brand->id);
            if ($state === null) {
                $this->line('Bu marka için pilot yenileme kaydı yok.');

                return self::SUCCESS;
            }
            $this->line('Durum: '.($state['status'] ?? '?').' · başladı '.($state['started_at'] ?? '?').(isset($state['finished_at']) ? ' · bitti '.$state['finished_at'] : ''));
            $this->table(['Adım', 'Durum', 'Zaman', 'Not'], array_map(fn (string $step, array $row): array => [$step, $row['status'], $row['at'], $row['note']],
                array_keys((array) ($state['steps'] ?? [])), array_values((array) ($state['steps'] ?? []))));

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($pilot->plan($brand) as $index => $step) {
            $rows[] = [($index + 1).'. '.$step['label'], $step['target'], $step['current']];
        }
        $this->table(['Adım (sırayla)', 'Kapsam', 'Şu anki durum'], $rows);

        if (! $this->option('run')) {
            $this->line('Kuru çalıştırma: hiçbir iş kuyruğa alınmadı. Başlatmak için --run ekleyin; ilerleme: --status.');

            return self::SUCCESS;
        }
        try {
            $result = $pilot->dispatch($brand);
        } catch (ValidationException $exception) {
            $this->error(collect($exception->errors())->flatten()->implode(' '));

            return self::FAILURE;
        }
        $result['queued'] ? $this->info($result['message']) : $this->warn($result['message']);

        return $result['queued'] ? self::SUCCESS : self::FAILURE;
    }
}
