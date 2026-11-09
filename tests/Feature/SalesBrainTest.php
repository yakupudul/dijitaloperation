<?php

namespace Tests\Feature;

use App\Jobs\Sales\LearnFromDeal;
use App\Livewire\Operator\Sales\SalesPage;
use App\Models\AgencySetting;
use App\Models\CoreIntegration;
use App\Models\SalesLesson;
use App\Models\User;
use App\Services\Sales\SalesBrain;
use App\Services\Sales\SalesLeads;
use App\Services\Sales\SalesReplyWriter;
use App\Services\Sales\StandingRules;
use App\Services\WhatsApp\WhatsAppConnection;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Satış beyni (yakup 2026-11-08): every closed deal gets a karne, Claude proposes lessons and questions, the operator
 * approves, edits, answers or rejects them in Satış › Ayarlar; only approved ones reach the drafts.
 */
class SalesBrainTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        AgencySetting::query()->firstOrCreate([], ['agency_name' => 'Moximu']);
        Http::fake();
        $this->actingAs($this->admin);
    }

    public function test_a_closed_deal_gets_a_karne_and_the_hourly_sweep_queues_it_once(): void
    {
        Queue::fake();
        $leads = app(SalesLeads::class);
        $lead = $leads->quickAdd(['name' => 'Dr. Can', 'phone' => '0532 100 20 30', 'source' => 'whatsapp'], $this->admin);
        $leads->event($lead, 'message_in', 'Fiyat nedir?', now()->subDays(3));
        $leads->event($lead, 'message_out', 'Aylık 14.000 TL.', now()->subDays(3)->addHours(2));
        $leads->setStage($lead, 'lost', 'price', $this->admin);

        $karne = app(SalesBrain::class)->karne($lead->fresh());
        $this->assertSame('lost', $karne['outcome']);
        $this->assertSame(1, $karne['in']);
        $this->assertSame(2, $karne['first_reply_hours']);
        $this->assertStringContainsString('Olumsuz', $karne['line']);

        $this->assertSame(1, SalesBrain::sweep());
        $this->assertSame(0, SalesBrain::sweep(), 'a deal is read once');
        Queue::assertPushed(LearnFromDeal::class, 1);
        $this->assertSame('queued', $lead->fresh()->lesson_status);

        // No Claude API and no subscription Claude here: the deal is marked, nothing breaks.
        $this->assertFalse(app(SalesBrain::class)->learn($lead->id));
        $this->assertContains($lead->fresh()->lesson_status, ['failed', 'waiting']);
    }

    public function test_lessons_wait_for_approval_and_only_approved_ones_reach_the_drafts(): void
    {
        $leads = app(SalesLeads::class);
        $lead = $leads->quickAdd(['name' => 'Dr. Can', 'phone' => '0532 100 20 30', 'source' => 'whatsapp'], $this->admin);
        $leads->setStage($lead, 'won', null, $this->admin);
        $brain = app(SalesBrain::class);
        $brain->store($lead, ['verdict' => 'Hızlı fiyat verildiği için kazanıldı.', 'lessons' => [
            ['kind' => 'yontem', 'text' => 'Fiyat sorana aynı saat içinde paket fiyatını yaz.'],
            ['kind' => 'karar', 'text' => 'Tek şubeli kliniğe 6 ay peşinde web sitesi hediye et.'],
        ], 'questions' => ['Veteriner kliniklerine aynı fiyatı mı veriyoruz?']]);
        $brain->store($lead, ['verdict' => '', 'lessons' => [['kind' => 'yontem', 'text' => 'Fiyat sorana aynı saat içinde paket fiyatını yaz!']], 'questions' => []]);

        $this->assertSame(3, SalesLesson::query()->proposed()->count(), 'the same lesson is not proposed twice');
        $this->assertSame('Hızlı fiyat verildiği için kazanıldı.', $lead->fresh()->lesson_verdict);
        $this->assertSame([], SalesBrain::forPrompt());

        $method = SalesLesson::query()->where('kind', 'yontem')->sole();
        $decision = SalesLesson::query()->where('kind', 'karar')->sole();
        $question = SalesLesson::query()->where('kind', 'soru')->sole();
        Livewire::test(SalesPage::class)->set('tab', 'ayarlar')
            ->assertSee('Satış beyni')->assertSee('Veteriner kliniklerine aynı fiyatı mı veriyoruz?')
            ->set('lessonEdits.'.$method->id, 'Fiyat sorana bir saat içinde paket fiyatını yaz.')->call('approveLesson', $method->id)
            ->call('rejectLesson', $decision->id)
            ->set('lessonAnswers.'.$question->id, 'Evet, aynı fiyat.')->call('answerLesson', $question->id)
            ->set('newLesson', ['kind' => 'karar', 'text' => 'Kürtaj SEO işi alınmaz.'])->call('addLesson')
            ->assertHasNoErrors();

        $prompt = SalesBrain::forPrompt();
        $this->assertContains('Yöntem: Fiyat sorana bir saat içinde paket fiyatını yaz.', $prompt);
        $this->assertContains('Karar: Kürtaj SEO işi alınmaz.', $prompt);
        $this->assertContains('Soru: Veteriner kliniklerine aynı fiyatı mı veriyoruz? Cevap: Evet, aynı fiyat.', $prompt);
        $this->assertNotContains('Karar: Tek şubeli kliniğe 6 ay peşinde web sitesi hediye et.', $prompt);
        $this->assertSame($prompt, app(SalesReplyWriter::class)->payload($lead->fresh())['learned']);

        Livewire::test(SalesPage::class)->set('tab', 'sonuclar')->assertSee('Karne')->assertSee('Dr. Can')->assertSee('Hızlı fiyat verildiği için kazanıldı.');
    }

    public function test_standing_rules_and_the_whatsapp_brain_questions_are_answered_from_sales_settings(): void
    {
        CoreIntegration::query()->create(['provider' => WhatsAppConnection::PROVIDER, 'name' => 'WhatsApp Business', 'status' => CoreIntegration::STATUS_ACTIVE,
            'config' => ['business_context' => 'Fiyatlar KDV hariç.', 'brain' => ['open_questions' => ['Web sitesi kaç günde teslim?']]]]);

        Livewire::test(SalesPage::class)->set('tab', 'ayarlar')
            ->assertSet('rulesText', 'Fiyatlar KDV hariç.')->assertSee('Web sitesi kaç günde teslim?')
            ->set('waAnswers.0', '15 iş günü.')->call('answerBrainQuestion', 0)
            ->assertDontSee('Web sitesi kaç günde teslim? <span', false);
        $this->assertStringContainsString('- Web sitesi kaç günde teslim? → 15 iş günü.', StandingRules::text());
        $this->assertSame([], StandingRules::brainQuestions());

        Livewire::test(SalesPage::class)->set('tab', 'ayarlar')->set('rulesText', 'Yeni kural.')->call('saveRules');
        $this->assertSame('Yeni kural.', StandingRules::text());
    }
}
