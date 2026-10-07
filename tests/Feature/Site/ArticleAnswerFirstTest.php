<?php

namespace Tests\Feature\Site;

use App\Livewire\Operator\Portfolio\BrandExperts;
use App\Models\BrandExpert;
use App\Models\CoreConnection;
use App\Models\ExternalWriteAction;
use App\Services\ExternalWrites\ArticleDraft;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\Site\ArticleFaqSchema;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * Cevap öncelikli içerik (yakup, 2026-10-07): the writer answers first and puts its questions as <h3>…?</h3>; the
 * WordPress draft carries those questions as FAQPage schema and goes out under the brand's expert author.
 */
final class ArticleAnswerFirstTest extends SiteTestCase
{
    private const string HTML = '<p>İmplant süreci kemik durumuna göre değişir.</p>'
        .'<h2>Süreyi ne belirler?</h2><p>Muayenede netleşir.</p>'
        .'<h2>Sık sorulan sorular</h2><h3>İmplant ağrılı mı?</h3><p>Lokal anestezi yapılır.</p><p>Sonrasında hafif sızı olabilir.</p>'
        .'<h3>Kaç günde biter?</h3><ul><li>İlk aşama bir gün</li><li>Kaplama 3 ay sonra</li></ul>'
        .'<h3>Cevapsız soru?</h3><h2>Randevu</h2><p>Bize ulaşın.</p>';

    public function test_questions_with_answers_become_faq_schema(): void
    {
        $pairs = ArticleFaqSchema::pairs(self::HTML);
        $this->assertSame(['Süreyi ne belirler?', 'İmplant ağrılı mı?', 'Kaç günde biter?'], array_column($pairs, 'question'), 'a question without an answer is left out');
        $this->assertSame('Lokal anestezi yapılır. Sonrasında hafif sızı olabilir.', $pairs[1]['answer']);
        $this->assertSame('İlk aşama bir gün; Kaplama 3 ay sonra', $pairs[2]['answer']);

        $schema = ArticleFaqSchema::fromHtml(self::HTML, 'tr');
        $this->assertSame('FAQPage', $schema['@type']);
        $this->assertSame('Answer', $schema['mainEntity'][0]['acceptedAnswer']['@type']);
        $this->assertNull(ArticleFaqSchema::fromHtml('<h2>Tek soru?</h2><p>Cevap.</p>'), 'one question is not an FAQ');
        $this->assertNull(ArticleFaqSchema::fromHtml(''));
    }

    public function test_the_draft_carries_the_faq_schema_and_the_expert_author(): void
    {
        Queue::fake();
        CoreConnection::factory()->create(['digital_asset_id' => $this->site->id, 'type' => 'wordpress_connector', 'enabled' => true,
            'config' => ['pairing_state' => 'paired', 'plugin_version' => '1.11.0']]);

        Livewire::test(BrandExperts::class, ['brandId' => $this->brand->id])
            ->set('name', 'Dt. Ayşe Kaya')->set('title', 'Ağız ve diş cerrahisi uzmanı')->set('wpAuthor', 'aysekaya')->call('add')->assertHasNoErrors()
            ->set('name', 'Dt. Mehmet Er')->call('add')->assertHasNoErrors()
            ->set('profileUrl', 'adres-degil')->set('name', 'X')->call('add')->assertHasErrors('profileUrl')
            ->assertSee('Dt. Ayşe Kaya');
        $this->assertSame('aysekaya', BrandExpert::authorOf($this->brand->id)?->wp_author, 'the first expert is the author');

        $draft = new ArticleDraft(title: 'İmplant süreci', html: self::HTML, reference: 'test-1', language: 'tr');
        app(ExternalWriteService::class)->requestArticleDrafts($this->admin, $this->site, $draft);
        $payload = ExternalWriteAction::query()->sole()->request_payload['drafts'][0]['draft'];
        $this->assertSame('aysekaya', $payload['author']);
        $this->assertSame('FAQPage', $payload['schema']['@type']);
        $this->assertCount(3, $payload['schema']['mainEntity']);

        $mehmet = BrandExpert::query()->where('name', 'Dt. Mehmet Er')->sole();
        Livewire::test(BrandExperts::class, ['brandId' => $this->brand->id])->call('makeDefault', $mehmet->id);
        $this->assertNull(BrandExpert::authorOf($this->brand->id)?->wp_author, 'an expert without a WordPress user leaves the site default author');

        $plugin = (string) file_get_contents(base_path('connectors/wordpress/moxdop-connector/includes/class-moxdop-connector-rest-controller.php'));
        $this->assertStringContainsString("user_can(\$user, 'edit_posts')", $plugin, 'only a user who can write posts becomes the author');
        $this->assertStringContainsString("'_moxdop_schema'", $plugin);
    }
}
