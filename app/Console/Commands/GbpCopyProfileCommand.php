<?php

namespace App\Console\Commands;

use App\Models\ClaudeNote;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\User;
use App\Services\Gbp\Desk\GbpDesk;
use App\Services\Gbp\GbpProfileCopier;
use App\Support\Roles;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * moxdop:gbp:copy-profile — one branch's Business Profile categories and services onto the brand's other branches
 * (yakup, 2026-10-13, Avrupadent Çiğli → the other Avrupadent branches). Without --send it only lists what each branch
 * lacks; --send writes each branch's own descriptions and sends the additions as the first active Admin (ADR-077,
 * undoable on each profile's "Kategori ve hizmetler" tab), then waits for the results. --report lists each branch's
 * last send of the day and keeps it as a Claude note on the brand (read over MCP), so a queued run is checked without
 * the server shell.
 */
final class GbpCopyProfileCommand extends Command
{
    protected $signature = 'moxdop:gbp:copy-profile {source : Source profile asset id, or the start of its name (e.g. "Avrupadent Çiğli")}
        {--only=* : Only these target asset ids} {--send : Write the descriptions and send} {--wait=600 : Seconds to wait for the sends to finish}
        {--report : Only report the last day sends per branch (also kept as a Claude note on the brand)}';

    protected $description = 'Copy one Business Profile branch\'s categories and services to the brand\'s other branches (descriptions per branch).';

    public function handle(GbpProfileCopier $copier, GbpDesk $desk): int
    {
        $source = $this->source((string) $this->argument('source'), $desk);
        if ($source === null) {
            return self::FAILURE;
        }
        $only = array_map('intval', (array) $this->option('only'));
        $targets = $desk->locations((int) $source->brand_id)->filter(fn (DigitalAsset $l): bool => (int) $l->id !== (int) $source->id
            && ($only === [] || in_array((int) $l->id, $only, true)))->values();
        if ($this->option('report')) {
            $this->noteReport($source, $targets);

            return self::SUCCESS;
        }
        $admin = User::query()->role(Roles::ADMIN)->where('is_active', true)->orderBy('id')->first();
        if ($this->option('send') && $admin === null) {
            $this->error('Aktif Admin kullanıcı yok.');

            return self::FAILURE;
        }
        if ($this->option('send')) {
            // The Admin's approved send, not automatic work: without a user the AI budget only lets the automatic areas
            // run, and every branch came back "Uygun AI sağlayıcısı yok" (live, 2026-10-13).
            Auth::setUser($admin);
        }
        try {
            $from = $copier->source($source);
        } catch (Throwable $exception) {
            $this->error(GbpDesk::shortName((string) $source->name).' okunamadı: '.$exception->getMessage());

            return self::FAILURE;
        }
        $this->info(GbpDesk::shortName((string) $source->name).' (#'.$source->id.'): '.count($from['categories']).' kategori, '.count($from['services']).' hizmet → '.$targets->count().' şube.');
        $this->line('Kategoriler: '.implode(', ', array_column($from['categories'], 'name')));

        $sent = [];
        $failed = [];
        foreach ($targets as $target) {
            $name = GbpDesk::shortName((string) $target->name).' (#'.$target->id.')';
            try {
                $missing = $copier->missing($target, $from);
                $summary = count($missing['categories']).' kategori, '.count($missing['services']).' hizmet eksik'.($missing['skipped'] !== [] ? ', '.count($missing['skipped']).' atlandı' : '');
                if ($missing['categories'] === [] && $missing['services'] === []) {
                    $this->line($name.': hepsi zaten var.');

                    continue;
                }
                if (! $this->option('send')) {
                    $this->line($name.': '.$summary.'.');
                    foreach ($missing['skipped'] as $skip) {
                        $this->line('   atlanır: '.$skip['line'].' — '.$skip['reason']);
                    }

                    continue;
                }
                $result = $copier->send($admin, $source, $target, $missing);
                $sent[$name] = (int) $result['action']->id;
                $this->line($name.': '.$summary.' → gönderildi (işlem #'.$result['action']->id.')'.($result['without_description'] > 0 ? '; '.$result['without_description'].' hizmet açıklamasız' : '').'.');
            } catch (Throwable $exception) {
                $failed[] = $name.': '.mb_substr($exception->getMessage(), 0, 300);
                $this->error($name.': '.$exception->getMessage());
            }
        }
        if ($this->option('send') && $failed !== []) {
            // A queued run has no screen: why a branch was not sent is kept on the brand (read over MCP).
            ClaudeNote::query()->create(['brand_id' => $source->brand_id, 'kind' => 'observation', 'status' => ClaudeNote::OPEN,
                'text' => GbpDesk::shortName((string) $source->name).' kopyası gönderilemedi ('.now()->format('Y-m-d H:i')."):\n".implode("\n", $failed)]);
        }
        if (! $this->option('send')) {
            $this->info('Göndermek için aynı komutu --send ile çalıştırın.');

            return self::SUCCESS;
        }

        return $this->report($sent, (int) $this->option('wait')) && $failed === [] ? self::SUCCESS : self::FAILURE;
    }

