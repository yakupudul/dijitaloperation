<?php

namespace Tests\Feature\Operations;

use App\Enums\CustomerStatus;
use App\Livewire\Operator\Settings\KvkkPage;
use App\Livewire\Operator\Settings\SystemHealthPage;
use App\Models\AgencySetting;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Services\Assistant\WhatsAppRetention;
use App\Services\Operations\SystemBackup;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

final class KvkkAndBackupTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->dir = sys_get_temp_dir().'/moxdop-backup-test-'.uniqid();
        config(['moxdop-backup.directory' => $this->dir, 'moxdop-backup.keep' => 2, 'moxdop-backup.remote_disk' => null]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_sqlite_backup_is_compressed_recorded_pruned_and_shown_on_system_health(): void
    {
        $source = $this->dir.'-source.sqlite';
        file_put_contents($source, "SQLite format 3\0".str_repeat('page data ', 200));
        config(['database.connections.sqlite.database' => $source]);

        foreach (['2026-09-20 03:30:00', '2026-09-21 03:30:00', '2026-09-22 03:30:00'] as $at) {
            $this->travelTo($at);
            $result = app(SystemBackup::class)->run();
            $this->assertSame('succeeded', $result['status']);
        }
        @unlink($source);

        $this->assertStringStartsWith("SQLite format 3\0page data", gzdecode((string) file_get_contents($result['path'])));
        $this->assertCount(2, glob($this->dir.'/moxdop-*.gz'), 'only the newest `keep` files stay');
        $this->assertSame(3, DB::table('system_backups')->where('status', 'succeeded')->count());

        $this->travelTo('2026-09-22 10:00:00');
        $status = app(SystemBackup::class)->status();
        $this->assertTrue($status['ok']);
        $this->assertFalse($status['remote']);

        $this->actingAs($this->admin);
        Livewire::test(SystemHealthPage::class)->assertSee('Sistem yedeği')->assertSee('Güncel');

        $this->travelTo('2026-09-24 10:00:00');
        $this->assertFalse(app(SystemBackup::class)->status()['ok'], 'older than stale_hours');
    }

    public function test_failed_backup_is_recorded_with_its_error(): void
    {
        config(['database.connections.sqlite.database' => ':memory:']);

        $result = app(SystemBackup::class)->run();

        $this->assertSame('failed', $result['status']);
        $this->assertSame('SQLite dosyası bulunamadı.', app(SystemBackup::class)->status()['last_error']);
        $this->assertSame([], glob($this->dir.'/moxdop-*.gz') ?: []);
    }

    public function test_incomplete_backup_is_rejected_by_verification(): void
    {
        $source = $this->dir.'-broken.sqlite';
        file_put_contents($source, str_repeat('not a database ', 50));
        config(['database.connections.sqlite.database' => $source]);

        $result = app(SystemBackup::class)->run();
        @unlink($source);

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('doğrulanamadı', (string) $result['error']);
        $this->assertSame([], glob($this->dir.'/moxdop-*.gz') ?: [], 'an unverified file is not kept');

        $dump = $this->dir.'/check.sql.gz';
        File::ensureDirectoryExists($this->dir);
        file_put_contents($dump, gzencode("CREATE TABLE a (id int);\n-- PostgreSQL database dump complete\n"));
        app(SystemBackup::class)->verify('pgsql', $dump);
        file_put_contents($dump, substr(gzencode(str_repeat('INSERT INTO a VALUES (1);', 500)), 0, 200));
        $this->expectException(\RuntimeException::class);
        app(SystemBackup::class)->verify('pgsql', $dump);
    }

    public function test_system_health_lists_admins_without_two_factor_and_enforcement_redirects_to_profile(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(SystemHealthPage::class)->assertSee('İki adımlı doğrulama')->assertSee('1 yöneticide kapalı')->assertSee('Zorunlu değil');
        $this->get(route('operator.customers'))->assertOk();

        config(['moxdop.security.require_admin_2fa' => true]);
        $this->get(route('operator.customers'))->assertRedirect(route('operator.profile'));
        $this->get(route('operator.profile'))->assertOk();

        $this->admin->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->save();
        $this->get(route('operator.customers'))->assertOk();

        $member = User::factory()->create(['is_active' => true]);
        $member->assignRole(Roles::TEAM_MEMBER);
        $this->actingAs($member)->get(route('operator.customers'))->assertOk();
    }

    public function test_sqlite_backup_can_be_restored_after_a_safety_backup(): void
    {
        $database = $this->dir.'-live.sqlite';
        File::ensureDirectoryExists($this->dir);
        file_put_contents($database, "SQLite format 3\0".'version-one');
        config(['database.connections.sqlite.database' => $database]);
        $first = app(SystemBackup::class)->run();
        file_put_contents($database, "SQLite format 3\0".'version-two');

        $this->artisan('moxdop:backup:restore', ['file' => basename((string) $first['path']), '--force' => true])->assertSuccessful();

        $this->assertSame("SQLite format 3\0".'version-one', file_get_contents($database));
        $this->assertCount(2, glob($this->dir.'/moxdop-*.gz'), 'a safety backup of version two was taken first');
        @unlink($database);
    }

    public function test_whatsapp_retention_blanks_old_texts_only_when_set(): void
    {
        $conversation = DB::table('whatsapp_conversations')->insertGetId(['integration_id' => CoreIntegration::factory()->create(['provider' => 'whatsapp'])->id, 'phone_number_id' => '1', 'contact_id' => '905551112233',
            'last_message_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        // Write through the model so the body is encrypted, exactly as ingestion stores it.
        foreach (['old' => now()->subDays(100), 'new' => now()->subDays(5)] as $id => $sentAt) {
            WhatsAppMessage::query()->create(['conversation_id' => $conversation, 'message_id' => $id, 'direction' => 'incoming', 'message_type' => 'text', 'body' => 'Tedavi fiyatı nedir?', 'sent_at' => $sentAt]);
        }

        $this->assertSame(0, app(WhatsAppRetention::class)->run(), 'off by default');
        AgencySetting::query()->create(['agency_name' => 'Moximu'])->forceFill(['whatsapp_retention_days' => 90])->save();

        $this->assertSame(1, app(WhatsAppRetention::class)->run());
        $this->assertSame(0, app(WhatsAppRetention::class)->run());
        // The redacted body must still decrypt cleanly through the model (it is stored as ciphertext, not plaintext).
        $old = WhatsAppMessage::query()->where('message_id', 'old')->firstOrFail();
        $this->assertSame(WhatsAppRetention::REDACTED, $old->body);
        $this->assertNotSame(WhatsAppRetention::REDACTED, DB::table('whatsapp_messages')->where('message_id', 'old')->value('body'), 'stored value is encrypted, not the plaintext marker');
        $this->assertNotNull($old->redacted_at);
        $this->assertSame('Tedavi fiyatı nedir?', WhatsAppMessage::query()->where('message_id', 'new')->value('body'));
    }

    public function test_kvkk_page_saves_agreements_and_retention_for_admins_only(): void
    {
        $customer = Customer::factory()->create(['status' => CustomerStatus::Active, 'name' => 'Atlas Sağlık']);
        $this->actingAs($this->admin);

        Livewire::test(KvkkPage::class)->assertSee('Atlas Sağlık')
            ->set('retention', '10')->call('save')->assertHasErrors('retention')
            ->set('retention', '180')
            ->set('rows.'.$customer->id.'.signed_on', '2026-09-01')->set('rows.'.$customer->id.'.note', 'Islak imzalı')->set('rows.'.$customer->id.'.health', true)
            ->call('save')->assertHasNoErrors()->assertSee('Kaydedildi.');

        $fresh = DB::table('customers')->where('id', $customer->id)->first();
        $this->assertSame('2026-09-01', substr((string) $fresh->kvkk_dpa_signed_on, 0, 10));
        $this->assertSame('Islak imzalı', $fresh->kvkk_dpa_note);
        $this->assertTrue((bool) $fresh->kvkk_health_data);
        $this->assertSame(180, (int) AgencySetting::query()->value('whatsapp_retention_days'));
        $this->get(route('operator.settings.kvkk'))->assertOk();

        $operator = User::factory()->create(['is_active' => true]);
        $operator->assignRole(Roles::TEAM_MEMBER);
        $this->actingAs($operator)->get(route('operator.settings.kvkk'))->assertForbidden();
    }
}
