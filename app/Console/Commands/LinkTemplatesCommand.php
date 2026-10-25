<?php

namespace App\Console\Commands;

use App\Models\DigitalAsset;
use App\Services\Site\TemplateLinks;
use App\Support\SchemaState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * moxdop:links:templates — nightly (pool #56): finds the links each site repeats on most of its pages, keeps them once
 * per site (website_template_links) and deletes their per-page rows from website_link_edge. The crawl skips them from
 * then on. The rows are rebuilt by the next crawl if a template link is dropped, and the night's backup holds them.
 */
final class LinkTemplatesCommand extends Command
{
    protected $signature = 'moxdop:links:templates {--site= : Only this website asset id}';

    protected $description = 'Sitenin çoğu sayfasında tekrarlanan bağlantıları site başına bir kez saklar, sayfa başına kopyalarını siler.';

    public function handle(TemplateLinks $templates): int
    {
        if (! SchemaState::hasTable('website_link_edge') || ! SchemaState::hasTable('website_template_links')) {
            $this->warn('Bağlantı tabloları yok.');

            return self::SUCCESS;
        }
        $ids = DB::table('website_link_edge')->distinct()->pluck('digital_asset_id')->map(fn ($id): int => (int) $id)
            ->when($this->option('site') !== null, fn ($c) => $c->filter(fn (int $id): bool => $id === (int) $this->option('site')));
        $deleted = 0;
        foreach ($ids as $id) {
            $site = DigitalAsset::query()->find($id);
            if ($site === null) {
                continue;
            }
            $home = (string) ($site->primary_url ?: 'https://'.$site->domain.'/');
            try {
                $out = $templates->compact($id, $home);
            } catch (Throwable $exception) {
                report($exception);

                continue;
            }
            $deleted += $out['deleted'];
            if ($out['templates'] > 0 || $out['dropped'] > 0) {
                $this->line(sprintf('Site %d: %d şablon bağlantısı, %d sayfa satırı silindi, %d eski şablon bırakıldı.', $id, $out['templates'], $out['deleted'], $out['dropped']));
            }
        }
        // Space goes back to the disk (and out of the backup) only after the table is rewritten: done when a lot went.
        if ($deleted >= 500000 && DB::getDriverName() === 'pgsql') {
            try {
                DB::statement("SET lock_timeout = '60s'");
                DB::statement('VACUUM (FULL, ANALYZE) website_link_edge');
                $this->line('website_link_edge yeniden yazıldı (VACUUM FULL).');
            } catch (Throwable $exception) {
                report($exception);
            }
        }
        $this->info(sprintf('Şablon bağlantıları: %d sayfa satırı silindi.', $deleted));

        return self::SUCCESS;
    }
}
