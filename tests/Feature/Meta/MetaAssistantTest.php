<?php

namespace Tests\Feature\Meta;

use App\Ai\Agents\MetaCreativesAgent;
use App\Ai\Agents\MetaLandingAgent;
use App\Ai\Agents\MetaStructureAgent;
use App\Enums\CustomerStatus;
use App\Livewire\Demo\Meta\OverviewPage;
use App\Models\CoreIntegration;
use App\Models\ExternalWriteAction;
use App\Models\Suggestion;
use App\Services\Meta\MetaAssistant;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\SeedsMetaAccount;
use Tests\TestCase;

/**
 * Faz 6 Meta AI operations (fake AI, no HTTP): every item is validated against the data pack (services, campaigns,
 * ad sets, targets, numbers, URLs) and the sector compliance gate; operator edits are locked (a new AI version only
 * waits as a change proposal); non-operational brands get no AI; nothing is written to Meta.
 */
final class MetaAssistantTest extends TestCase
{
    use RefreshDatabase;
    use SeedsMetaAccount;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        Http::preventStrayRequests();
        config(['moxdop.anthropic.api_key' => 'test-anthropic-value']);
        CoreIntegration::factory()->anthropic()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        $this->seedMetaAccount();
    }

    private function page(string $tab): Testable
    {
        return Livewire::actingAs($this->admin)->test(OverviewPage::class, ['assetId' => (string) $this->asset->id, 'tab' => $tab]);
    }

    /** @return array<string, string> */
    private function creative(string $service, string $angle, string $text, string $headline = 'Ankara’da implant muayenesi'): array
    {
        return ['service' => $service, 'angle' => $angle, 'primary_text' => $text, 'headline' => $headline, 'description' => 'Randevu alın',
            'video_hook' => 'Hekim muayene odasında: “İmplant süreci nasıl işler?”', 'test' => 'Süreç anlatımı soru açılışına karşı.'];
    }

    public function test_creatives_keep_only_offering_services_grounded_numbers_and_compliant_text(): void
    {
        MetaCreativesAgent::fake([['items' => [
            $this->creative('diş implantı', 'Süreç', 'Çankaya şubemizde implant tedavisi muayene ile başlar; hekimimiz size uygun planı anlatır. Randevu için formu doldurun.'),
            $this->creative('Diş Beyazlatma', 'Uydurma', 'Beyazlatma hizmetimizle tanışın, hemen randevu alın ve gülüşünüzü yenileyin.'),
            $this->creative('Zirkonyum Kaplama', 'İndirim', 'Zirkonyum kaplamada %35 indirim fırsatını kaçırmayın, randevunuzu şimdi alın.'),
            $this->creative('Zirkonyum Kaplama', 'Garanti', 'Garantili ve ağrısız zirkonyum kaplama için hemen kliniğimize gelin, fark edin.'),
            $this->creative('Zirkonyum Kaplama', 'Link', 'Ayrıntılar için https://baska.test/zirkonyum adresine bakın ve hemen randevu alın.'),
            $this->creative('Zirkonyum Kaplama', 'Doğal görünüm', 'Zirkonyum kaplama ile doğal görünen dişler: muayenede size uygun seçenekleri anlatıyoruz.', str_repeat('Zirkonyum ', 8)),
        ]]]);

        $this->page('creatives')->call('runAi', MetaAssistant::OP_CREATIVES);

        MetaCreativesAgent::assertPrompted(fn ($prompt): bool => str_contains((string) $prompt->prompt, 'İmplant video reklamı') && str_contains((string) $prompt->prompt, 'https://panorama.test/implant/'));
        $rows = Suggestion::query()->where('action_type', 'meta_creative')->orderBy('id')->get();
        $this->assertSame(['Kreatif: Diş İmplantı · Süreç', 'Kreatif: Zirkonyum Kaplama · Doğal görünüm'], $rows->pluck('title')->all());
        $this->assertTrue($rows->every(fn (Suggestion $s): bool => $s->channel === 'meta' && $s->prompt_version_id !== null));
        $this->assertLessThanOrEqual(MetaAssistant::HEADLINE_MAX, mb_strlen((string) $rows[1]->action['headline']));
        $this->assertSame('ready', app(MetaAssistant::class)->state($this->asset->id, MetaAssistant::OP_CREATIVES)['status']);
        $this->page('creatives')->assertSee('Kreatif: Diş İmplantı · Süreç')->assertSee('Video kancası')->assertDontSee('Diş Beyazlatma')->assertSee('Kreatif öner: 2 kreatif önerisi.');
    }

    public function test_structure_needs_existing_names_and_numbers_from_the_pack(): void
    {
        MetaStructureAgent::fake([['items' => [
            ['title' => 'İzmir setini kapatıp Ankara’ya taşı', 'kind' => 'structure', 'service' => 'Diş İmplantı', 'campaign' => 'Genel Trafik', 'adsets' => ['İzmir geniş'],
                'reason' => 'Genel Trafik 1400 harcadı, 0 sonuç.', 'steps' => "Reklam setini durdurun.\nBütçeyi Diş İmplantı Lead Ankara kampanyasına aktarın."],
            ['title' => 'Site ziyaretçilerine yeniden pazarlama', 'kind' => 'remarketing', 'service' => 'Diş İmplantı', 'campaign' => 'Yeni: İmplant yeniden pazarlama', 'adsets' => ['Yeni: Site ziyaretçileri 30 gün'],
                'reason' => 'İmplant kampanyası 56 sonuç getirdi; ziyaretçiler tekrar hedeflenmiyor.', 'steps' => 'Özel hedef kitle oluşturun: site ziyaretçileri.'],
            ['title' => 'Uydurma kampanya', 'kind' => 'structure', 'service' => '', 'campaign' => 'Olmayan Kampanya', 'adsets' => [], 'reason' => 'x', 'steps' => 'y'],
            ['title' => 'Uydurma sayı', 'kind' => 'structure', 'service' => '', 'campaign' => 'Diş İmplantı Lead Ankara', 'adsets' => ['İmplant Ankara 35+'], 'reason' => 'Sonuçlar 873 arttı.', 'steps' => 'Bütçeyi artırın.'],
        ]]]);

        $this->page('strategy')->call('runAi', MetaAssistant::OP_STRUCTURE);

        MetaStructureAgent::assertPrompted(fn ($prompt): bool => str_contains((string) $prompt->prompt, 'LINK_CLICKS') && str_contains((string) $prompt->prompt, 'Çankaya şubesi'));
        $this->assertSame(['İzmir setini kapatıp Ankara’ya taşı', 'Site ziyaretçilerine yeniden pazarlama'],
            Suggestion::query()->where('action_type', 'meta_structure')->orderBy('id')->pluck('title')->all());
        $this->page('strategy')->assertSee('Site ziyaretçilerine yeniden pazarlama')->assertDontSee('Uydurma');

        $pack = app(MetaAssistant::class)->structurePack($this->asset->load('brand'));
        $pause = ['title' => 'Kampanyayı kapat', 'kind' => 'structure', 'service' => '', 'campaign' => 'Genel Trafik', 'adsets' => [], 'reason' => 'Sonuç yok.', 'steps' => 'Kampanyayı kapatın.'];
        $this->assertCount(1, MetaAssistant::validateStructure(['items' => [$pause]], $pack));
        $pack['account']['results'] = 3;
        $this->assertSame([], MetaAssistant::validateStructure(['items' => [$pause]], $pack), 'little data: no pause proposal');
    }

    public function test_landing_targets_must_be_the_ads_own_pages_or_forms(): void
    {
        MetaLandingAgent::fake([['items' => [
            ['target' => 'https://panorama.test/implant/', 'problem' => 'Sayfada randevu formu yok.', 'change' => 'Sayfanın üstüne kısa randevu formu ekleyin.', 'reason' => 'Sayfa 2800 harcamayla 56 sonuç aldı.'],
            ['target' => 'https://baska.test/kampanya', 'problem' => 'Başka alan adı.', 'change' => 'Sayfayı düzeltin.', 'reason' => 'Harcama 1400.'],
            ['target' => 'https://panorama.test/olmayan/', 'problem' => 'Uydurma sayfa.', 'change' => 'x', 'reason' => 'y'],
            ['target' => 'https://panorama.test/implant/', 'problem' => 'Güven eksik.', 'change' => 'Garantili sonuç yazın.', 'reason' => 'Sayfa 56 sonuç aldı.'],
        ]]]);

        $this->page('strategy')->call('runAi', MetaAssistant::OP_LANDING);

        MetaLandingAgent::assertPrompted(fn ($prompt): bool => str_contains((string) $prompt->prompt, 'İmplant tedavisi süreci'));
        $rows = Suggestion::query()->where('action_type', 'meta_landing')->orderBy('id')->get();
        $this->assertSame(['Sayfa: /implant/', 'Sayfa: /kampanya'], $rows->pluck('title')->all());
        $this->assertSame(0, ExternalWriteAction::query()->count());
    }

    public function test_operator_edit_is_locked_and_a_new_ai_version_is_only_a_change_proposal(): void
    {
        $first = $this->creative('Diş İmplantı', 'Süreç', 'Çankaya şubemizde implant tedavisi muayene ile başlar; hekimimiz size uygun planı anlatır.');
        $second = $this->creative('Diş İmplantı', 'Süreç', 'İmplant tedavisinde ilk adım muayenedir; Çankaya şubemizde hekimimiz süreci adım adım anlatır.');
        MetaCreativesAgent::fake([['items' => [$first]], ['items' => [$second]]]);

        $page = $this->page('creatives')->call('runAi', MetaAssistant::OP_CREATIVES);
        $suggestion = Suggestion::query()->where('action_type', 'meta_creative')->sole();
        $page->call('startEdit', $suggestion->id)->set('editText', 'Operatör metni: implant muayenesi Çankaya’da.')->call('saveEdit');
        $this->assertTrue((bool) $suggestion->fresh()->action['locked']);

        $page->call('runAi', MetaAssistant::OP_CREATIVES);

        $suggestion->refresh();
        $this->assertSame('Operatör metni: implant muayenesi Çankaya’da.', $suggestion->action['text'], 'AI never overwrites the operator');
        $this->assertStringContainsString('ilk adım muayenedir', (string) $suggestion->action['change_proposal']['primary_text']);
        $page->call('setTab', 'creatives')->assertSee('Değişiklik önerisi')->assertSee('elle düzenlendi')
            ->call('takeProposal', $suggestion->id);
        $this->assertStringContainsString('ilk adım muayenedir', (string) $suggestion->fresh()->action['primary_text']);
    }

    public function test_non_operational_brand_gets_no_ai(): void
    {
        $this->brand->customer->forceFill(['status' => CustomerStatus::Inactive])->save();
        MetaCreativesAgent::fake();
        MetaStructureAgent::fake();
        MetaLandingAgent::fake();

        $this->page('todo')->call('runAi', MetaAssistant::OP_CREATIVES)->call('runAi', MetaAssistant::OP_STRUCTURE)->call('runAi', MetaAssistant::OP_LANDING);
        app(MetaAssistant::class)->run($this->asset->id, MetaAssistant::OP_STRUCTURE);

        MetaCreativesAgent::assertNeverPrompted();
        MetaStructureAgent::assertNeverPrompted();
        MetaLandingAgent::assertNeverPrompted();
        $this->assertSame('Marka operasyonel değil; AI çalışmaz.', app(MetaAssistant::class)->state($this->asset->id, MetaAssistant::OP_STRUCTURE)['message']);
    }
}
