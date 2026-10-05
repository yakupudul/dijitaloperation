<?php

namespace Tests\Feature;

use App\Ai\Agents\WhatsAppBrainAgent;
use App\Ai\Agents\WhatsAppReplyAgent;
use App\Jobs\WhatsApp\ExtractWhatsAppBackup;
use App\Jobs\WhatsApp\GenerateWhatsAppSuggestion;
use App\Jobs\WhatsApp\LearnWhatsAppBrain;
use App\Livewire\Operator\WhatsApp\Inbox;
use App\Models\AgencySetting;
use App\Models\CoreIntegration;
use App\Models\User;
use App\Models\WhatsAppBackupImport;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\Backup\WhatsAppBackupImporter;
use App\Services\WhatsApp\Backup\WhatsAppBackupReader;
use App\Services\WhatsApp\WhatsAppBrain;
use App\Services\WhatsApp\WhatsAppConnection;
use App\Services\WhatsApp\WhatsAppDispatch;
use App\Services\WhatsApp\WhatsAppSuggestions;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use PDO;
use Tests\TestCase;

/**
 * The WhatsApp Business backup as a second way into the inbox: the Android msgstore.db.crypt15 is uploaded in pieces,
 * opened with the 64-digit key, its one-to-one chats stored (again only what is missing), and the brain learned from
 * them feeds the reply drafts next to the operator's instructions.
 */
