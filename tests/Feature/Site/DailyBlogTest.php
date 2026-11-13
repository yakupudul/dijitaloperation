<?php

namespace Tests\Feature\Site;

use App\Models\CoreConnection;
use App\Models\CoreConnectionCredential;
use App\Models\ExternalWriteAction;
use App\Models\Suggestion;
use App\Services\Site\BrandCover;
use App\Services\Site\DailyBlog;
use App\Services\Work\ContentAutopilot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

/**
 * Günlük blog (yakup 2026-11-12): moximu.com publishes two articles a day without approval, through a quality gate, with
 * its own drawn cover; one line only, the weekly cycle leaves the site.
 */
final class DailyBlogTest extends SiteTestCase
{
    private const string SECRET = 'sssssssssssssssssssssssssssssssssssssssssss';

    protected function setUp(): void
    {
        parent::setUp();
        $this->brand->forceFill(['auto_blog' => ['per_day' => 2, 'times' => ['09:00', '15:00']]])->save();
    }

    private function connection(): void
    {
        $connection = CoreConnection::factory()->create(['digital_asset_id' => $this->site->id, 'type' => 'wordpress_connector', 'enabled' => true,
            'config' => ['pairing_state' => 'paired', 'snapshot_url' => 'https://panorama.com.tr/wp-json/moxdop/v1/snapshot', 'plugin_version' => '1.37.0']]);
        CoreConnectionCredential::factory()->create(['connection_id' => $connection->id, 'encrypted_payload' => ['client_id' => 'client-1', 'shared_secret' => self::SECRET]]);
    }

    private function body(int $words = 520): string
    {
        $sentence = 'Panorama Ankara hastaların aradığı tedaviyi açık ve sade bir dille anlatır ve her sayfayı ölçer.';
        $paragraphs = '';
        for ($i = 0; $i * 15 < $words; $i++) {
            $paragraphs .= '<p>'.$sentence.'</p>';
        }

        return '<h2>Doğru kategori</h2>'.$paragraphs.'<h2>Haftalık gönderi</h2><p>Her hafta bir bilgi gönderisi girilir.</p>';
    }