    private function source(string $given, GbpDesk $desk): ?DigitalAsset
    {
        if (ctype_digit($given)) {
            $asset = DigitalAsset::query()->where('type', 'google_business_profile')->find((int) $given);
        } else {
            $matches = $desk->locations()->filter(fn (DigitalAsset $l): bool => str_starts_with(mb_strtolower(GbpDesk::shortName((string) $l->name)), mb_strtolower(trim($given))))->values();
            if ($matches->count() > 1) {
                $this->error('Birden çok profil uyuyor: '.$matches->map(fn (DigitalAsset $l): string => GbpDesk::shortName((string) $l->name).' (#'.$l->id.')')->implode(', ').'. Kimliği yazın.');

                return null;
            }
            $asset = $matches->first();
        }
        if ($asset === null) {
            $this->error('Kaynak profil bulunamadı: '.$given);
        }

        return $asset;
    }

    /**
     * Each branch's latest category / service send of the last day, printed and kept as a Claude note on the brand.
     *
     * @param  Collection<int, DigitalAsset>  $targets
     */
    private function noteReport(DigitalAsset $source, Collection $targets): void
    {
        $lines = [];
        $refs = [];
        foreach ($targets as $target) {
            $action = ExternalWriteAction::query()->where('digital_asset_id', $target->id)->where('action', ExternalWriteAction::ACTION_PROFILE_UPDATE)
                ->where('created_at', '>=', now()->subDay())->latest('id')->first();
            $result = (array) ($action->result ?? []);
            $lines[] = GbpDesk::shortName((string) $target->name).' (#'.$target->id.'): '.($action === null ? 'gönderim yok (eksik yoktu ya da hazırlanamadı)'
                : $action->status.' · '.count((array) ($result['added_categories'] ?? [])).' kategori, '.count((array) ($result['added_services'] ?? [])).' hizmet eklendi'
                .($action->error ? ' · '.$action->error : ''));
            if ($action !== null) {
                $refs[] = 'external_write_action:'.$action->id;
            }
        }
        $text = GbpDesk::shortName((string) $source->name).' kategori ve hizmet kopyalama sonucu ('.now()->format('Y-m-d H:i')."):\n".implode("\n", $lines);
        foreach (explode("\n", $text) as $line) {
            $this->line($line);
        }
        ClaudeNote::query()->create(['brand_id' => $source->brand_id, 'kind' => 'observation', 'text' => $text, 'refs' => $refs ?: null, 'status' => ClaudeNote::OPEN]);
    }

    /** @param  array<string, int>  $sent  branch => action id */
    private function report(array $sent, int $wait): bool
    {
        if ($sent === []) {
            return true;
        }
        $this->info('Sonuçlar bekleniyor…');
        $deadline = time() + max(0, $wait);
        do {
            $actions = ExternalWriteAction::query()->whereIn('id', array_values($sent))->get()->keyBy('id');
            $open = $actions->whereIn('status', ['queued', 'running'])->count();
            if ($open > 0 && time() < $deadline) {
                sleep(5);
            }
        } while ($open > 0 && time() < $deadline);
        $ok = true;
        foreach ($sent as $name => $id) {
            $action = $actions->get($id);
            $result = (array) ($action->result ?? []);
            $status = (string) ($action->status ?? '?');
            $ok = $ok && $status === 'succeeded';
            $this->line($name.': '.$status.($status === 'succeeded' || $status === 'partial'
                ? ' · '.count((array) ($result['added_categories'] ?? [])).' kategori, '.count((array) ($result['added_services'] ?? [])).' hizmet eklendi' : '')
                .($action?->error ? ' · '.$action->error : ''));
        }

        return $ok;
    }
}
