<?php

namespace Tests\Feature\Social;

use App\Jobs\Social\DraftSocialReply;
use App\Livewire\Operator\Inbox\InboxPage;
use App\Models\SalesLead;
use App\Models\SocialAccount;
use App\Models\SocialThread;
use App\Models\User;
use App\Services\Social\SocialInbox;
use App\Services\Social\SocialReplyWriter;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ADR-083 phase 2: Instagram / Facebook DMs and comments are read into threads, a reply draft is asked for the other
 * person's last word, and a reply goes out only on the Admin's click (DM inside 24 hours).
 */
final class SocialInboxTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private SocialAccount $instagram;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-09 12:00', 'Europe/Istanbul'));
        $this->seed(RoleAndPermissionSeeder::class);
        config(['moxdop.meta.app_id' => '123', 'moxdop.meta.app_secret' => 'sec']);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->instagram = SocialAccount::query()->create(['platform' => 'instagram', 'external_id' => 'IG1', 'username' => 'moximucom', 'access_token' => 'page-token', 'page_id' => 'PAGE1']);
    }

    private function fakeGraph(): void
    {
        Http::fake([
            '*/PAGE1/conversations*' => Http::response(['data' => [[
                'id' => 'CONV1', 'updated_time' => '2026-11-09T08:30:00+0000',
                'participants' => ['data' => [['id' => 'IG1', 'username' => 'moximucom'], ['id' => 'USER9', 'username' => 'drayse']]],
                'messages' => ['data' => [
                    ['id' => 'm2', 'message' => 'Fiyatlarınız nedir?', 'from' => ['id' => 'USER9', 'username' => 'drayse'], 'created_time' => '2026-11-09T08:30:00+0000'],
                    ['id' => 'm1', 'message' => 'Merhaba', 'from' => ['id' => 'USER9', 'username' => 'drayse'], 'created_time' => '2026-11-09T08:29:00+0000'],
                ]],
            ]]]),
            '*/IG1/media*' => Http::response(['data' => [[
                'id' => 'MEDIA1', 'permalink' => 'https://www.instagram.com/p/x/', 'caption' => 'Klinik sitelerinde üç hata',
                'comments' => ['data' => [
                    ['id' => 'C1', 'text' => 'Çok faydalı, teşekkürler', 'username' => 'klinikx', 'timestamp' => '2026-11-09T07:00:00+0000',
                        'replies' => ['data' => [['id' => 'C1R', 'text' => 'Biz teşekkür ederiz', 'username' => 'moximucom', 'timestamp' => '2026-11-09T07:10:00+0000']]]],
                    ['id' => 'C2', 'text' => 'Bizim site de yavaş, bakabilir misiniz?', 'username' => 'dentaly', 'timestamp' => '2026-11-09T07:30:00+0000'],
                ]],
            ]]]),
            '*/PAGE1/messages' => Http::response(['recipient_id' => 'USER9', 'message_id' => 'mid.out']),
            '*/C2/replies' => Http::response(['id' => 'C2R']),
        ]);
    }

    public function test_sync_reads_dms_and_comments_and_asks_drafts_only_for_their_last_word(): void
    {
        Queue::fake();
        $this->fakeGraph();
        $stats = app(SocialInbox::class)->sync();

        $this->assertSame([], $stats['errors']);
        $dm = SocialThread::query()->where('kind', 'dm')->sole();
        $this->assertSame('drayse', $dm->author_name);
        $this->assertSame('USER9', $dm->author_id);
        $this->assertSame('in', $dm->last_direction);
        $this->assertSame('Fiyatlarınız nedir?', $dm->last_text);
        $this->assertSame(2, $dm->messages()->count());

        $answered = SocialThread::query()->where('external_id', 'C1')->sole();
        $this->assertSame(SocialThread::ANSWERED, $answered->status);
        $open = SocialThread::query()->where('external_id', 'C2')->sole();
        $this->assertSame(SocialThread::OPEN, $open->status);
        $this->assertSame('MEDIA1', $open->post_external_id);

        Queue::assertPushed(DraftSocialReply::class, 2); // the DM and C2, not the answered comment

        // A second read adds nothing and asks nothing again.
        app(SocialInbox::class)->sync();
        $this->assertSame(3, SocialThread::query()->count());
        Queue::assertPushed(DraftSocialReply::class, 2);
    }

    public function test_draft_is_stored_and_a_service_question_opens_a_sales_card(): void
    {
        Queue::fake();
        $this->fakeGraph();
        app(SocialInbox::class)->sync();
        $dm = SocialThread::query()->where('kind', 'dm')->sole();

        app(SocialReplyWriter::class)->store($dm, ['action' => 'reply', 'reply' => 'Merhaba, Klinik SEO ve İşletme Profili 14.000 TL + KDV/ay.', 'lead' => true, 'note' => 'Fiyat sordu.']);

        $dm->refresh();
        $this->assertSame('ready', $dm->draft_status);
        $this->assertNotNull($dm->sales_lead_id);
        $lead = SalesLead::query()->findOrFail($dm->sales_lead_id);
        $this->assertSame('instagram', $lead->source);

        $spam = SocialThread::query()->where('external_id', 'C2')->sole();
        app(SocialReplyWriter::class)->store($spam, ['action' => 'ignore', 'reply' => '', 'lead' => false, 'note' => 'Bot.']);
        $this->assertSame(SocialThread::IGNORED, $spam->refresh()->status);
    }

    public function test_send_all_sends_ready_drafts_through_the_right_endpoints(): void
    {
        Queue::fake();
        $this->fakeGraph();
        app(SocialInbox::class)->sync();
        SocialThread::query()->where('status', SocialThread::OPEN)->update(['draft' => 'Teşekkürler, yazıyoruz.', 'draft_status' => 'ready']);

        // Social messages are answered in the Gelen kutusu (Aşama 2); the old Mesajlar address points there.
        $this->actingAs($this->admin)->get('/sosyal?sekme=mesajlar')->assertRedirect('/gelen-kutusu');
        Livewire::actingAs($this->admin)->test(InboxPage::class)
            ->assertSee('Hazır sosyal cevapları gönder (2)')->assertSee('drayse')->assertSee('Instagram mesajı')
            ->call('sendReady')
            ->assertSet('message', '2 cevap gönderildi.');

        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/PAGE1/messages') && str_contains($r->body(), 'USER9') && str_contains($r->body(), 'RESPONSE'));
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/C2/replies') && str_contains(urldecode($r->body()), 'Teşekkürler'));
        $this->assertSame(0, SocialThread::query()->where('status', SocialThread::OPEN)->count());
        $this->assertSame(2, SocialThread::query()->where('status', SocialThread::ANSWERED)->where('external_id', '!=', 'C1')->count());
    }

    public function test_dm_older_than_24_hours_is_not_sent(): void
    {
        Queue::fake();
        $this->fakeGraph();
        app(SocialInbox::class)->sync();
        $this->travel(2)->days();
        $dm = SocialThread::query()->where('kind', 'dm')->sole();
        $dm->update(['draft' => 'Merhaba', 'draft_status' => 'ready']);

        $result = app(SocialInbox::class)->sendAllReady();

        $this->assertSame(0, $result['sent']);
        $this->assertStringContainsString('24 saat', (string) $dm->refresh()->send_error);
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/messages'));
    }
}