final class WhatsAppBackupImportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $key;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->key = bin2hex(random_bytes(32));
        AgencySetting::query()->firstOrCreate([], ['agency_name' => 'Moximu']);
    }

    protected function tearDown(): void
    {
        foreach ([...$this->files, ...WhatsAppBackupImport::query()->get()->map->path()->all()] as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function test_a_backup_uploaded_in_pieces_and_extracted_brings_its_chats_into_the_inbox(): void
    {
        Queue::fake();
        $backup = $this->backup([
            ['905321112233', 's.whatsapp.net', [
                ['A1', 0, '2026-09-01 10:00', 0, 'Merhaba, web sitesi fiyatı nedir?'],
                ['A2', 1, '2026-09-01 10:05', 0, 'Merhaba, kurumsal site 20.000 TL.'],
                ['A3', 0, '2026-09-01 10:07', 1, 'logo'],
                ['A4', 0, '2026-09-01 10:08', 7, ''],
            ]],
            ['120363000', 'g.us', [['G1', 0, '2026-09-01 11:00', 0, 'grup mesajı']]],
            ['84512345678901', 'lid', [['L1', 0, '2026-09-02 09:00', 0, 'Gizli numaradan selam']]],
        ]);

        $import = $this->upload($backup, chunk: 1000);
        $this->assertSame('uploaded', $import->status);
        $this->actingAs($this->admin);
        Livewire::test(Inbox::class)->call('extractBackup', strtoupper(implode(' ', str_split($this->key, 16))))
            ->assertHasNoErrors()->assertSee('Yedek çıkarılıyor');
        Queue::assertPushed(ExtractWhatsAppBackup::class, fn (ExtractWhatsAppBackup $job): bool => $job->importId === $import->id);
        $this->assertSame('queued', $import->fresh()->status);

        app(WhatsAppBackupImporter::class)->run($import->id);

        $import = $import->fresh();
        $this->assertSame('completed', $import->status);
        $this->assertNull($import->getAttributes()['backup_key']);
        $this->assertFileDoesNotExist($import->path());
        $this->assertSame(['chats' => 2, 'new_chats' => 2, 'messages' => 4, 'new_messages' => 4, 'skipped_groups' => 1], array_intersect_key($import->stats, array_flip(['chats', 'new_chats', 'messages', 'new_messages', 'skipped_groups'])));
        $chat = WhatsAppConversation::query()->where('contact_id', '905321112233')->firstOrFail();
        $this->assertSame([WhatsAppBackupImporter::LINE, 'idle'], [$chat->phone_number_id, $chat->suggestion_status]);
        $this->assertSame(
            [['incoming', 'Merhaba, web sitesi fiyatı nedir?'], ['outgoing', 'Merhaba, kurumsal site 20.000 TL.'], ['incoming', '[Fotoğraf — içeriği okunmadı] logo']],
            $chat->messages()->orderBy('sent_at')->get()->map(fn (WhatsAppMessage $message): array => [$message->direction, $message->body])->all(),
        );
        $this->assertTrue(WhatsAppConversation::query()->where('contact_id', 'lid84512345678901')->whereNull('customer_id')->exists());
        Queue::assertPushed(LearnWhatsAppBrain::class);

        Livewire::test(Inbox::class)
            ->assertSee('2 görüşme yedekten geldi')
            ->assertSee('1 grup sohbeti alınmadı')
            ->assertSee('Gizli numara')
            ->assertSee('Beyin')
            ->assertSee('Canlı bağlantı (Meta)')
            ->call('selectConversation', $chat->id)
            ->assertSee('Merhaba, web sitesi fiyatı nedir?')
            ->assertSee('Mesaj üret')
            ->assertDontSee('Cevap penceresi');
    }

    public function test_a_wrong_key_keeps_the_file_so_another_key_can_be_tried(): void
    {
        Queue::fake();
        $import = $this->upload($this->backup([['905321112233', 's.whatsapp.net', [['A1', 0, '2026-09-01 10:00', 0, 'Selam']]]]));
        $this->actingAs($this->admin);

        Livewire::test(Inbox::class)->call('extractBackup', 'kisa')->assertHasErrors('backup_key');
        Livewire::test(Inbox::class)->call('extractBackup', str_repeat('ab', 32))->assertHasNoErrors();
        app(WhatsAppBackupImporter::class)->run($import->id);

        $import = $import->fresh();
        $this->assertSame(['uploaded', WhatsAppBackupReader::KEY_ERROR], [$import->status, $import->error]);
        $this->assertFileExists($import->path());
        Livewire::test(Inbox::class)->assertSee('Anahtar bu yedeği açmadı');

        Livewire::test(Inbox::class)->call('extractBackup', $this->key);
        app(WhatsAppBackupImporter::class)->run($import->id);
        $this->assertSame('completed', $import->fresh()->status);
        $this->assertSame(1, WhatsAppMessage::query()->count());
    }

    public function test_a_newer_backup_adds_only_the_missing_messages(): void
    {
        Queue::fake();
        $first = [['905321112233', 's.whatsapp.net', [['A1', 0, '2026-09-01 10:00', 0, 'Selam'], ['A2', 1, '2026-09-01 10:01', 0, 'Merhaba']]]];
        $this->extract($this->upload($this->backup($first)));
        $chat = WhatsAppConversation::query()->firstOrFail();
        $chat->update(['suggestion_status' => 'ready', 'suggested_revision' => $chat->revision, 'suggestion' => 'Eski taslak']);

        $second = [['905321112233', 's.whatsapp.net', [...$first[0][2], ['A3', 0, '2026-09-03 09:00', 0, 'Fiyat var mı?']]]];
        $import = $this->extract($this->upload($this->backup($second)));

        $this->assertSame(['chats' => 1, 'new_chats' => 0, 'new_messages' => 1], array_intersect_key($import->stats, array_flip(['chats', 'new_chats', 'new_messages'])));
        $this->assertSame(3, WhatsAppMessage::query()->count());
        $chat = $chat->fresh();
        $this->assertSame('idle', $chat->suggestion_status);
        $this->assertSame('2026-09-03 06:00', $chat->last_message_at->format('Y-m-d H:i'));
        $this->assertStringStartsWith('2026-09-03', (string) app(WhatsAppConnection::class)->integration()->config['backup_snapshot_at']);
    }

    public function test_an_old_format_backup_is_refused_with_how_to_take_a_new_one(): void
    {
        $this->actingAs($this->admin)->postJson(route('operator.whatsapp.backup'), ['name' => 'msgstore.db.crypt14', 'size' => 5000])
            ->assertUnprocessable()->assertJsonPath('errors.backup.0', fn (string $message): bool => str_contains($message, '64 haneli anahtarla yeni bir yedek'));
        $this->assertSame(0, WhatsAppBackupImport::query()->count());
    }

    public function test_a_piece_sent_for_the_wrong_offset_says_where_to_continue(): void
    {
        $content = $this->backup([['905321112233', 's.whatsapp.net', [['A1', 0, '2026-09-01 10:00', 0, 'Selam']]]]);
        $id = $this->actingAs($this->admin)->postJson(route('operator.whatsapp.backup'), ['name' => 'msgstore.db.crypt15', 'size' => strlen($content)])->json('id');
        $this->piece($id, 0, substr($content, 0, 100))->assertOk()->assertJson(['received' => 100, 'complete' => false]);

        $this->piece($id, 0, substr($content, 0, 100))->assertStatus(409)->assertJson(['expected_offset' => 100]);
        $this->piece($id, 100, substr($content, 100))->assertOk()->assertJson(['complete' => true]);
        $this->assertSame($content, file_get_contents(WhatsAppBackupImport::query()->findOrFail($id)->path()));
    }

    public function test_a_file_that_is_not_a_backup_fails_with_a_clear_reason_and_is_removed(): void
    {
        Queue::fake();
        $import = $this->upload(str_repeat('x', 500));
        $import = $this->extract($import);

        $this->assertSame(['failed', WhatsAppBackupReader::FORMAT_ERROR], [$import->status, $import->error]);
        $this->assertFileDoesNotExist($import->path());
    }

    public function test_the_brain_learns_from_the_chats_and_feeds_the_reply_with_the_operators_instructions_first(): void
    {
        config(['moxdop.openai.api_key' => 'sk-test', 'ai.providers.openai.key' => 'sk-test']);
        CoreIntegration::factory()->openai()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        Queue::fake();
        $this->extract($this->upload($this->backup([['905321112233', 's.whatsapp.net', [
            ['A1', 0, '2026-09-01 10:00', 0, 'Web sitesi fiyatı nedir?'],
            ['A2', 1, '2026-09-01 10:05', 0, 'Merhaba, kurumsal site 20.000 TL.'],
            ['A3', 0, '2026-09-04 10:00', 0, 'Logo da yapıyor musunuz?'],
        ]]])));
        WhatsAppBrainAgent::fake([[
            'summary' => 'Ajans; web sitesi ve logo işleri.', 'services' => ['Kurumsal web sitesi'],
            'prices' => [['item' => 'Kurumsal site', 'price' => '20.000 TL', 'last_quoted' => '2026-09-01']],
            'faq' => [['question' => 'Fiyat nedir?', 'answer' => 'Kurumsal site 20.000 TL.']], 'tone' => 'Siz diye, kısa ve sıcak.',
            'policies' => [], 'avoid' => [], 'open_questions' => ['20.000 TL fiyatı hâlâ geçerli mi?'],
        ]]);

        app(WhatsAppBrain::class)->learn();

        $config = app(WhatsAppConnection::class)->integration()->config;
        $this->assertSame(['ready', 'Ajans; web sitesi ve logo işleri.', 1], [$config['brain_status'], $config['brain']['summary'], $config['brain']['conversations']]);
        WhatsAppBrainAgent::assertPrompted(fn ($prompt): bool => str_contains((string) $prompt->prompt, 'Kişi 1') && ! str_contains((string) $prompt->prompt, '905321112233'));
        $this->actingAs($this->admin);
        Livewire::test(Inbox::class)
            ->assertSee('Senden istediklerim')->assertSee('20.000 TL fiyatı hâlâ geçerli mi?')
            ->set('business_context', 'Kurumsal site artık 25.000 TL.')->call('saveInstructions')->assertHasNoErrors();

        $chat = WhatsAppConversation::query()->firstOrFail();
        Livewire::test(Inbox::class)->call('generate', $chat->id);
        Queue::assertPushed(GenerateWhatsAppSuggestion::class);
        WhatsAppReplyAgent::fake([['action' => 'reply', 'reply' => 'Evet, logo da yapıyoruz.', 'rationale' => 'Logo sordu.', 'summary' => 'Logo sorusu']]);
        app(WhatsAppSuggestions::class)->generate($chat->id);

        $this->assertSame(['ready', 'Evet, logo da yapıyoruz.'], [$chat->fresh()->suggestion_status, $chat->fresh()->suggestion]);
        WhatsAppReplyAgent::assertPrompted(fn ($prompt): bool => str_contains((string) $prompt->prompt, 'Kurumsal site artık 25.000 TL.')
            && str_contains((string) $prompt->prompt, 'Siz diye, kısa ve sıcak.') && str_contains((string) $prompt->prompt, 'WhatsApp backup taken around'));
    }

    public function test_imported_chats_are_never_drafted_without_a_click(): void
    {
        Queue::fake();
        $this->extract($this->upload($this->backup([['905321112233', 's.whatsapp.net', [['A1', 0, '2026-09-01 10:00', 0, 'Selam']]]])));
        $integration = app(WhatsAppConnection::class)->integration();
        $integration->update(['config' => [...$integration->config, 'automatic_suggestions' => true]]);
        WhatsAppConversation::query()->update(['updated_at' => now()->subMinute()]);

        app(WhatsAppDispatch::class)->tick();

        Queue::assertNotPushed(GenerateWhatsAppSuggestion::class);
    }

    public function test_learning_without_any_chat_says_to_upload_first(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(Inbox::class)->call('learnBrain')->assertHasErrors('brain')->assertSee('önce yedeği yükleyip çıkarın');
    }

    public function test_reset_removes_the_backup_chats_the_uploads_and_the_brain(): void
    {
        Queue::fake();
        $this->extract($this->upload($this->backup([['905321112233', 's.whatsapp.net', [['A1', 0, '2026-09-01 10:00', 0, 'Selam']]]])));
        $pending = $this->upload($this->backup([['905321112233', 's.whatsapp.net', [['A9', 0, '2026-09-05 10:00', 0, 'Yeni']]]]));
        $integration = app(WhatsAppConnection::class)->integration();
        $integration->update(['config' => [...$integration->config, 'brain' => ['summary' => 'x', 'learned_at' => now()->toIso8601String()], 'brain_status' => 'ready', 'business_context' => 'Talimat']]);

        app(WhatsAppConnection::class)->reset($this->admin);

        $this->assertSame(0, WhatsAppConversation::query()->count());
        $this->assertSame(0, WhatsAppBackupImport::query()->count());
        $this->assertFileDoesNotExist($pending->path());
        $config = app(WhatsAppConnection::class)->integration()->config;
        $this->assertArrayNotHasKey('brain', $config);
        $this->assertSame('Talimat', $config['business_context']);
    }

    /**
     * An Android crypt15 backup as WhatsApp writes it: [prefix size][0x01][BackupPrefix with the IV][AES-GCM data][tag][MD5].
     *
     * @param  list<array{0: string, 1: string, 2: list<array{0: string, 1: int, 2: string, 3: int, 4: string}>}>  $chats  [user, server, [[key_id, from_me, time (Istanbul), type, text]]]
     */
    private function backup(array $chats): string
    {
        $path = tempnam(sys_get_temp_dir(), 'wa-msgstore');
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

    private function upload(string $content, int $chunk = 4 * 1024 * 1024): WhatsAppBackupImport
    {
        $id = $this->actingAs($this->admin)->postJson(route('operator.whatsapp.backup'), ['name' => 'msgstore.db.crypt15', 'size' => strlen($content)])
            ->assertOk()->json('id');
        for ($offset = 0; $offset < strlen($content); $offset += $chunk) {
            $this->piece($id, $offset, substr($content, $offset, $chunk))->assertOk();
        }

        return WhatsAppBackupImport::query()->findOrFail($id);
    }

    private function piece(string $id, int $offset, string $bytes): TestResponse
    {
        return $this->actingAs($this->admin)->call('POST', route('operator.whatsapp.backup.chunk', ['import' => $id, 'offset' => $offset]), [], [], [],
            ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], $bytes);
    }

    private function extract(WhatsAppBackupImport $import): WhatsAppBackupImport
    {
        app(WhatsAppBackupImporter::class)->extract($this->admin, $this->key);
        app(WhatsAppBackupImporter::class)->run($import->id);

        return $import->fresh();
    }
}
