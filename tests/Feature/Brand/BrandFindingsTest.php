<?php

namespace Tests\Feature\Brand;

use App\Livewire\Operator\Portfolio\BrandFindingsCard;
use App\Livewire\Operator\Workspace\BrandScorecardTab;
use App\Mcp\Tools\BrandFindings;
use App\Models\AgencySetting;
use App\Models\Brand;
use App\Models\BrandBaseline;
use App\Models\BrandFinding;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\Brand\Findings;
use App\Services\Brand\NewOrder;
use App\Services\Moxi\MoxiTools;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Marka sayfası › Bulgular (Marka Düzeni 2.1 / 2.2): written with evidence, handed over with "Çöz", the result returns to the row. */
class BrandFindingsTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        AgencySetting::query()->firstOrCreate([], ['agency_name' => 'Moximu']);
        Http::fake();
        $this->actingAs($admin);
        $this->brand = Brand::factory()->create(['name' => 'Özel Demiderm Polikliniği']);
    }

    public function test_a_finding_needs_evidence_and_goes_from_coz_to_the_result_on_the_same_row(): void
    {
        $refused = MoxiTools::callMcp(BrandFindings::class, ['action' => 'save', 'brand_id' => $this->brand->id, 'title' => 'Kanıtsız bulgu']);
        $this->assertStringContainsString('kanıtsız', mb_strtolower($refused));
        $this->assertSame(0, BrandFinding::query()->count());

        MoxiTools::callMcp(BrandFindings::class, ['action' => 'save', 'brand_id' => $this->brand->id, 'area' => 'Web sitesi', 'approval' => 'redirect',
            'title' => 'Alsancak erkek lazer için birbirinin kopyası 3 sayfa var', 'evidence' => '90 günde 100, 50 ve 49 tık', 'saw' => 'Üç sayfa aynı aramada yarışıyor.',
            'plan' => 'En çok tık alanı ana sayfa yaparım.', 'needs' => 'Yalnız onay.']);
        MoxiTools::callMcp(BrandFindings::class, ['action' => 'save', 'brand_id' => $this->brand->id, 'area' => 'Ölçüm', 'title' => 'Form dönüşümü 30 günde sıfır', 'evidence' => '194 WhatsApp, 78 arama var']);
        MoxiTools::callMcp(BrandFindings::class, ['action' => 'save', 'brand_id' => $this->brand->id, 'kind' => 'task', 'area' => 'Erişim', 'title' => 'Ana İşletme Profili için yetki iste']);
        MoxiTools::callMcp(BrandFindings::class, ['action' => 'save', 'brand_id' => $this->brand->id, 'kind' => 'unseen', 'area' => 'Meta reklamları', 'title' => 'Meta hesabı ödenmemiş bakiye yüzünden veri vermiyor']);
        $first = BrandFinding::query()->where('kind', 'finding')->orderBy('id')->firstOrFail();

        $early = MoxiTools::callMcp(BrandFindings::class, ['action' => 'result', 'id' => $first->id, 'result' => 'Yaptım']);
        $this->assertStringContainsString('"Çöz" denmedi', $early, 'nothing is done before the operator asks');

        $card = Livewire::test(BrandFindingsCard::class, ['brandId' => $this->brand->id])
            ->assertSee('Demiderm\'de', false)->assertSee('bulgu çözülmeyi bekliyor')->assertSee('Yönlendirme onayı')
            ->assertSee('Senin işlerin')->assertSee('Göremedim')->assertSeeHtml('data-brand-findings-all')
            ->call('solve', $first->id)->assertSee('Claude\'da', false);
        $this->assertSame('requested', $first->fresh()->status);
        $this->assertSame(1, Findings::requested());
        $list = MoxiTools::callMcp(BrandFindings::class, ['action' => 'list', 'only_requested' => true]);
        $this->assertStringContainsString('Alsancak', $list);
        $this->assertStringNotContainsString('Form dönüşümü', $list);

        MoxiTools::callMcp(BrandFindings::class, ['action' => 'result', 'id' => $first->id, 'result' => 'İki sayfa ana sayfaya yönlendirilmeye hazır.', 'url' => 'https://app.moximu.com/onarim', 'waiting' => true]);
        $this->assertSame('waiting', $first->fresh()->status);
        $card->call('$refresh')->assertSee('Onayını bekliyor')->assertSee('yönlendirilmeye hazır')->call('mark', $first->id, 'done')->assertSee('Yapılanlar ve sonuçları');
        MoxiTools::callMcp(BrandFindings::class, ['action' => 'effect', 'id' => $first->id, 'effect' => 'tık 199 → 260']);
        $card->call('$refresh')->assertSee('tık 199 → 260');

        $card->call('solveAll');
        $this->assertSame(1, Findings::requested(), 'the one open finding went; the task and the unseen row stay the operator\'s');
        $task = BrandFinding::query()->where('kind', 'task')->firstOrFail();
        $card->call('mark', $task->id, 'done');
        $this->assertSame('done', $task->fresh()->status);
    }

    public function test_a_brand_without_a_first_look_shows_nothing_and_a_member_cannot_hand_work_over(): void
    {
        Livewire::test(BrandFindingsCard::class, ['brandId' => $this->brand->id])->assertDontSee('bulgu');
        $row = app(Findings::class)->save($this->brand, ['title' => 'Bir bulgu', 'evidence' => '10 tık', 'area' => 'Web sitesi']);
        $member = User::factory()->create(['is_active' => true]);
        $member->assignRole(Roles::TEAM_MEMBER);
        $this->actingAs($member);
        Livewire::test(BrandFindingsCard::class, ['brandId' => $this->brand->id])->call('solve', $row->id)->assertForbidden();
        $this->assertSame('open', $row->fresh()->status);
    }

    public function test_new_order_sets_the_brands_open_rows_aside_and_brings_them_back(): void
    {
        Cache::flush();
        MoxiTools::callMcp(BrandFindings::class, ['action' => 'save', 'brand_id' => $this->brand->id, 'area' => 'Web sitesi', 'title' => 'Başlık eksik', 'evidence' => 'Ana sayfada başlık etiketi boş']);
        $row = fn (Brand $brand, string $key): Suggestion => Suggestion::query()->create(['brand_id' => $brand->id, 'fingerprint' => $key, 'channel' => 'search',
            'decision_key' => 'test.row', 'material_hash' => 'x', 'reason' => 'Deneme.', 'priority' => 5,
            'target_type' => 'brand', 'target_id' => $brand->id, 'action_type' => 'note', 'title' => 'Satır '.$key, 'status' => 'open']);
        $other = Brand::factory()->create(['name' => 'Başka Marka']);
        $old = $row($this->brand, 'a');
        $elsewhere = $row($other, 'b');

        $card = Livewire::test(BrandFindingsCard::class, ['brandId' => $this->brand->id])->assertSee('Bu marka eski düzende.')
            ->call('newOrder', true)->assertSee('1 açık satır kenara alındı')->assertSee('Yeni düzen açık');
        $this->assertSame('snoozed', $old->fresh()->status);
        $this->assertSame('open', data_get($old->fresh()->action, 'new_order.status'));
        $this->assertSame('open', $elsewhere->fresh()->status, 'other brands are untouched');
        $this->assertSame('snoozed', $row($this->brand, 'c')->fresh()->status, 'a row a check opens later is set aside as it is saved');
        Suggestion::query()->where('brand_id', $this->brand->id)->update(['status' => 'open']);
        $this->assertSame(2, app(NewOrder::class)->sweep(), 'the nightly sweep catches bulk-written rows');

        $card->call('newOrder', false)->assertSee('2 satır geri geldi')->assertSee('Bu marka eski düzende.');
        $this->assertSame('open', $old->fresh()->status);
        $this->assertNull(data_get($old->fresh()->action, 'new_order'));
        $this->assertNull($old->fresh()->snoozed_until);
    }

    public function test_start_numbers_are_taken_once_and_shown_beside_the_current_days(): void
    {
        $this->travelTo('2026-11-11 10:00:00');
        $json = json_decode(MoxiTools::callMcp(BrandFindings::class, ['action' => 'baseline', 'brand_id' => $this->brand->id]), true);
        $this->assertSame(['2026-10-10', '2026-11-08'], [$json['from'], $json['to']], '30 full days, ending three days back');
        $this->assertSame(['web', 'google_ads', 'meta', 'gbp'], array_keys($json['numbers']['demand']));
        $baseline = BrandBaseline::query()->where('brand_id', $this->brand->id)->sole();
        $baseline->update(['numbers' => ['demand' => ['web' => 40, 'google_ads' => 0, 'meta' => 0, 'gbp' => 0], 'demand_total' => 40, 'organic_clicks' => 200,
            'services' => [['name' => 'Lazer epilasyon', 'clicks' => 80, 'pages' => 2]]]]);

        $this->travelTo('2026-12-11 10:00:00');
        MoxiTools::callMcp(BrandFindings::class, ['action' => 'baseline', 'brand_id' => $this->brand->id]);
        $this->assertSame('2026-11-08', $baseline->fresh()->period_to->toDateString(), 'the start is not taken twice');
        Livewire::test(BrandScorecardTab::class, ['brandId' => $this->brand->id])
            ->assertSee('Başlangıç sayıları')->assertSee('Web sitesi talebi')->assertSee('Organik tık')->assertSee('Lazer epilasyon')->assertSee('200')
            ->assertDontSee('data-brand-baseline-take', false);
        Livewire::test(BrandScorecardTab::class, ['brandId' => Brand::factory()->create()->id])->assertSee('data-brand-baseline-take', false);
    }
}