    /** @param  array<string, mixed>  $action */
    private function idea(string $title, string $status = Suggestion::APPROVED, array $action = [], bool $written = true): Suggestion
    {
        return Suggestion::query()->create([
            'brand_id' => $this->brand->id, 'channel' => 'search', 'decision_key' => 'site.content', 'fingerprint' => md5($title), 'material_hash' => md5($title),
            'title' => $title, 'reason' => 'Kümenin sayfası yok.', 'priority' => 3, 'evidence' => [], 'action_type' => 'content',
            'action' => array_merge(['site_id' => $this->site->id, 'kind' => 'new', 'page_type' => 'blog', 'language' => 'tr'], $written ? ['article' => [
                'title' => $title, 'slug' => 'yazi-'.md5($title), 'html' => $this->body(), 'reference' => 'moxdop-'.md5($title),
                'meta_title' => $title, 'meta_description' => 'Kısa özet.', 'excerpt' => 'Kısa özet.', 'language' => 'tr']] : [], $action),
            'status' => $status, 'target_type' => 'site', 'target_id' => $this->site->id, 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
    }

    public function test_the_quality_gate_names_every_problem_and_passes_a_clean_article(): void
    {
        $clean = ['title' => 'Diş kliniği haritada nasıl öne çıkar?', 'html' => $this->body()];
        $this->assertSame([], DailyBlog::problems($clean, $this->idea('Temiz yazı'), $this->site));

        $bad = ['title' => 'Diş kliniği SEO fiyatları: ücret neye göre belirlenir ve neden değişir?',
            'html' => '<p>MoxDOP ile ilk sırayı garanti ediyoruz. Aylık 12.000 ₺ + KDV.</p>'];
        $problems = implode(' | ', DailyBlog::problems($bad, $this->idea('Kötü yazı'), $this->site));
        foreach (['başlık', 'iki nokta', 'MoxDOP', 'garanti', 'fiyat', 'kelime', 'Panorama Ankara adı'] as $expected) {
            $this->assertStringContainsString($expected, $problems);
        }
        $this->assertNotSame([], DailyBlog::problems(['title' => 'Kısa başlık', 'html' => $this->body().'<p>Ücret 500 TL olur.</p>'], null, null), 'TL alone is a price');
        $this->assertSame([], DailyBlog::problems(['title' => 'Kısa başlık', 'html' => $this->body().'<p>Ücret hizmet kapsamına göre değişir.</p>'], null, null));

        $this->page('/rehber/dis-klinigi-haritada-nasil-one-cikar/', 'Diş kliniği haritada nasıl öne çıkar?');
        $this->assertStringContainsString('aynı konuda yazı var', implode(' ', DailyBlog::problems($clean, $this->idea('Tekrar'), $this->site)));
    }

    public function test_one_line_takes_the_waiting_titles_and_the_weekly_cycle_leaves_the_site(): void
    {
        Queue::fake();
        $waiting = $this->idea('Google Ads hesabında ilk optimizasyon', Suggestion::APPROVED, [], false);
        foreach (['Hasta formu neden gelmez', 'Klinik web sitesi ne zaman yenilenir', 'Google yorumlarına cevap verme düzeni', 'Sağlık turizmi için dil sayfaları',
            'Klinik reklamında yasak ifadeler', 'Instagram reklamında hedef kitle seçimi', 'Search Console raporu okuma', 'Hekim profili sayfası nasıl yazılır'] as $title) {
            $this->idea($title, Suggestion::OPEN, [], false);
        }
        $this->idea('İzmir diş kliniği haritada nasıl öne çıkar', Suggestion::OPEN, [], false);

        $started = app(DailyBlog::class)->produce($this->site, $this->admin);

        $this->assertSame(DailyBlog::BUFFER, $started);
        $this->assertTrue(DailyBlog::taken($waiting->fresh()), 'the approved waiting title joins the line first');
        $this->assertSame(DailyBlog::BUFFER, Suggestion::query()->where('status', Suggestion::APPROVED)->get()->filter(fn (Suggestion $s): bool => DailyBlog::taken($s))->count());
        $this->assertSame('yerel', DailyBlog::lane(Suggestion::query()->where('title', 'like', 'İzmir%')->sole()));
        $this->assertSame('daily_blog', app(ContentAutopilot::class)->planWeek($this->site, $this->admin)['reason']);
        $this->assertSame(0, app(ContentAutopilot::class)->plan($this->site, $this->admin)['planned']);
        $this->assertSame(0, app(DailyBlog::class)->produce($this->site, $this->admin), 'the line is full');
    }

    public function test_checked_articles_go_scheduled_to_the_morning_and_afternoon_slots_with_the_sales_paragraph(): void
    {
        Queue::fake();
        $this->connection();
        $this->page('/hizmetlerimiz/google-isletme-profili/', 'Google İşletme Profili · Moximu');
        $this->page('/ucretsiz-klinik-analizi/', 'Ücretsiz ön değerlendirme');
        $ok = ['autopilot' => ['review' => ['verdict' => 'ok', 'reason' => '', 'at' => now()->toIso8601String()]]];
        $guide = $this->idea('Google İşletme Profili nasıl kurulur?', Suggestion::APPROVED, $ok + ['daily' => ['lane' => 'rehber']]);
        $local = $this->idea('Ankara diş kliniği haritada nasıl görünür?', Suggestion::APPROVED, $ok + ['daily' => ['lane' => 'yerel']]);
        $held = $this->idea('Kontrolde kalan yazı', Suggestion::APPROVED, ['daily' => ['lane' => 'rehber'], 'autopilot' => ['review' => ['verdict' => 'wrong', 'reason' => 'x', 'at' => now()->toIso8601String()]], 'check_fixes' => 3]);
        $this->assertSame(0, app(ContentAutopilot::class)->ready()->count(), 'the daily click never takes this line');

        $now = CarbonImmutable::parse('2026-11-13 07:30', 'Europe/Istanbul');
        $this->travelTo($now); // the slot has to be in the future for the post to go scheduled
        $sent = app(DailyBlog::class)->publish($this->site, $this->admin, $now);

        $this->assertSame(2, $sent, json_encode([$guide->fresh()->action['daily'] ?? null, $local->fresh()->action['daily'] ?? null], JSON_UNESCAPED_UNICODE));
        $this->assertSame('2026-11-13 09:00', data_get($guide->fresh()->action, 'daily.slot'), 'the guide takes the morning');
        $this->assertSame('2026-11-13 15:00', data_get($local->fresh()->action, 'daily.slot'), 'the city article the afternoon');
        $this->assertNull(data_get($held->fresh()->action, 'daily.slot'), 'a held article never goes');
        $write = ExternalWriteAction::query()->where('action', ExternalWriteAction::ACTION_ARTICLE_DRAFTS)->orderBy('id')->first();
        $draft = $write->request_payload['drafts'][0]['draft'];
        $this->assertTrue($draft['schedule']);
        $this->assertSame('2026-11-13T06:00:00+00:00', $draft['post_date']);
        $html = (string) data_get($guide->fresh()->action, 'article.html');
        $this->assertStringContainsString('https://panorama.com.tr/hizmetlerimiz/google-isletme-profili/', $html);
        $this->assertStringContainsString('ücretsiz ön değerlendirme', $html);
        $this->assertSame('2026-11-14 09:00', app(DailyBlog::class)->freeSlots($this->site, $now)[0]->format('Y-m-d H:i'), 'the next free slot is tomorrow morning');
        $this->brand->forceFill(['cover_style' => BrandCover::MOXIMU_REHBER])->save();
        app(DailyBlog::class)->instagramDraft($guide->fresh(), $now->setTime(9, 0));
        $social = \App\Models\SocialPost::query()->find((int) data_get($guide->fresh()->action, 'daily.instagram_id'));
        $this->assertNotNull($social, 'the article gets its social post');
        $this->assertStringContainsString('https://panorama.com.tr/rehber/yazi-', (string) $social->linkedin_text, 'LinkedIn gets the article link for its card');
    }

    public function test_the_sales_paragraph_never_links_a_service_on_a_shared_word_only(): void
    {
        $this->page('/hizmetlerimiz/google-isletme-profili/', 'Google İşletme Profili · Moximu');
        $this->page('/hizmetlerimiz/google-ads/', 'Google Ads yönetimi · Moximu');
        $this->page('/ucretsiz-klinik-analizi/', 'Ücretsiz ön değerlendirme');
        $linked = $this->idea('Google Ads danışmanına nasıl ulaşılır?');
        $action = $linked->action;
        $action['article']['html'] .= '<p><a href="https://panorama.com.tr/hizmetlerimiz/google-ads/">Google Ads</a></p>';
        $linked->forceFill(['action' => $action])->save();
        app(DailyBlog::class)->decorate($linked, $this->site);
        $html = (string) data_get($linked->fresh()->action, 'article.html');
        $this->assertStringNotContainsString('google-isletme-profili', $html, 'only "google" is shared: no Business Profile link');
        $this->assertStringContainsString('ücretsiz ön değerlendirme', $html);

        $plain = $this->idea('Google Ads hesabında ilk optimizasyon');
        app(DailyBlog::class)->decorate($plain, $this->site);
        $this->assertStringContainsString('/hizmetlerimiz/google-ads/', (string) data_get($plain->fresh()->action, 'article.html'));
    }

    public function test_without_its_own_cover_the_article_waits_instead_of_taking_a_site_photo(): void
    {
        Queue::fake();
        $this->connection();
        $this->brand->forceFill(['cover_style' => BrandCover::MOXIMU_REHBER])->save();
        $idea = $this->idea('Google İşletme Profili nasıl kurulur?', Suggestion::APPROVED, ['daily' => ['lane' => 'rehber'],
            'autopilot' => ['review' => ['verdict' => 'ok', 'reason' => '', 'at' => now()->toIso8601String()]]]);

        $sent = app(DailyBlog::class)->publish($this->site, $this->admin, CarbonImmutable::parse('2026-11-13 07:30', 'Europe/Istanbul'));

        $this->assertSame(0, $sent, 'the site did not take the drawn cover (no answer): nothing goes with a site photo');
        $this->assertNull(data_get($idea->fresh()->action, 'daily.slot'));
        $this->assertNull(data_get($idea->fresh()->action, 'image'));
        $this->assertSame(0, ExternalWriteAction::query()->count());
    }

    public function test_the_cover_follows_the_blog_style_with_a_motif_per_topic_and_an_instagram_portrait(): void
    {
        $cover = new BrandCover;
        foreach (array_keys(BrandCover::MOTIFS) as $motif) {
            $jpeg = $cover->render(BrandCover::MOXIMU_REHBER, ['title' => 'Google kartınız', 'title_rest' => 'ilk üçe nasıl girer?', 'tag' => 'Google İşletme Profili',
                'query' => 'yakınımdaki diş kliniği', 'items' => ['Doğru kategori', 'Hizmet listesi'], 'motif' => $motif]);
            $this->assertSame([1600, 900], array_slice(getimagesizefromstring($jpeg) ?: [], 0, 2), $motif);
        }
        $portrait = $cover->render(BrandCover::MOXIMU_REHBER, ['title' => 'Kısa başlık', 'motif' => 'harita'], true);
        $this->assertSame([1080, 1350], array_slice(getimagesizefromstring($portrait) ?: [], 0, 2));

        $this->assertSame('harita', BrandCover::motif('Diş kliniği Google İşletme Profili kurulumu'));
        $this->assertSame('olcum', BrandCover::motif('Klinik SEO nasıl ölçülür'));
        $this->assertNotSame('harita', BrandCover::motif('Google İşletme Profili yorumları ve SEO', 'harita'), 'two covers in a row differ when another family fits');
        $words = BrandCover::words($this->idea('Reklam bütçesi hedeften geriye hesaplanır'));
        $this->assertSame(['Reklam bütçesi', 'hedeften geriye hesaplanır'], [$words['title'], $words['title_rest']], 'black first part, grey rest');
        $this->assertSame(['Doğru kategori', 'Haftalık gönderi'], $words['items']);
    }
}
