<?php

namespace Tests\Feature\Site;

use App\Ai\Agents\Site\BacklinkSourcesAgent;
use App\Enums\CustomerStatus;
use App\Livewire\Operator\Website\V2\BacklinksTab;
use App\Models\Backlink;
use App\Models\BacklinkSource;
use App\Services\Site\Backlinks\BacklinkImporter;
use App\Services\Site\Backlinks\BacklinkSourceProposer;
use App\Services\Site\Backlinks\BacklinkVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Tests\TestCase;

/** Faz 4b Backlinkler: Search Console export import, AI potential sources (fee rule), link verification. */
final class BacklinksTest extends TestCase
{
    use RefreshDatabase;
    use SiteFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSite();
    }

    public function test_search_console_export_is_imported_once_per_link_with_the_earliest_date(): void
    {
        $csv = "\u{FEFF}Linking page,Target page,Last crawled\n"
            ."https://haber.example/ankara-klinikler,https://panorama.example/implant/,2026-08-10\n"
            ."https://rehber.example/dis,https://panorama.example/,2026-07-01\n"
            ."https://www.panorama.example/blog,https://panorama.example/,2026-07-01\n"
            ."bozuk satır,,\n";
        $path = tempnam(sys_get_temp_dir(), 'gsc');
        file_put_contents($path, $csv);

        $stats = app(BacklinkImporter::class)->import($this->brand, $path, 'links.csv');

        $this->assertSame(['imported' => 2, 'updated' => 0, 'skipped' => 2], $stats, 'own site and broken rows skipped');
        $link = Backlink::query()->where('source_domain', 'haber.example')->sole();
        $this->assertSame(['https://haber.example/ankara-klinikler', 'https://panorama.example/implant/', '2026-08-10', 'gsc_import'],
            [$link->source_url, $link->target_url, $link->first_seen->toDateString(), $link->source]);

        // Turkish "top linking sites" export (semicolon): domain-level rows; a re-import keeps the earliest date.
        file_put_contents($path, "Site;Bağlantı veren sayfalar;Hedef sayfalar\nsektor.example;12;3\n");
        $this->assertSame(1, app(BacklinkImporter::class)->import($this->brand, $path, 'siteler.csv')['imported']);
        $this->assertNull(Backlink::query()->where('source_domain', 'sektor.example')->value('source_url'));
        file_put_contents($path, "Linking page,Target page,Last crawled\nhttps://haber.example/ankara-klinikler,https://panorama.example/implant/,2026-06-01\n");
        $this->assertSame(1, app(BacklinkImporter::class)->import($this->brand, $path, 'links.csv')['updated']);
        $this->assertSame('2026-06-01', $link->fresh()->first_seen->toDateString());
        $this->assertSame(3, Backlink::query()->count());
        unlink($path);
    }

    public function test_upload_through_the_tab_and_manual_rows(): void
    {
        $file = UploadedFile::fake()->createWithContent('links.csv', "Linking page,Target page\nhttps://blog.example/yazi,https://panorama.example/\n");

        Livewire::test(BacklinksTab::class, ['assetId' => $this->site->id])
            ->set('export', $file)->call('importExport')->assertHasNoErrors()->assertSet('message', '1 yeni · 0 güncellendi · 0 atlandı')
            ->assertSee('https://blog.example/yazi')
            ->set('linkUrl', 'https://dernek.example/uyeler')->call('addBacklink')->assertHasNoErrors()->assertSee('dernek.example/uyeler')
            ->set('sourceName', 'Ankara Diş Hekimleri Odası')->set('sourceUrl', 'https://oda.example/uyeler')->call('addSource')->assertHasNoErrors()
            ->assertSee('Ankara Diş Hekimleri Odası')->assertSee('teyit gerekli')
            ->set('sourceUrl', 'https://oda.example/baska')->set('sourceName', 'Aynı')->call('addSource')->assertHasErrors('sourceUrl');
        $this->assertSame(['gsc_import', 'manual'], Backlink::query()->orderBy('id')->pluck('source')->all());
    }

    public function test_potential_sources_keep_a_fee_only_with_an_evidence_url_on_the_same_site(): void
    {
        $this->enableAi();
        Backlink::query()->create(['brand_id' => $this->brand->id, 'source_domain' => 'zaten.example', 'link_hash' => str_repeat('c', 64), 'source' => 'manual']);
        $sent = '';
        BacklinkSourcesAgent::fake(function (string $prompt) use (&$sent): array {
            $sent = $prompt;

            return ['sources' => [
                ['name' => 'Ankara Rehberi', 'url' => 'https://rehber.example/firma-ekle', 'kind' => 'dizin', 'fee' => 'ucretsiz', 'fee_evidence_url' => 'https://rehber.example/ucretsiz-kayit', 'reason' => 'Yerel rehber.'],
                ['name' => 'Kanıtsız Ücretli', 'url' => 'https://ilan.example/ekle', 'kind' => 'dizin', 'fee' => 'ucretli', 'fee_evidence_url' => null, 'reason' => 'x'],
                ['name' => 'Başka Siteden Kanıt', 'url' => 'https://haberler.example/ilan', 'kind' => 'yerel_haber', 'fee' => 'ucretli', 'fee_evidence_url' => 'https://baska.example/fiyat', 'reason' => 'x'],
                ['name' => 'Zaten Var', 'url' => 'https://zaten.example/', 'kind' => 'dizin', 'fee' => 'teyit', 'fee_evidence_url' => null, 'reason' => 'x'],
                ['name' => 'Güvensiz', 'url' => 'http://duz.example/', 'kind' => 'dizin', 'fee' => 'teyit', 'fee_evidence_url' => null, 'reason' => 'x'],
                ['name' => 'Kendi Site', 'url' => 'https://panorama.example/', 'kind' => 'diger', 'fee' => 'teyit', 'fee_evidence_url' => null, 'reason' => 'x'],
            ], 'prompt_version' => BacklinkSourcesAgent::PROMPT_VERSION];
        });

        $this->assertSame(['status' => 'ready', 'added' => 3], app(BacklinkSourceProposer::class)->propose($this->brand));

        $this->assertStringContainsString('Çankaya', $sent);
        $this->assertStringContainsString('zaten.example', $sent, 'existing linking domains are listed so they are not repeated');
        $fees = BacklinkSource::query()->orderBy('id')->get()->mapWithKeys(fn (BacklinkSource $s): array => [$s->domain => [$s->fee, $s->fee_evidence_url, $s->status, $s->origin]])->all();
        $this->assertSame([
            'rehber.example' => ['ucretsiz', 'https://rehber.example/ucretsiz-kayit', 'yok', 'ai'],
            'ilan.example' => ['teyit', null, 'yok', 'ai'],
            'haberler.example' => ['teyit', null, 'yok', 'ai'],
        ], $fees);
    }

    public function test_potential_sources_are_not_proposed_for_a_passive_customer(): void
    {
        $this->enableAi();
        $this->customer->forceFill(['status' => CustomerStatus::Inactive])->save();
        BacklinkSourcesAgent::fake(fn (): array => $this->fail('no AI call'));

        $this->assertSame('not_operational', app(BacklinkSourceProposer::class)->propose($this->brand->fresh())['status']);
        Livewire::test(BacklinksTab::class, ['assetId' => $this->site->id])->call('proposeSources')->assertSee('Hizmet kapsamı dışında');
    }

    public function test_link_is_verified_found_missing_and_removed(): void
    {
        $source = BacklinkSource::query()->create(['brand_id' => $this->brand->id, 'name' => 'Rehber', 'url' => 'https://rehber.example/', 'domain' => 'rehber.example', 'status' => 'yok']);
        $pages = ['https://rehber.example/panorama' => '<html><body><a href="https://www.panorama.example/?utm_source=rehber">Panorama</a></body></html>'];
        $this->fakePages($pages);

        Livewire::test(BacklinksTab::class, ['assetId' => $this->site->id])
            ->set('given.'.$source->id, 'https://rehber.example/panorama')->call('markGiven', $source->id)->assertHasNoErrors();

        $source->refresh();
        $this->assertSame(['dogrulandi', 'https://rehber.example/panorama'], [$source->status, $source->link_url], 'queued check found the link');
        $this->assertNotNull($source->verified_at);

        // Link gone on the weekly re-check → "daha sonra kaldırıldı" (a real status); it stays so while missing.
        $this->fakePages(['https://rehber.example/panorama' => '<html><body><a href="/iletisim">İletişim</a><a href="https://baska.example/">x</a></body></html>']);
        Artisan::call('moxdop:site', ['task' => 'backlinks']);
        $source->refresh();
        $this->assertSame(BacklinkSource::REMOVED, $source->status);
        $this->assertSame(BacklinkVerifier::MISSING, app(BacklinkVerifier::class)->verify($source->fresh()));
        $this->assertSame(BacklinkSource::REMOVED, $source->fresh()->status);
        Livewire::test(BacklinksTab::class, ['assetId' => $this->site->id])->assertSee('daha sonra kaldırıldı');

        // Marked "verildi" but the page has no link → stays verildi with a note; unreachable page changes only the note.
        $source->forceFill(['status' => 'verildi'])->save();
        $this->assertSame(BacklinkVerifier::MISSING, app(BacklinkVerifier::class)->verify($source->fresh()));
        $this->assertSame('verildi', $source->fresh()->status);
        $this->fakePages([]);
        $this->assertSame(BacklinkVerifier::UNREACHABLE, app(BacklinkVerifier::class)->verify($source->fresh()));
        $this->assertStringStartsWith('Sayfa açılamadı', (string) $source->fresh()->note);
    }

    public function test_five_statuses_and_the_operator_sets_basvuru_and_verildi(): void
    {
        $this->assertSame(['yok', 'basvuru', 'verildi', 'dogrulandi', 'kaldirildi'], BacklinkSource::STATUSES);
        $this->assertSame(['henüz tespit edilmedi', 'başvuru / iletişim yapıldı', 'kullanıcı eklediğini bildirdi', 'sayfada doğrulandı', 'daha sonra kaldırıldı'], array_values(BacklinkSource::STATUS_LABELS));
        $this->assertArrayNotHasKey('dataforseo', Backlink::SOURCES);
        $source = BacklinkSource::query()->create(['brand_id' => $this->brand->id, 'name' => 'Dernek', 'url' => 'https://dernek.example/', 'domain' => 'dernek.example', 'status' => 'yok']);
        $this->fakePages(['https://dernek.example/uyeler' => '<html><body>Üyeler</body></html>']);

        $tab = Livewire::test(BacklinksTab::class, ['assetId' => $this->site->id])->assertSee('henüz tespit edilmedi')->call('markApplied', $source->id);
        $this->assertSame(BacklinkSource::APPLIED, $source->fresh()->status);
        $tab->assertSee('başvuru / iletişim yapıldı')
            ->set('given.'.$source->id, 'https://dernek.example/uyeler')->call('markGiven', $source->id)->assertHasNoErrors();
        // The page has no link yet: the operator's report stands (verildi), with a note.
        $this->assertSame(BacklinkSource::GIVEN, $source->fresh()->status);
        $this->assertStringStartsWith('Bağlantı bulunamadı', (string) $source->fresh()->note);
    }

    public function test_migration_maps_old_removed_rows_and_the_dataforseo_source(): void
    {
        $removed = BacklinkSource::query()->create(['brand_id' => $this->brand->id, 'name' => 'A', 'url' => 'https://a.example/', 'domain' => 'a.example', 'status' => 'yok', 'note' => 'Bağlantı kaldırıldı · 01.09.2026']);
        $none = BacklinkSource::query()->create(['brand_id' => $this->brand->id, 'name' => 'B', 'url' => 'https://b.example/', 'domain' => 'b.example', 'status' => 'yok']);
        $link = Backlink::query()->create(['brand_id' => $this->brand->id, 'source_domain' => 'c.example', 'link_hash' => hash('sha256', 'c'), 'source' => 'dataforseo', 'status' => 'aktif']);

        (require database_path('migrations/2026_10_30_093000_moxdop_v2_site_screen_fixes.php'))->up();

        $this->assertSame(['kaldirildi', 'yok', 'manual'], [$removed->fresh()->status, $none->fresh()->status, $link->fresh()->source]);
    }
}
