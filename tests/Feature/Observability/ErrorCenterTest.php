<?php

namespace Tests\Feature\Observability;

use App\Enums\Observability\OperationalAlertRuleType;
use App\Enums\Observability\OperationalAlertSeverity;
use App\Enums\Observability\OperationalSignalFamily;
use App\Livewire\Operator\Settings\SystemHealthPage;
use App\Models\Observability\OperationalAlert;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Observability\ErrorTriage;
use App\Services\Observability\OperationalAlertLifecycleService;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Hata merkezi: open alerts in three buckets; self-healing ones stay quiet until they do not heal; one morning digest. */
final class ErrorCenterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        app()->setLocale('tr');
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
    }

    public function test_alerts_are_split_by_who_fixes_them_and_self_healing_ones_do_not_ring(): void
    {
        $transient = $this->observe('resource-automation.collection', '11', ['reason' => 'collection_failed', 'affected' => [['error_category' => 'provider']]]);
        $reconnect = $this->observe('resource-automation.collection', '12', ['reason' => 'reconnect']);
        $software = $this->observe('resource-automation.collection', '13', ['reason' => 'collection_failed', 'affected' => [['error_category' => 'contract_mismatch']]]);
        $quota = $this->observe('dataset_stale', 'dataset:stale', ['affected' => [['error_category' => 'quota', 'states' => ['STALE']]]]);
        $blocked = $this->observe('collection_repeated_failure', 'x', ['affected' => [['error_category' => 'provider', 'states' => ['ACTION_REQUIRED']]]]);

        $this->assertSame(
            [ErrorTriage::AUTO, ErrorTriage::YOU, ErrorTriage::CODE, ErrorTriage::AUTO, ErrorTriage::YOU],
            array_map(fn (OperationalAlert $a): string => ErrorTriage::cause($a), [$transient, $reconnect, $software, $quota, $blocked]),
        );
        $titles = UserNotification::query()->pluck('presentation')->map(fn ($p): string => (string) ($p['title'] ?? ''))->all();
        $this->assertCount(3, $titles, 'only "you" and "code" ring the bell');

        // Not healed after 48 hours: it becomes the operator's work.
        $transient->forceFill(['opened_at' => now()->subHours(ErrorTriage::ESCALATE_HOURS + 1)])->save();
        $this->assertSame(ErrorTriage::YOU, app(ErrorTriage::class)->bucket($transient->fresh()));

        $this->actingAs($this->admin);
        Livewire::test(SystemHealthPage::class)->assertSee('Hata merkezi')->assertSeeHtml('data-error-bucket="you"')->assertSeeHtml('data-error-bucket="code"')
            ->assertSeeHtml('data-error-bucket="auto"')->assertSee('2 günde düzelmedi')->assertSee('Sistem hallediyor');
    }

    public function test_the_morning_digest_names_only_what_needs_the_operator(): void
    {
        $this->artisan('moxdop:ops:error-digest')->assertSuccessful();
        $this->assertSame(0, UserNotification::query()->count(), 'nothing to do: no notice');

        $this->observe('resource-automation.collection', '21', ['reason' => 'collection_failed', 'affected' => [['error_category' => 'timeout']]]);
        $this->artisan('moxdop:ops:error-digest')->assertSuccessful();
        $this->assertSame(0, UserNotification::query()->count(), 'only self-healing work open: no notice');

        $this->observe('resource-automation.collection', '22', ['reason' => 'reconnect']);
        UserNotification::query()->delete();
        $this->artisan('moxdop:ops:error-digest')->assertSuccessful();
        $this->assertSame('Hata merkezi: 1 iş seni bekliyor · sistem 1 sorunu kendisi hallediyor', UserNotification::query()->sole()->presentation['title']);
    }

    /** @param  array<string, mixed>  $observed */
    private function observe(string $rule, string $scope, array $observed): OperationalAlert
    {
        return app(OperationalAlertLifecycleService::class)->observeCondition(
            ruleKey: $rule, ruleVersion: 1, ruleType: OperationalAlertRuleType::CollectionRepeatedFailure, family: OperationalSignalFamily::Collection,
            severity: OperationalAlertSeverity::Warning, scopeType: 'external_resource', scopeKey: $scope, title: 'x', summary: null, observed: $observed,
        );
    }
}
