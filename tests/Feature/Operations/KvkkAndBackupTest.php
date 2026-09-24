<?php

namespace Tests\Feature\Operations;

use App\Enums\CustomerStatus;
use App\Livewire\Operator\Settings\KvkkPage;
use App\Livewire\Operator\Settings\SystemHealthPage;
use App\Models\AgencySetting;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\User;
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
        file_put_contents($source, str_repeat('SQLite format 3 data ', 200));
        config(['database.connections.sqlite.database' => $source]);

        foreach (['2026-09-20 03:30:00', '2026-09-21 03:30:00', '2026-09-22 03:30:00'] as $at) {
            $this->travelTo($at);
            $result = app(SystemBackup::class)->run();
            $this->assertSame('succeeded', $result['status']);
        }
        @unlink($source);

        $this->assertStringStartsWith(str_repeat('SQLite format 3 data ', 2), gzdecode((string) file_get_contents($result['path'])));
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

    public function test_whatsapp_retention_blanks_old_texts_only_when_set(): void
    {
        $conversation = DB::table('whatsapp_conversations')->insertGetId(['integration_id' => CoreIntegration::factory()->create(['provider' => 'whatsapp'])->id, 'phone_number_id' => '1', 'contact_id' => '905551112233',
            'last_message_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        foreach (['old' => now()->subDays(100), 'new' => now()->subDays(5)] as $id => $sentAt) {
            DB::table('whatsapp_messages')->insert(['conversation_id' => $conversation, 'message_id' => $id, 'direction' => 'incoming', 'message_type' => 'text', 'body' => 'Tedavi fiyatı nedir?', 'sent_at' => $sentAt, 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->assertSame(0, app(WhatsAppRetention::class)->run(), 'off by default');
        AgencySetting::query()->create(['agency_name' => 'Moximu'])->forceFill(['whatsapp_retention_days' => 90])->save();

        $this->assertSame(1, app(WhatsAppRetention::class)->run());
        $this->assertSame(0, app(WhatsAppRetention::class)->run());
        $this->assertSame(WhatsAppRetention::REDACTED, DB::table('whatsapp_messages')->where('message_id', 'old')->value('body'));
        $this->assertSame('Tedavi fiyatı nedir?', DB::table('whatsapp_messages')->where('message_id', 'new')->value('body'));
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
