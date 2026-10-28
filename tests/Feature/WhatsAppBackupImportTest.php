<?php

namespace Tests\Feature;

use App\Ai\Agents\WhatsAppBrainAgent;
use App\Ai\Agents\WhatsAppReplyAgent;
use App\Jobs\WhatsApp\ExtractWhatsAppBackup;
use App\Jobs\WhatsApp\GenerateWhatsAppSuggestion;
use App\Jobs\WhatsApp\LearnWhatsAppBrain;
use App\Livewire\Operator\WhatsApp\Inbox;
use App\Models\AgencySetting;
use App\Models\AiTask;
use App\Models\CoreIntegration;
use App\Models\User;
use App\Models\WhatsAppBackupImport;
use App\Models\WhatsAppBackupLine;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\AiTasks\AiTaskQueue;
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
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
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
        $kept = [WhatsAppBackupImport::keptPath(null), ...WhatsAppBackupLine::query()->pluck('id')->map(fn (int $id): string => WhatsAppBackupImport::keptPath($id))->all()];
        $excels = WhatsAppBackupImport::query()->get()->map->excelPath()->all();
        foreach ([...$this->files, ...$kept, ...$excels, ...WhatsAppBackupImport::query()->get()->map->path()->all()] as $file) {
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
            ->assertSee('Gelen kutusu')->assertSeeHtml('data-wa-tab-count="inbox">2</span>')
            ->assertSee('Gizli numara')
            ->assertSee('Beyin')
            ->set('tab', 'setup')
            ->assertSee('2 görüşme yedekten geldi')
            ->assertSee('1 grup sohbeti alınmadı')
            ->assertSee('Canlı bağlantı (Meta)')
            ->set('tab', 'inbox')
            ->call('selectConversation', $chat->id)
            ->assertSee('Merhaba, web sitesi fiyatı nedir?')
            ->assertSee('Mesaj üret')
            ->assertDontSee('Cevap penceresi');
        // Newest message first.
        $html = Livewire::withQueryParams(['conversation' => $chat->id])->test(Inbox::class)->html();
        $this->assertLessThan(strpos($html, 'web sitesi fiyatı nedir?'), strpos($html, 'kurumsal site 20.000 TL.'));
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
        // The key saved by the first extraction: uploading the file alone queues the extraction.
        $import = $this->upload($this->backup($second));
        $this->assertSame('queued', $import->status);
        app(WhatsAppBackupImporter::class)->run($import->id);
        $import = $import->fresh();

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
            ->assertSee('Beyin senden 1 konuda bilgi istiyor')
            ->set('tab', 'brain')
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

    public function test_when_claude_takes_all_the_brain_waits_for_claude_and_stores_its_answer(): void
    {
        Queue::fake();
        $this->extract($this->upload($this->backup([['905321112233', 's.whatsapp.net', [
            ['A1', 0, '2026-09-01 10:00', 0, 'Web sitesi fiyatı nedir?'],
            ['A2', 1, '2026-09-01 10:05', 0, 'Merhaba, kurumsal site 20.000 TL.'],
        ]]])));
        config(['moxdop-mcp.token' => 'test-mcp-token', 'moxdop-mcp.takes_all' => true]);
        WhatsAppBrainAgent::fake()->preventStrayPrompts();
        $this->setBrainStatus('queued');

        (new LearnWhatsAppBrain(null))->handle(app(WhatsAppBrain::class));

        $this->assertSame('running', app(WhatsAppConnection::class)->integration()->config['brain_status']);
        $task = AiTask::query()->sole();
        $this->assertSame('whatsapp.brain', $task->operation);
        $this->assertStringNotContainsString('905321112233', (string) $task->input, 'phone numbers never leave as they are');

        // Claude answers; the job dispatched again (here by hand, the queue is faked) stores the brain.
        $this->assertSame([], app(AiTaskQueue::class)->submit($task, [
            'summary' => 'Ajans; web sitesi işleri.', 'services' => ['Kurumsal web sitesi'],
            'prices' => [['item' => 'Kurumsal site', 'price' => '20.000 TL', 'last_quoted' => '2026-09-01']],
            'faq' => [], 'tone' => 'Siz diye.', 'policies' => [], 'avoid' => [], 'open_questions' => [],
        ]));
        (new LearnWhatsAppBrain(null))->handle(app(WhatsAppBrain::class));

        $config = app(WhatsAppConnection::class)->integration()->config;
        $this->assertSame(['ready', 'Ajans; web sitesi işleri.'], [$config['brain_status'], $config['brain']['summary']]);
        WhatsAppBrainAgent::assertNeverPrompted();
    }

    private function setBrainStatus(string $status): void
    {
        $integration = app(WhatsAppConnection::class)->integration();
        $integration->update(['config' => [...$integration->config, 'brain_status' => $status]]);
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
    public function test_the_working_key_is_saved_encrypted_and_later_uploads_need_only_the_file(): void
    {
        Queue::fake();
        $chats = [['905321112233', 's.whatsapp.net', [['A1', 0, '2026-09-01 10:00', 0, 'Selam']]]];
        $first = $this->upload($this->backup($chats));
        $this->assertSame('uploaded', $first->status);
        $this->actingAs($this->admin);
        Livewire::test(Inbox::class)->call('extractBackup', '')->assertHasErrors('backup_key');

        $this->extract($first);
        $stored = app(WhatsAppConnection::class)->integration()->config['backup_key'];
        $this->assertStringNotContainsString($this->key, $stored);
        $this->assertSame($this->key, Crypt::decryptString($stored));
        $this->assertNull($first->fresh()->getRawOriginal('backup_key'));
        Livewire::test(Inbox::class)->set('tab', 'setup')->assertSee('Anahtar kayıtlı');

        $content = $this->backup([['905321112233', 's.whatsapp.net', [['A1', 0, '2026-09-01 10:00', 0, 'Selam'], ['A2', 0, '2026-09-02 10:00', 0, 'Orada mısınız?']]]]);
        $id = $this->postJson(route('operator.whatsapp.backup'), ['name' => 'msgstore.db.crypt15', 'size' => strlen($content)])->json('id');
        $this->piece($id, 0, $content)->assertOk()->assertJson(['complete' => true, 'extracting' => true]);
        Queue::assertPushed(ExtractWhatsAppBackup::class, fn (ExtractWhatsAppBackup $job): bool => $job->importId === $id);
        app(WhatsAppBackupImporter::class)->run($id);
        $this->assertSame('completed', WhatsAppBackupImport::query()->findOrFail($id)->status);
        $this->assertSame(2, WhatsAppMessage::query()->count());
    }

    public function test_a_forgotten_key_is_asked_for_again(): void
    {
        Queue::fake();
        $this->extract($this->upload($this->backup([['905321112233', 's.whatsapp.net', [['A1', 0, '2026-09-01 10:00', 0, 'Selam']]]])));
        $this->actingAs($this->admin);
        Livewire::test(Inbox::class)->set('tab', 'setup')->call('forgetBackupKey')->assertDontSee('Anahtar kayıtlı');
        $this->assertArrayNotHasKey('backup_key', app(WhatsAppConnection::class)->integration()->config);

        $next = $this->upload($this->backup([['905321112233', 's.whatsapp.net', [['A2', 0, '2026-09-02 10:00', 0, 'Yeni']]]]));
        $this->assertSame('uploaded', $next->status);
        Queue::assertPushed(ExtractWhatsAppBackup::class, 1);
    }

    public function test_the_loaded_backup_file_and_key_can_be_seen_and_downloaded(): void
    {
        Queue::fake();
        $content = $this->backup([['905321112233', 's.whatsapp.net', [['A1', 0, '2026-09-01 10:00', 0, 'Selam']]]]);
        $this->extract($this->upload($content));
        $this->assertFileEquals(WhatsAppBackupImport::keptPath(null), $this->tempFile($content));

        $this->actingAs($this->admin);
        $screen = Livewire::test(Inbox::class)->set('tab', 'setup')
            ->assertSee('Yüklü yedek')->assertSee('Dosyayı indir')->assertSee('Excel indir')->assertSee('… '.substr($this->key, -4))
            ->assertDontSee($this->key)->assertDontSee(substr($this->key, 0, 8));
        $this->assertSame(implode(' ', str_split($this->key, 4)), $screen->instance()->revealBackupKey(app(WhatsAppBackupImporter::class), app(WhatsAppConnection::class)));

        $key = $this->get(route('operator.whatsapp.backup.key'))->assertOk();
        $this->assertSame($this->key, str_replace([' ', "\n"], '', (string) $key->getContent()));
        $this->assertStringContainsString('no-store', (string) $key->headers->get('cache-control'));
        $file = $this->get(route('operator.whatsapp.backup.file'))->assertOk();
        $this->assertSame($content, (string) file_get_contents($file->baseResponse->getFile()->getPathname()));

        // A backup extracted before files were kept: its details show, the download says it is not there.
        @unlink(WhatsAppBackupImport::keptPath(null));
        Livewire::test(Inbox::class)->set('tab', 'setup')->assertSee('sunucuda tutulmamış')->assertDontSee('Dosyayı indir');
        $this->get(route('operator.whatsapp.backup.file'))->assertNotFound();
    }

    public function test_another_backup_with_its_own_key_is_kept_apart_and_exports_on_its_own(): void
    {
        Queue::fake();
        $this->extract($this->upload($this->backup([['905321112233', 's.whatsapp.net', [['A1', 0, '2026-09-01 10:00', 0, 'Ana hat']]]])));
        $mainKey = $this->key;
        $this->actingAs($this->admin);
        Livewire::test(Inbox::class)->set('tab', 'setup')->call('createBackupLine', '0532 hattı')->assertHasNoErrors()
            ->call('createBackupLine', '0532 hattı')->assertHasErrors('line_name');
        $line = WhatsAppBackupLine::query()->sole();

        $this->key = bin2hex(random_bytes(32));
        $other = $this->upload($this->backup([['905551112233', 's.whatsapp.net', [['B1', 0, '2026-09-03 10:00', 0, 'Diğer hat'], ['B2', 1, '2026-09-03 10:05', 0, 'Cevap']]]]), line: $line->id);
        $this->assertSame([$line->id, 'uploaded'], [$other->line_id, $other->status]);
        Livewire::test(Inbox::class)->set('tab', 'setup')->call('extractBackup', '', $line->id)->assertHasErrors('backup_key.'.$line->id)
            ->call('extractBackup', $this->key, $line->id)->assertHasNoErrors();
        app(WhatsAppBackupImporter::class)->run($other->id);

        $this->assertSame('completed', $other->fresh()->status);
        $this->assertSame($mainKey, app(WhatsAppBackupImporter::class)->savedKey());
        $this->assertSame($this->key, app(WhatsAppBackupImporter::class)->savedKey($line->id));
        $this->assertStringNotContainsString($this->key, (string) $line->fresh()->getRawOriginal('backup_key'));
        $this->assertFileExists(WhatsAppBackupImport::keptPath($line->id));
        $this->assertFileExists(WhatsAppBackupImport::keptPath(null));
        $inbox = app(WhatsAppConnection::class)->integration();
        $this->assertSame(['905321112233'], WhatsAppConversation::query()->where('integration_id', $inbox->id)->pluck('contact_id')->all());
        $this->assertSame(['905551112233'], WhatsAppConversation::query()->where('phone_number_id', $line->conversationLine())->pluck('contact_id')->all());
        Livewire::test(Inbox::class)->assertDontSee('+905551112233');
        Livewire::test(Inbox::class)->set('tab', 'setup')->assertSee('0532 hattı')->assertSee('1 görüşme')->assertSee('… '.substr($this->key, -4));

        $sheets = $this->sheets($this->get(route('operator.whatsapp.export', ['line' => $line->id]))->assertOk());
        $this->assertSame(['+905551112233', '+905551112233'], array_column(array_slice($sheets['Mesajlar'], 1), 1));
        $this->assertSame(['+905321112233'], array_column(array_slice($this->sheets($this->get(route('operator.whatsapp.export')))['Mesajlar'], 1), 1));
        $this->assertSame($this->key, str_replace([' ', "\n"], '', (string) $this->get(route('operator.whatsapp.backup.key', ['line' => $line->id]))->getContent()));

        // A newer backup of the same phone needs only the file; the inbox's key is not used for it.
        $next = $this->upload($this->backup([['905551112233', 's.whatsapp.net', [['B1', 0, '2026-09-03 10:00', 0, 'Diğer hat'], ['B3', 0, '2026-09-04 10:00', 0, 'Yeni']]]]), line: $line->id);
        Queue::assertPushed(ExtractWhatsAppBackup::class, fn (ExtractWhatsAppBackup $job): bool => $job->importId === $next->id);
        app(WhatsAppBackupImporter::class)->run($next->id);
        $this->assertSame(3, WhatsAppMessage::query()->whereIn('conversation_id', WhatsAppConversation::query()->where('phone_number_id', $line->conversationLine())->select('id'))->count());

        // Resetting the inbox leaves the extra backup; deleting the extra backup leaves the inbox.
        app(WhatsAppConnection::class)->reset($this->admin);
        $this->assertSame(1, WhatsAppConversation::query()->where('phone_number_id', $line->conversationLine())->count());
        $this->assertSame($this->key, app(WhatsAppBackupImporter::class)->savedKey($line->id));
        $this->extract($this->upload($this->backup([['905321112233', 's.whatsapp.net', [['A1', 0, '2026-09-01 10:00', 0, 'Ana hat']]]])));
        Livewire::test(Inbox::class)->set('tab', 'setup')->call('deleteBackupLine', $line->id);
        $this->assertSame(0, WhatsAppBackupLine::query()->count());
        $this->assertSame(['905321112233'], WhatsAppConversation::query()->pluck('contact_id')->all());
        $this->assertFileDoesNotExist(WhatsAppBackupImport::keptPath($line->id));
        $this->assertSame(1, WhatsAppMessage::query()->count());
    }

    public function test_each_backup_gets_its_own_excel_and_an_excel_only_upload_never_touches_the_inbox(): void
    {
        Queue::fake();
        $inboxImport = $this->extract($this->upload($this->backup([['905321112233', 's.whatsapp.net', [['A1', 0, '2026-09-01 10:00', 0, 'Firma A']]]])));
        $mainKey = $this->key;

        // Another business's backup, Excel only, with its own key.
        $this->key = bin2hex(random_bytes(32));
        $content = $this->backup([['905551112233', 's.whatsapp.net', [['B1', 0, '2026-09-03 10:00', 0, 'Firma B'], ['B2', 1, '2026-09-03 10:05', 0, 'Cevap']]]]);
        $id = $this->actingAs($this->admin)->postJson(route('operator.whatsapp.backup'), ['name' => 'msgstore.db.crypt15', 'size' => strlen($content), 'excel' => true])->assertOk()->json('id');
        $this->piece($id, 0, $content)->assertOk()->assertJson(['complete' => true, 'extracting' => false]);
        $excel = WhatsAppBackupImport::query()->findOrFail($id);
        $this->assertSame(['excel', 'uploaded'], [$excel->mode, $excel->status]);
        Livewire::test(Inbox::class)->set('tab', 'setup')->assertSee('Yedekten Excel al')
            ->call('extractExcel', '')->assertHasErrors('backup_key.excel')
            ->call('extractExcel', $this->key)->assertHasNoErrors();
        app(WhatsAppBackupImporter::class)->run($id);

        $this->assertSame('completed', $excel->fresh()->status);
        $this->assertSame(['905321112233'], WhatsAppConversation::query()->pluck('contact_id')->all());
        $this->assertSame($mainKey, app(WhatsAppBackupImporter::class)->savedKey());
        $this->assertFileExists($excel->excelPath());
        $this->assertStringNotContainsString('Firma B', (string) file_get_contents($excel->excelPath()));

        $sheets = $this->sheets($this->get(route('operator.whatsapp.backup.excel', ['import' => $id]))->assertOk());
        $this->assertSame(['Numara', 'Tarih', 'Yön', 'Tür', 'Mesaj'], $sheets['Mesajlar'][0]);
        $this->assertSame([['+905551112233', '2026-09-03 10:00', 'Gelen', 'Metin', 'Firma B'], ['+905551112233', '2026-09-03 10:05', 'Giden', 'Metin', 'Cevap']], array_slice($sheets['Mesajlar'], 1));
        $this->assertSame(['+905551112233', '2026-09-03 10:05', '2026-09-03 10:00', 2], $sheets['Görüşmeler'][1]);
        $own = $this->sheets($this->get(route('operator.whatsapp.backup.excel', ['import' => $inboxImport->id]))->assertOk());
        $this->assertSame(['+905321112233'], array_column(array_slice($own['Mesajlar'], 1), 0));

        Livewire::test(Inbox::class)->set('tab', 'setup')->assertSee('Yalnız Excel')->assertSee('Gelen kutusu yedeği')
            ->call('deleteBackupExcel', $id);
        $this->assertNull(WhatsAppBackupImport::query()->find($id));
        $this->assertFileDoesNotExist($excel->excelPath());
        $this->get(route('operator.whatsapp.backup.excel', ['import' => $id]))->assertNotFound();
    }

    public function test_clearing_the_inbox_leaves_room_for_one_backup_alone(): void
    {
        Queue::fake();
        $this->extract($this->upload($this->backup([['905321112233', 's.whatsapp.net', [['A1', 0, '2026-09-01 10:00', 0, 'Firma A']]]])));
        // Another business's backup with the same key was merged in, as had happened.
        app(WhatsAppBackupImporter::class)->run($this->upload($this->backup([['905551112233', 's.whatsapp.net', [['B1', 0, '2026-09-03 10:00', 0, 'Firma B']]]]))->id);
        $this->assertSame(2, WhatsAppConversation::query()->count());
        $integration = app(WhatsAppConnection::class)->integration();
        $integration->update(['config' => [...$integration->config, 'brain' => ['summary' => 'x'], 'brain_status' => 'ready', 'business_context' => 'Talimat']]);

        $this->actingAs($this->admin);
        Livewire::test(Inbox::class)->set('tab', 'setup')->call('clearBackupInbox')->assertHasNoErrors();
        $this->assertSame(0, WhatsAppConversation::query()->count());
        $this->assertSame(0, WhatsAppMessage::query()->count());
        $this->assertNull(app(WhatsAppBackupImporter::class)->savedKey());
        $config = app(WhatsAppConnection::class)->integration()->config;
        $this->assertArrayNotHasKey('brain', $config);
        $this->assertSame('Talimat', $config['business_context']);

        $this->key = bin2hex(random_bytes(32));
        $wanted = $this->upload($this->backup([['905551112233', 's.whatsapp.net', [['B1', 0, '2026-09-03 10:00', 0, 'Firma B']]]]));
        $this->assertSame('uploaded', $wanted->status);
        $this->extract($wanted);
        $this->assertSame(['905551112233'], WhatsAppConversation::query()->pluck('contact_id')->all());
    }

    public function test_cevap_uret_drafts_every_recent_chat_waiting_for_an_answer(): void
    {
        Queue::fake();
        $this->extract($this->upload($this->backup([
            ['905321112233', 's.whatsapp.net', [['A1', 1, '2026-09-10 10:00', 0, 'Merhaba'], ['A2', 0, '2026-09-10 11:00', 0, 'Fiyat nedir?']]],
            ['905321112244', 's.whatsapp.net', [['B1', 0, '2026-09-11 10:00', 0, 'Selam'], ['B2', 1, '2026-09-11 10:05', 0, 'Buyurun']]],
            ['905321112255', 's.whatsapp.net', [['C1', 0, '2026-06-01 10:00', 0, 'Eski soru']]],
            ['905321112266', 's.whatsapp.net', [['D1', 0, '2026-09-12 10:00', 0, 'Yarın arar mısınız?']]],
        ])));
        $waiting = WhatsAppConversation::query()->whereIn('contact_id', ['905321112233', '905321112266'])->pluck('id')->sort()->values();
        $this->actingAs($this->admin);

        $page = Livewire::test(Inbox::class)->assertSee('Cevap üret (2)')->assertSee('905321112255');
        $page->call('generateAll')->assertSee('2 görüşmeye cevap hazırlanıyor');

        $this->assertEquals($waiting, WhatsAppConversation::query()->where('suggestion_status', 'requested')->pluck('id')->sort()->values());
        Queue::assertPushed(GenerateWhatsAppSuggestion::class, 2);
        Livewire::test(Inbox::class)->assertSee('Cevap üret')->assertDontSee('Cevap üret (')->call('generateAll')->assertSee('Cevap bekleyen görüşme yok');
    }

    public function test_answers_to_the_brains_questions_go_into_the_instructions(): void
    {
        Queue::fake();
        $this->extract($this->upload($this->backup([['905321112233', 's.whatsapp.net', [['A1', 0, '2026-09-01 10:00', 0, 'Selam']]]])));
        $integration = app(WhatsAppConnection::class)->integration();
        $integration->update(['config' => [...$integration->config, 'business_context' => 'Kısa yaz.',
            'brain' => ['summary' => 'Ajans', 'open_questions' => ['Site fiyatı ne?', 'KDV dahil mi?'], 'learned_at' => now()->toIso8601String()]]]);
        $this->actingAs($this->admin);

        Livewire::test(Inbox::class)->call('saveAnswers')->assertHasErrors('answers');
        Livewire::test(Inbox::class)->set('tab', 'brain')->set('answers.1', 'KDV hariç.')->call('saveAnswers')->assertHasNoErrors()
            ->assertSet('business_context', "Kısa yaz.\n\n- KDV dahil mi? → KDV hariç.")
            ->assertSee('Site fiyatı ne?')->assertDontSee('KDV dahil mi?</span>', false);

        $config = $integration->fresh()->config;
        $this->assertSame(['Site fiyatı ne?'], $config['brain']['open_questions']);
        $this->assertStringEndsWith('- KDV dahil mi? → KDV hariç.', $config['business_context']);
    }

    public function test_names_come_from_the_phones_address_book(): void
    {
        Queue::fake();
        $this->extract($this->upload($this->backup([
            ['905321112233', 's.whatsapp.net', [['A1', 0, '2026-09-01 10:00', 0, 'Selam']]],
            ['905321112244', 's.whatsapp.net', [['B1', 0, '2026-09-01 10:00', 0, 'Merhaba']]],
            ['4915112345678', 's.whatsapp.net', [['C1', 0, '2026-09-01 10:00', 0, 'Hallo']]],
        ])));
        $vcard = "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Ayşe Yılmaz\r\nTEL;TYPE=CELL:0532 111 22 33\r\nEND:VCARD\r\n"
            ."BEGIN:VCARD\r\nVERSION:2.1\r\nN;CHARSET=UTF-8;ENCODING=QUOTED-PRINTABLE:Demir;Ali;;;\r\nTEL;CELL:+49 151 1234 5678\r\nEND:VCARD\r\n"
            ."BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Rehberde olmayan\r\nitem1.TEL:532 999 88 77\r\nEND:VCARD\r\n";
        $this->actingAs($this->admin);

        Livewire::test(Inbox::class)->call('importContacts', 'merhaba')->assertHasErrors('contacts');
        Livewire::test(Inbox::class)->call('importContacts', $vcard)->assertHasNoErrors()
            ->assertSee('3 rehber numarasından 2 görüşmeye isim yazıldı.')->assertSee('Ayşe Yılmaz')->assertSee('Ali Demir');

        $this->assertSame(['905321112233' => 'Ayşe Yılmaz', '905321112244' => null, '4915112345678' => 'Ali Demir'],
            WhatsAppConversation::query()->orderBy('id')->pluck('contact_name', 'contact_id')->all());
        Livewire::test(Inbox::class)->set('q', 'Ayşe')->assertSee('Ayşe Yılmaz')->assertDontSee('Ali Demir');
    }

    public function test_conversations_export_to_excel(): void
    {
        Queue::fake();
        $this->extract($this->upload($this->backup([
            ['905321112233', 's.whatsapp.net', [['A1', 0, '2026-09-01 10:00', 0, 'Fiyat nedir?'], ['A2', 1, '2026-09-01 10:05', 0, 'Kurumsal site 14.000 TL.']]],
            ['905321112244', 's.whatsapp.net', [['B1', 0, '2026-09-02 10:00', 0, 'Merhaba']]],
        ])));
        WhatsAppConversation::query()->where('contact_id', '905321112233')->update(['contact_name' => 'Ayşe Yılmaz']);

        $response = $this->actingAs($this->admin)->get(route('operator.whatsapp.export'))->assertOk();
        $this->assertStringContainsString('whatsapp-konusmalar-', (string) $response->headers->get('content-disposition'));

        $reader = new XlsxReader;
        $reader->open($response->baseResponse->getFile()->getPathname());
        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $sheets[$sheet->getName()][] = $row->toArray();
            }
        }
        $reader->close();

        $this->assertSame(['Kişi', 'Numara', 'Müşteri', 'Tarih', 'Yön', 'Tür', 'Mesaj'], $sheets['Mesajlar'][0]);
        $this->assertSame(['Ayşe Yılmaz', '+905321112233', '', '2026-09-01 10:00', 'Gelen', 'Metin', 'Fiyat nedir?'], $sheets['Mesajlar'][2]);
        $this->assertSame('Giden', $sheets['Mesajlar'][3][4]);
        $this->assertCount(4, $sheets['Mesajlar']);
        $this->assertSame([['Kişi', 'Numara'], ['', '+905321112244'], ['Ayşe Yılmaz', '+905321112233']],
            array_map(fn (array $row): array => array_slice($row, 0, 2), $sheets['Görüşmeler']));

        $one = WhatsAppConversation::query()->where('contact_id', '905321112244')->value('id');
        $this->get(route('operator.whatsapp.export', ['conversation' => $one]))->assertOk();
        $this->get(route('operator.whatsapp.export', ['conversation' => 999999]))->assertNotFound();
    }

    public function test_the_inbox_filters_previews_and_done_then_next_flow(): void
    {
        Queue::fake();
        $this->extract($this->upload($this->backup([
            ['905321112233', 's.whatsapp.net', [['A1', 1, '2026-09-10 10:00', 0, 'Merhaba'], ['A2', 0, '2026-09-10 11:00', 0, 'Fiyat nedir?']]],
            ['905321112244', 's.whatsapp.net', [['B1', 0, '2026-09-11 10:00', 0, 'Selam'], ['B2', 1, '2026-09-11 10:05', 0, 'Buyurun efendim']]],
            ['905321112255', 's.whatsapp.net', [['C1', 0, '2026-09-12 10:00', 0, 'Logo yapıyor musunuz?']]],
        ])));
        $first = WhatsAppConversation::query()->where('contact_id', '905321112233')->firstOrFail();
        $third = WhatsAppConversation::query()->where('contact_id', '905321112255')->firstOrFail();
        $third->update(['suggestion_status' => 'ready', 'suggested_revision' => $third->revision, 'suggestion' => 'Evet, logo da yapıyoruz.', 'suggestion_action' => 'reply']);
        $this->actingAs($this->admin);

        Livewire::test(Inbox::class)
            ->assertSee('Siz: Buyurun efendim')->assertSee('Cevap bekliyor')
            ->set('filter', 'waiting')->assertSee('+905321112233')->assertDontSee('+905321112244')
            ->set('filter', 'ready')->assertSee('+905321112255')->assertDontSee('+905321112233')
            ->call('selectConversation', $third->id)
            ->assertSee("WhatsApp'ta aç", false)->assertSee('https://wa.me/905321112255')
            ->call('markDone', $third->id, true)
            ->assertSet('conversation', $first->id)
            ->assertSee('sıradaki açıldı');

        $this->assertNotNull($third->fresh()->done_at);
        Livewire::test(Inbox::class)->set('filter', 'done')->assertSee('+905321112255')->assertDontSee('+905321112233')
            ->set('filter', 'open')->assertDontSee('+905321112255');

        // The customer writes again: a newer backup brings the chat back to the open list.
        $this->travel(1)->minutes();
        $next = $this->upload($this->backup([['905321112255', 's.whatsapp.net', [
            ['C1', 0, '2026-09-12 10:00', 0, 'Logo yapıyor musunuz?'], ['C2', 0, now('Europe/Istanbul')->addDay()->format('Y-m-d H:i'), 0, 'Fiyat?'],
        ]]]));
        app(WhatsAppBackupImporter::class)->run($next->id);
        $this->assertNull($third->fresh()->done_at);
    }

    public function test_an_extraction_cut_off_mid_way_is_started_again_and_finishes(): void
    {
        Queue::fake();
        $this->extract($this->upload($this->backup([['905321112233', 's.whatsapp.net', [['A1', 0, '2026-09-01 10:00', 0, 'Selam']]]])));
        // The next backup is queued by itself (saved key); its worker dies after starting: no failure hook runs.
        $next = $this->upload($this->backup([['905321112233', 's.whatsapp.net', [['A1', 0, '2026-09-01 10:00', 0, 'Selam'], ['A2', 0, '2026-09-02 10:00', 0, 'Fiyat?']]]]));
        $this->assertSame(['queued', 1], [$next->status, $next->attempt]);
        $next->update(['status' => 'running', 'started_at' => now(), 'backup_key' => null, 'stats' => ['phase' => 'import']]);

        $this->actingAs($this->admin);
        $this->travel(6)->minutes();
        Livewire::test(Inbox::class)->assertSee('Görüşmeler aktarılıyor')->assertSee('dakikadır ilerleme yok');
        $this->postJson(route('operator.whatsapp.backup'), ['name' => 'msgstore.db.crypt15', 'size' => 100])
            ->assertStatus(422)->assertJsonPath('errors.backup.0', 'Önceki yedek şu an çıkarılıyor; bitince yeni dosya yükleyebilirsiniz.');

        $this->travel(11)->minutes();
        Livewire::test(Inbox::class)->assertSee('Çıkarma sırada bekliyor')->assertSee('2. deneme')->assertSee('yarıda kesildi');
        $next->refresh();
        $this->assertSame(['queued', 2], [$next->status, $next->attempt]);
        Queue::assertPushed(ExtractWhatsAppBackup::class, fn (ExtractWhatsAppBackup $job): bool => $job->importId === $next->id && $job->attempt === 2);

        // The first attempt's job arriving late does nothing; the new attempt imports only what is missing.
        app(WhatsAppBackupImporter::class)->run($next->id, 1);
        $this->assertSame('queued', $next->fresh()->status);
        (new ExtractWhatsAppBackup($next->id, $this->admin->id, 1))->failed(null);
        $this->assertSame('queued', $next->fresh()->status);
        app(WhatsAppBackupImporter::class)->run($next->id, 2);
        $this->assertSame('completed', $next->fresh()->status);
        $this->assertSame(2, WhatsAppMessage::query()->count());
        $this->assertNull($next->fresh()->getRawOriginal('backup_key'));
    }

    public function test_a_job_lost_from_the_queue_is_queued_again_and_the_operator_can_restart_it(): void
    {
        Queue::fake();
        $this->extract($this->upload($this->backup([['905321112233', 's.whatsapp.net', [['A1', 0, '2026-09-01 10:00', 0, 'Selam']]]])));
        $next = $this->upload($this->backup([['905321112233', 's.whatsapp.net', [['A2', 0, '2026-09-02 10:00', 0, 'Yeni']]]]));
        $this->actingAs($this->admin);
        Livewire::test(Inbox::class)->assertSee('Çıkarma sırada bekliyor (0 dk)')->assertDontSee('Yeniden başlat')
            ->call('restartBackup')->assertHasErrors('backup');

        $this->travel(6)->minutes();
        Livewire::test(Inbox::class)->call('restartBackup')->assertHasNoErrors()->assertSee('Yeniden başlattınız.');
        $this->assertSame(2, $next->fresh()->attempt);

        $this->travel(21)->minutes();
        app(WhatsAppBackupImporter::class)->recoverStalled();
        // Lost from the queue, not cut off: queued again under the same attempt.
        $this->assertSame(['queued', 2], [$next->fresh()->status, $next->fresh()->attempt]);
        $this->assertSame(3, Queue::pushed(ExtractWhatsAppBackup::class)->filter(fn (ExtractWhatsAppBackup $job): bool => $job->importId === $next->id)->count());
    }

    public function test_an_extraction_that_keeps_getting_cut_off_fails_with_the_reason(): void
    {
        Queue::fake();
        $this->extract($this->upload($this->backup([['905321112233', 's.whatsapp.net', [['A1', 0, '2026-09-01 10:00', 0, 'Selam']]]])));
        $this->travel(1)->minutes();
        $next = $this->upload($this->backup([['905321112233', 's.whatsapp.net', [['A2', 0, '2026-09-02 10:00', 0, 'Yeni']]]]));
        foreach ([2, 3] as $attempt) {
            (new ExtractWhatsAppBackup($next->id, $this->admin->id, $next->fresh()->attempt))->failed(null);
            $this->assertSame(['queued', $attempt], [$next->fresh()->status, $next->fresh()->attempt]);
        }
        (new ExtractWhatsAppBackup($next->id, $this->admin->id, 3))->failed(null);
        $this->assertSame('failed', $next->fresh()->status);
        $this->assertStringContainsString('3 denemede de bitmedi', $next->fresh()->error);
        $this->assertFileDoesNotExist($next->path());

        $this->actingAs($this->admin);
        Livewire::test(Inbox::class)->assertSee('Son yedek çıkarılamadı')->assertSee('3 denemede de bitmedi');
    }

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

    private function upload(string $content, int $chunk = 4 * 1024 * 1024, ?int $line = null): WhatsAppBackupImport
    {
        $id = $this->actingAs($this->admin)->postJson(route('operator.whatsapp.backup'), ['name' => 'msgstore.db.crypt15', 'size' => strlen($content), 'line' => $line])
            ->assertOk()->json('id');
        for ($offset = 0; $offset < strlen($content); $offset += $chunk) {
            $this->piece($id, $offset, substr($content, $offset, $chunk))->assertOk();
        }

        return WhatsAppBackupImport::query()->findOrFail($id);
    }

    private function tempFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'wa-expected');
        $this->files[] = $path;
        file_put_contents($path, $content);

        return $path;
    }

    /** @return array<string, list<list<mixed>>> */
    private function sheets(TestResponse $response): array
    {
        $reader = new XlsxReader;
        $reader->open($response->baseResponse->getFile()->getPathname());
        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $sheets[$sheet->getName()][] = $row->toArray();
            }
        }
        $reader->close();

        return $sheets;
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
