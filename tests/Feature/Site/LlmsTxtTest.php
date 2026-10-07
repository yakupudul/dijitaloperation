<?php

namespace Tests\Feature\Site;

use App\Livewire\Operator\Website\V2\LlmsTxtCard;
use App\Models\BrandExpert;
use App\Models\BrandIntelligenceContext;
use App\Models\CoreConnection;
use App\Models\ExternalWriteAction;
use App\Models\OfferingPage;
use App\Services\Site\LlmsTxt;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * llms.txt (yakup, 2026-10-07): built by rules from the brand's data and stored pages, sent as a site fix to the
 * connector (≥ 1.11.0) which serves it at /llms.txt.
 */
final class LlmsTxtTest extends SiteTestCase
{
    public function test_the_file_is_built_from_the_brand_data_and_sent_to_the_site(): void
    {
        Queue::fake();
        BrandIntelligenceContext::query()->create(['brand_id' => $this->brand->id, 'business_summary' => "Ankara'da implant ve estetik diş tedavisi yapan diş kliniği.", 'source' => 'operator']);
        $implantPage = $this->page('/implant/', 'Diş İmplantı | Panorama', ['category' => 'hizmet', 'meta_description' => 'Tek seansta implant planlaması.']);
        OfferingPage::query()->create(['brand_offering_id' => $this->implantOffering->id, 'page_id' => $implantPage->id, 'source' => 'rule', 'locked' => false]);
        $this->page('/iletisim/', 'İletişim', ['category' => 'kurumsal']);
        $this->page('/blog/implant-sonrasi/', 'İmplant sonrası bakım', ['category' => 'blog']);
        $this->page('/gizli/', 'Gizli', ['is_indexable' => false, 'category' => 'blog']);
        BrandExpert::query()->create(['brand_id' => $this->brand->id, 'name' => 'Dt. Ayşe Kaya', 'title' => 'Ağız ve diş cerrahisi', 'profile_url' => 'https://panorama.com.tr/ekip/ayse/', 'is_default' => true]);

        $text = app(LlmsTxt::class)->build($this->site);
        $this->assertStringStartsWith("# Panorama Ankara\n\n> Ankara'da implant", $text);
        $this->assertStringContainsString('Hizmet verilen yerler: Çankaya / Ankara.', $text);
        $this->assertStringContainsString('- [Diş İmplantı](https://panorama.com.tr/implant/): Tek seansta implant planlaması.', $text);
        $this->assertStringContainsString("- Zirkonyum Kaplama\n", $text, 'a service without a page is named without a link');
        $this->assertStringContainsString('- [İletişim](https://panorama.com.tr/iletisim/)', $text);
        $this->assertStringContainsString('- [Dt. Ayşe Kaya](https://panorama.com.tr/ekip/ayse/): Ağız ve diş cerrahisi', $text);
        $this->assertStringContainsString('## Yazılar', $text);
        $this->assertStringNotContainsString('Gizli', $text, 'noindex pages stay out');

        $connection = CoreConnection::factory()->create(['digital_asset_id' => $this->site->id, 'type' => 'wordpress_connector', 'enabled' => true,
            'config' => ['pairing_state' => 'paired', 'plugin_version' => '1.10.0']]);
        $card = Livewire::test(LlmsTxtCard::class, ['assetId' => $this->site->id])->set('preview', true)->assertSeeHtml('data-llms-preview')->assertSee('Dt. Ayşe Kaya')
            ->call('send')->assertHasErrors('write');
        $this->assertSame(0, ExternalWriteAction::query()->count(), 'an older plugin would not know llms.txt');

        $connection->update(['config' => ['pairing_state' => 'paired', 'plugin_version' => '1.11.0']]);
        $card->call('send')->assertHasNoErrors()->assertSee('llms.txt siteye gönderildi.')->assertSeeHtml('data-llms-last');
        $change = ExternalWriteAction::query()->sole()->request_payload['changes'][0];
        $this->assertSame(['llms_txt', 0, 'llms-txt'], [$change['type'], $change['object_id'], $change['reference']]);
        $this->assertSame($text, $change['value']);

        $plugin = (string) file_get_contents(base_path('connectors/wordpress/moxdop-connector/includes/class-moxdop-connector-fixes.php'));
        $this->assertStringContainsString("case 'llms_txt':", $plugin);
        $this->assertStringContainsString("add_action('parse_request', [\$this, 'llms_txt'], 0);", $plugin);
    }
}
