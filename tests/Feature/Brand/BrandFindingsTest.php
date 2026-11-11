<?php

namespace Tests\Feature\Brand;

use App\Livewire\Operator\Portfolio\BrandFindingsCard;
use App\Mcp\Tools\BrandFindings;
use App\Models\AgencySetting;
use App\Models\Brand;
use App\Models\BrandFinding;
use App\Models\User;
use App\Services\Brand\Findings;
use App\Services\Moxi\MoxiTools;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
