<?php

namespace Tests\Feature\Panel;

use App\Livewire\Panel\InboxPage;
use App\Panel\Jobs\ImportPanelBackup;
use App\Panel\Models\PanelConversation;
use App\Panel\Models\PanelImport;
use App\Panel\Models\PanelMessage;
use App\Panel\Models\PanelPerson;
use App\Panel\Models\PanelRequest;
use App\Panel\Models\PanelTicket;
use App\Panel\Services\PanelBackups;
use App\Panel\Support\PanelContext;
use App\Panel\Support\PanelStages;
use App\Services\WhatsApp\Backup\WhatsAppBackupReader;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PDO;

/** Sürüm 2: the inbox shows a brand its own requests and chats only; a backup adds chats without making leads. */
class PanelInboxTest extends PanelTestCase
{
    private string $key = '0f1e2d3c4b5a69788796a5b4c3d2e1f00112233445566778899aabbccddeeff0';

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    /** @param  list<array{0: string, 1: string, 2: list<array{0: string, 1: int, 2: string, 3: int, 4: string}>}>  $chats */
    private function backup(array $chats): string
    {
        $path = tempnam(sys_get_temp_dir(), 'panel-msgstore');
        $this->files[] = $path;
        $pdo = new PDO('sqlite:'.$path);
        $pdo->exec('CREATE TABLE jid (_id INTEGER PRIMARY KEY, user TEXT, server TEXT, raw_string TEXT);
            CREATE TABLE chat (_id INTEGER PRIMARY KEY, jid_row_id INTEGER);
            CREATE TABLE message (_id INTEGER PRIMARY KEY, chat_row_id INTEGER, from_me INTEGER, key_id TEXT, timestamp INTEGER, message_type INTEGER, text_data TEXT);
            CREATE TABLE jid_map (lid_row_id INTEGER, jid_row_id INTEGER);');
        foreach ($chats as $index => [$user, $server, $messages]) {
            $pdo->prepare('INSERT INTO jid (_id, user, server) VALUES (?, ?, ?)')->execute([$index + 1, $user, $server]);
            $pdo->prepare('INSERT INTO chat (_id, jid_row_id) VALUES (?, ?)')->execute([$index + 1, $index + 1]);
            foreach ($messages as [$keyId, $fromMe, $at, $type, $text]) {
                $pdo->prepare('INSERT INTO message (chat_row_id, from_me, key_id, timestamp, message_type, text_data) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$index + 1, $fromMe, $keyId, Carbon::parse($at, 'Europe/Istanbul')->getTimestampMs(), $type, $text]);
            }
        }
        $pdo = null;
        $iv = random_bytes(16);
        $tag = '';
        $cipher = openssl_encrypt(gzcompress((string) file_get_contents($path)), 'aes-256-gcm', WhatsAppBackupReader::encryptionKey($this->key), OPENSSL_RAW_DATA, $iv, $tag);
        $prefix = "\x08\x01\x1a\x12\x0a\x10".$iv;
        $file = chr(strlen($prefix))."\x01".$prefix.$cipher.$tag;

        return $file.md5($file, true);
    }

    /** Uploads the file as the browser does (begin, pieces) and runs the queued job in place. */
    private function import(string $content, ?string $key = null): PanelImport
    {
        Queue::fake();
        $this->enter($this->managerA, $this->brandA);
        $page = Livewire::test(InboxPage::class)->set('channel', 'whatsapp');
        $page->call('beginBackup', 'msgstore.db.crypt15', strlen($content));
        $import = PanelImport::query()->latest('id')->firstOrFail();
        $half = intdiv(strlen($content), 2);
        foreach ([[0, substr($content, 0, $half)], [$half, substr($content, $half)]] as [$offset, $bytes]) {
            $this->asPanel($this->managerA)->call('POST', $this->url('/gelen-kutusu/yedek/'.$import->id.'/parca?offset='.$offset), [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], $bytes)->assertOk();
        }
        $this->enter($this->managerA, $this->brandA);
        $page->call('extractBackup', $key ?? $this->key);
        app(PanelContext::class)->leave();
        if (PanelImport::query()->withoutGlobalScopes()->find($import->id)->status === 'queued') {
            Queue::assertPushed(ImportPanelBackup::class);
            app(PanelBackups::class)->run($import->id);
        }
        $this->enter($this->managerA, $this->brandA);

        return $import->fresh();
    }

    public function test_form_and_call_requests_sit_in_their_tabs_and_stay_in_the_brand(): void
    {
        $this->enter($this->managerA, $this->brandA);
        $request = PanelRequest::query()->firstOrFail();
        Livewire::test(InboxPage::class)->set('channel', 'web_form')->assertSee('Ayşe Akasya')->assertDontSee('Bora Begonya')
            ->set('open', $request->id)->assertSee('Kaydın tamamını aç')
            ->set('note', 'Arandı, perşembe gelecek.')->call('addNote')->assertHasNoErrors()
            ->call('setStage', PanelStages::REACHED)
            ->set('appointment', '2026-12-01T10:30')->call('setAppointment')->assertHasNoErrors()
            ->call('remind', 3);
        $this->assertNotNull($request->fresh()->handled_at, 'working on a request takes it off the waiting list');
        $this->assertSame(PanelStages::APPOINTMENT, $this->personA->fresh()->stage);
        $this->assertSame(1, $this->personA->reminders()->count());
        // The call tab of this brand is empty; the other brand's call is not here.
        Livewire::test(InboxPage::class)->set('channel', 'call')->assertSee('Henüz çağrı kaydı yok')->assertDontSee('Bora Begonya');

        $this->enter($this->managerB, $this->brandB);
        Livewire::test(InboxPage::class)->set('channel', 'web_form')->set('open', $request->id)->assertDontSee('Ayşe Akasya')->set('note', 'x')->call('addNote')->assertNotFound();
    }

    public function test_a_user_sees_only_the_channels_of_their_role_and_their_own_people(): void
    {
        $viewer = $this->member($this->brandA, 'izleyen@example.test', role: 'İzleyici');
        $this->asPanel($viewer)->get($this->url('/gelen-kutusu'))->assertForbidden();
        $sales = $this->member($this->brandA, 'satis@example.test', role: 'Satış');
        $membership = $sales->memberships()->where('brand_id', $this->brandA->id)->firstOrFail();
        $membership->forceFill(['overrides' => ['allow' => [], 'deny' => ['inbox.call', 'leads.all']]])->save();
        $this->enter($sales, $this->brandA);
        Livewire::test(InboxPage::class)->assertSee('Web formu')->assertDontSee('Çağrı')->set('channel', 'web_form')->assertDontSee('Ayşe Akasya');
        $this->personA->forceFill(['assigned_user_id' => $sales->id])->save();
        Livewire::test(InboxPage::class)->set('channel', 'web_form')->assertSee('Ayşe Akasya');
    }

    public function test_an_unconnected_channel_asks_the_agency_once(): void
    {
        $this->enter($this->managerA, $this->brandA);
        Livewire::test(InboxPage::class)->set('channel', 'instagram')->assertSee('Instagram henüz bağlı değil')->call('requestConnection')->call('requestConnection');
        $this->assertSame(1, PanelTicket::query()->where('subject', 'Instagram bağlantısı')->count());
    }

    public function test_a_backup_brings_chats_without_making_leads_and_a_second_one_adds_only_what_is_missing(): void
    {
        $chats = [
            ['905321112233', 's.whatsapp.net', [['A1', 0, '2026-09-01 10:00', 0, 'Merhaba, kontrol için yazıyorum'], ['A2', 1, '2026-09-01 10:05', 0, 'Perşembe uygun mu?']]],
            ['905359998877', 's.whatsapp.net', [['B1', 0, '2026-08-01 09:00', 0, 'Fiyat alabilir miyim']]],
            ['1203630000', 'g.us', [['G1', 0, '2026-08-02 09:00', 0, 'Grup mesajı']]],
        ];
        $this->enter($this->managerA, $this->brandA);
        $people = PanelPerson::query()->count();
        $import = $this->import($this->backup($chats));
        $this->assertSame('completed', $import->status);
        $this->assertNull($import->getRawOriginal('secret'), 'the key is wiped when the run ends');
        $this->assertFileDoesNotExist($import->file());
        $this->assertSame(['chats' => 2, 'new_chats' => 2, 'messages' => 3, 'linked' => 1, 'groups' => 1, 'other' => 0], $import->stats);
        $this->assertSame($people, PanelPerson::query()->count(), 'old chats do not become leads');
        $this->assertStringNotContainsString('Fiyat alabilir', (string) DB::table('panel_messages')->where('external_id', 'B1')->value('body'));

        $known = PanelConversation::query()->where('contact_key', '5321112233')->firstOrFail();
        $this->assertSame($this->personA->id, $known->person_id, 'a chat with a known phone hangs on that person');
        $old = PanelConversation::query()->where('contact_key', '5359998877')->firstOrFail();
        Livewire::test(InboxPage::class)->set('channel', 'whatsapp')->assertSee('Eski sohbet')->assertSee('Ayşe Akasya')->assertDontSee('Grup mesajı')
            ->set('open', $old->id)->assertSee('Fiyat alabilir miyim')->assertSee('Adaya çevir')->call('convert');
        $this->assertSame($people + 1, PanelPerson::query()->count());
        $this->assertSame('whatsapp', $old->fresh()->person->source);

        // The same backup a month later with one more message: only that one is added.
        $chats[1][2][] = ['B2', 0, '2026-10-01 09:00', 0, 'Yeniden yazıyorum'];
        $second = $this->import($this->backup($chats));
        $this->assertSame(1, $second->stats['messages']);
        $this->assertSame(0, $second->stats['new_chats']);
        $this->assertSame(4, PanelMessage::query()->count());

        // The other brand sees none of it; undo takes back only what that backup added and leaves the lead.
        $this->asPanel($this->managerB)->call('POST', $this->url('/gelen-kutusu/yedek/'.$second->id.'/parca?offset=0'), [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], 'x')->assertNotFound();
        $this->enter($this->managerB, $this->brandB);
        Livewire::test(InboxPage::class)->set('channel', 'whatsapp')->assertDontSee('Fiyat alabilir')->assertSee('WhatsApp henüz bağlı değil')
            ->set('open', $old->id)->call('convert')->assertNotFound();
        $this->enter($this->managerA, $this->brandA);
        Livewire::test(InboxPage::class)->set('channel', 'whatsapp')->call('undoBackup', $second->id);
        $this->assertSame(3, PanelMessage::query()->count());
        $this->assertSame($people + 1, PanelPerson::query()->count());
    }

    public function test_a_wrong_key_keeps_the_file_for_another_try_and_only_the_manager_uploads(): void
    {
        $sales = $this->member($this->brandA, 'satis@example.test', role: 'Satış');
        $this->asPanel($sales)->call('POST', $this->url('/gelen-kutusu/yedek/1/parca?offset=0'), [], [], [], ['CONTENT_TYPE' => 'application/octet-stream'], 'x')->assertForbidden();
        $import = $this->import($this->backup([['905321112233', 's.whatsapp.net', [['A1', 0, '2026-09-01 10:00', 0, 'Selam']]]]), str_repeat('ab', 32));
        $this->assertSame('uploaded', $import->status);
        $this->assertStringContainsString('Anahtar bu yedeği açmadı', (string) $import->error);
        $this->assertFileExists($import->file());
        Livewire::test(InboxPage::class)->set('channel', 'whatsapp')->call('extractBackup', 'kısa')->assertReturned(false);
        @unlink($import->file());

        $this->enter($sales, $this->brandA);
        Livewire::test(InboxPage::class)->set('channel', 'whatsapp')->assertDontSee('WhatsApp yedeği yükle')->call('beginBackup', 'msgstore.db.crypt15', 1000)->assertForbidden();
    }
}
