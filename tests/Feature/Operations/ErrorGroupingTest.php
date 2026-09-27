<?php

namespace Tests\Feature\Operations;

use App\Livewire\Operator\Settings\SystemHealthPage;
use App\Models\User;
use App\Services\Operations\ErrorAlertReporter;
use App\Services\Operations\ReleaseInfo;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use LogicException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;
use Throwable;

final class ErrorGroupingTest extends TestCase
{
    use RefreshDatabase;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        config(['moxdop-observability.error_groups.enabled' => true, 'moxdop-observability.error_groups.throttle_seconds' => 60]);
        $this->storage = sys_get_temp_dir().'/moxdop-error-groups-'.uniqid();
        File::ensureDirectoryExists($this->storage.'/app');
        $this->app->useStoragePath($this->storage);
        ReleaseInfo::forget();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        ReleaseInfo::forget();
        parent::tearDown();
    }

    private function failure(int $orderId): RuntimeException
    {
        // same class, same line, same message shape; only the id differs
        return new RuntimeException("Order {$orderId} could not be synced for 'customer-{$orderId}'");
    }

    public function test_message_normalization_removes_values_but_keeps_shape(): void
    {
        $this->assertSame(
            'Order # could not be synced for ? at # (id #)',
            ErrorAlertReporter::normalizeMessage("Order 123 could not be synced for 'ahmet@example.com' at 2026-09-27 10:00:00 (id 3f2a9c1e-5b7d-4c11-9a0e-1234567890ab)"),
        );
        $this->assertSame(
            ErrorAlertReporter::fingerprint($this->failure(1), 'app/X.php:10'),
            ErrorAlertReporter::fingerprint($this->failure(2), 'app/X.php:10'),
        );
        $this->assertNotSame(
            ErrorAlertReporter::fingerprint($this->failure(1), 'app/X.php:10'),
            ErrorAlertReporter::fingerprint($this->failure(1), 'app/X.php:11'),
        );
    }

    public function test_repeated_errors_are_grouped_with_count_first_last_seen_and_release(): void
    {
        File::put($this->storage.'/app/release.json', json_encode(['sha' => 'abcdef0123456789abcdef0123456789abcdef01', 'deployed_at' => '2026-09-27T08:00:00Z']));
        ReleaseInfo::forget();
        $this->travelTo('2026-09-27 10:00:00');
        $reporter = app(ErrorAlertReporter::class);

        $reporter->report($this->failure(1));
        $first = DB::table('app_error_groups')->sole();
        $this->assertSame(1, (int) $first->occurrences);
        $this->assertSame(RuntimeException::class, $first->exception_class);
        $this->assertSame('Order # could not be synced for ?', $first->message);
        $this->assertStringStartsWith('tests/Feature/Operations/ErrorGroupingTest.php:', $first->location);
        $this->assertSame('abcdef0123456789abcdef0123456789abcdef01', $first->first_release);

        // within the throttle window: counted in the cache, no row write
        $this->travel(10)->seconds();
        $reporter->report($this->failure(2));
        $reporter->report($this->failure(3));
        $this->assertSame(1, (int) DB::table('app_error_groups')->value('occurrences'));

        // next window: the pending occurrences are flushed together
        $this->travel(61)->seconds();
        $reporter->report($this->failure(4));
        $group = DB::table('app_error_groups')->sole();
        $this->assertSame(4, (int) $group->occurrences);
        $this->assertSame('2026-09-27 10:00:00', substr((string) $group->first_seen_at, 0, 19));
        $this->assertSame('2026-09-27 10:01:11', substr((string) $group->last_seen_at, 0, 19));
        $this->assertSame('abcdef0123456789abcdef0123456789abcdef01', $group->last_release);
    }

    public function test_different_errors_get_their_own_groups_and_expected_errors_are_ignored(): void
    {
        $reporter = app(ErrorAlertReporter::class);
        $reporter->report($this->failure(1));
        $reporter->report(new LogicException('Something else broke'));
        $reporter->report(ValidationException::withMessages(['name' => 'required']));
        $reporter->report(new NotFoundHttpException('missing'));

        $this->assertSame(2, DB::table('app_error_groups')->count());
        $this->assertNull(DB::table('app_error_groups')->first()->first_release, 'no release file: release unknown');
    }

    public function test_the_exception_handler_feeds_grouping(): void
    {
        app(ExceptionHandler::class)->report(new RuntimeException('Handler path 42'));

        $this->assertSame('Handler path #', DB::table('app_error_groups')->value('message'));
    }

    public function test_error_inside_a_rolled_back_transaction_is_written_with_the_next_occurrence(): void
    {
        $reporter = app(ErrorAlertReporter::class);
        try {
            DB::transaction(function () use ($reporter): void {
                $reporter->report($this->failure(1));
                throw new RuntimeException('rollback');
            });
        } catch (Throwable) {
        }
        $this->assertSame(0, DB::table('app_error_groups')->count());

        $reporter->report($this->failure(2));
        $this->assertSame(2, (int) DB::table('app_error_groups')->value('occurrences'));
    }

    public function test_grouping_can_be_disabled(): void
    {
        config(['moxdop-observability.error_groups.enabled' => false]);
        app(ErrorAlertReporter::class)->report($this->failure(1));

        $this->assertSame(0, DB::table('app_error_groups')->count());
    }

    public function test_system_health_page_shows_top_error_groups_and_release(): void
    {
        File::put($this->storage.'/app/release.json', json_encode(['sha' => '0123456789abcdef0123456789abcdef01234567', 'deployed_at' => '2026-09-27T08:00:00Z']));
        ReleaseInfo::forget();
        app(ErrorAlertReporter::class)->report($this->failure(7));
        DB::table('app_error_groups')->insert([
            'fingerprint' => str_repeat('a', 40), 'exception_class' => 'App\\Old\\StaleException', 'location' => 'app/Old.php:1',
            'message' => 'old', 'occurrences' => 99, 'first_seen_at' => now()->subDays(30), 'last_seen_at' => now()->subDays(8),
        ]);
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);

        Livewire::test(SystemHealthPage::class)
            ->assertSee('Uygulama hataları')
            ->assertSee('RuntimeException')
            ->assertSee('Order # could not be synced for ?')
            ->assertSee('1 kez')
            ->assertSee('0123456789ab')
            ->assertDontSee('StaleException');
    }
}
