<?php

namespace App\Console\Commands;

use App\Models\ExternalWriteAction;
use App\Support\Demo\DemoMenu;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use ReflectionClass;

/**
 * W7: writes docs/SYSTEM_MAP.md from the code itself — menu and its tabs, the Command Center sources, every
 * scheduled job, the queues and the allowed external writes — so the one-page map never drifts from reality.
 */
final class SystemMapCommand extends Command
{
    protected $signature = 'moxdop:system-map {--path= : Output file (default docs/SYSTEM_MAP.md)}';

    protected $description = 'Regenerate the one-page system map from the code';

    public function handle(Schedule $schedule): int
    {
        app()->setLocale('tr');
        $path = (string) ($this->option('path') ?: base_path('docs/SYSTEM_MAP.md'));
        file_put_contents($path, $this->render($schedule));
        $this->info('Yazıldı: '.$path);

        return self::SUCCESS;
    }

    private function render(Schedule $schedule): string
    {
        $lines = [
            '# MoxDOP — sistem haritası',
            '',
            '> Bu dosya `php artisan moxdop:system-map` ile koddan üretilir; elle düzenlemeyin. Ürün kararları: `docs/MASTER_SPEC.md`, ADR\'ler: `docs/foundation/DECISION_LOG.md`, yetenek durumu: `PRODUCT_CAPABILITY_LEDGER.md`.',
            '',
            '## Akış',
            '',
            '1. **Bağlantılar** (Google, Meta, DataForSEO, WordPress Connector) hesapları keşfeder; her gün 05:10 otomatik keşif çalışır.',
            '2. Hesaplar **dijital varlıklara** bağlanır; bağlı varlıklar için **merkezi toplama** (`collection` kuyruğu) veriyi çeker.',
            '3. **Danışman, SEO görevleri, Hizmet Beyni, uyum denetimi, uyarılar** veriden iş üretir.',
            '4. Her iş **Komuta merkezine** düşer; operatör orada yapar / erteler / kapatır.',
            '5. Dış sistemlere yalnız aşağıdaki **onaylı yazmalar** gider; geri kalan her şey okumadır.',
            '6. Sonuçlar **aylık rapora**, **Ajans karnesine** ve **ajans işletmesine** (kârlılık, tahsilat) yansır.',
            '',
            '## Menü',
            '',
        ];
        foreach (DemoMenu::groups() as $group) {
            $lines[] = '- **'.$group['label'].'**';
            foreach ($group['items'] as $item) {
                $children = array_map(fn (array $child): string => $child['label'].' (`'.$child['route'].'`)', $item['children'] ?? []);
                $lines[] = '  - '.$item['label'].' (`'.$item['route'].'`)'.($children !== [] ? ' — sekmeler: '.implode(', ', $children) : '');
            }
        }

        $lines = [...$lines, '', '## Onaylı dış yazmalar', '', '| Kanal | İşlem |', '| --- | --- |'];
        $constants = (new ReflectionClass(ExternalWriteAction::class))->getConstants();
        $channels = array_values(array_filter($constants, fn ($v, $k): bool => str_starts_with($k, 'CHANNEL_'), ARRAY_FILTER_USE_BOTH));
        $actions = array_values(array_filter($constants, fn ($v, $k): bool => str_starts_with($k, 'ACTION_'), ARRAY_FILTER_USE_BOTH));
        $lines[] = '| '.implode(', ', $channels).' | '.implode(', ', $actions).' |';
        $lines[] = '';
        $lines[] = 'Google Ads kampanya / bütçe / durum değişikliği **yoktur**; öneriler Google Ads Editor dosyası olarak dışa aktarılır.';

        $lines = [...$lines, '', '## Kuyruklar', '', '- `default` — hızlı işler (bildirim, dış yazmalar, uptime).', '- `heavy` — uzun AI / analiz işleri; yalnız redis kuyruğunda ayrılır.', '- `collection` — merkezi veri toplama.', '', '## Zamanlanmış işler', '', '| Ne zaman | İş |', '| --- | --- |'];
        $events = collect($schedule->events())->map(fn (Event $event): array => [
            'when' => $event->expression.($event->timezone ? ' ('.(is_string($event->timezone) ? $event->timezone : $event->timezone->getName()).')' : ''),
            'what' => $this->describe($event),
        ])->sortBy('what')->values();
        foreach ($events as $event) {
            $lines[] = '| `'.$event['when'].'` | '.str_replace('|', '\\|', $event['what']).' |';
        }

        return implode("\n", $lines)."\n";
    }

    private function describe(Event $event): string
    {
        $command = (string) $event->command;
        if ($command !== '' && preg_match("/artisan'?\\s+'?([^\\s']+)/", $command, $match) === 1) {
            return '`'.$match[1].'`';
        }

        return (string) ($event->description ?: $command ?: 'closure');
    }
}
