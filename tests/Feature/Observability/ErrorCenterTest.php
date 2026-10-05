<?php

namespace Tests\Feature\Observability;

use App\Enums\Collection\CollectionErrorCategory;
use App\Enums\Observability\OperationalAlertRuleType;
use App\Enums\Observability\OperationalAlertSeverity;
use App\Enums\Observability\OperationalSignalFamily;
use App\Livewire\Operator\Settings\SystemHealthPage;
use App\Models\Observability\OperationalAlert;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Collection\Providers\GoogleAds\GoogleAdsProviderErrorMapper;
use App\Services\Observability\ErrorTriage;
use App\Services\Observability\OperationalAlertLifecycleService;
use App\Services\Verification\LiveVerifier;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use RuntimeException;
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

    public function test_closed_accounts_and_mixed_stale_alerts_land_in_the_right_bucket(): void
    {
        // The morning live check says the Meta account is disabled: retrying never helps, it is the operator's.
        DB::table('live_checks')->insert(['check_key' => 'meta:31', 'provider' => 'meta', 'capability' => 'meta_ads', 'subject_type' => 'external_resource', 'subject_id' => 31,
            'label' => 'Meta Ads · X', 'status' => LiveVerifier::FAIL, 'message' => 'Hesap okunuyor ama reklam yayınlayamaz: DISABLED.', 'checked_at' => now()]);
        $disabled = $this->observe('resource-automation.collection', '31', ['reason' => 'collection_failed', 'affected' => [['error_category' => 'provider']]]);
        $this->assertSame(ErrorTriage::YOU, ErrorTriage::cause($disabled));
        $this->assertStringContainsString('DISABLED', app(ErrorTriage::class)->groups()[ErrorTriage::YOU][0]['items'][0]['what']);

        // Fourteen transient accounts and one software error: not "yazılım hatası" for all of them.
        $affected = array_merge(array_fill(0, 14, ['error_category' => 'provider', 'states' => ['STALE']]), [['error_category' => 'contract_mismatch', 'states' => ['STALE']]]);
        $this->assertSame(ErrorTriage::AUTO, ErrorTriage::cause($this->observe('dataset_stale', 'dataset:stale', ['affected' => $affected])));

        $mapped = app(GoogleAdsProviderErrorMapper::class)->fromThrowable(new RuntimeException('Google Ads authorization failed: The caller does not have permission | authorizationError:CUSTOMER_NOT_ENABLED'));
        $this->assertSame([CollectionErrorCategory::Authorization, 'CUSTOMER_NOT_ENABLED'], [$mapped->errorCategory, $mapped->errorCode], 'a disabled account is not an "unexpected error"');
    }

    public function test_the_page_reads_the_live_checks_once_for_every_alert_and_account(): void
    {
        // An older failure that the latest check cleared, and an older pass that the latest check turned into a failure.
        $this->liveCheck(105, LiveVerifier::FAIL, 'Hesap okunuyor ama reklam yayınlayamaz: DISABLED.', now()->subDays(2));
        $this->liveCheck(105, LiveVerifier::OK, null, now()->subDay());
        $this->liveCheck(130, LiveVerifier::OK, null, now()->subDays(2));
        $this->liveCheck(130, LiveVerifier::FAIL, 'PERMISSION_DENIED', now()->subDay());
        $this->liveCheck(140, LiveVerifier::FAIL, 'Hesap kapalı: CLOSED.', now()->subDay());

        // Twenty transient accounts, not healed after 48 hours; one closed account in a repeated failure; one account
        // the latest live check cannot read.
        $affected = array_map(fn (int $id): array => ['resource_id' => $id, 'error_category' => 'provider', 'states' => ['STALE']], range(101, 120));
        $stale = $this->observe('dataset_stale', 'dataset:stale', ['affected' => $affected]);
        $stale->forceFill(['opened_at' => now()->subHours(ErrorTriage::ESCALATE_HOURS + 1)])->save();
        $closed = $this->observe('collection_repeated_failure', 'x', ['affected' => [['resource_id' => 140, 'error_category' => 'provider', 'states' => ['STALE']]]]);
        $denied = $this->observe('resource-automation.collection', '130', ['reason' => 'collection_failed', 'affected' => [['error_category' => 'provider']]]);

        $queries = $this->liveCheckQueries(fn (): array => app(ErrorTriage::class)->groups());
        $this->assertLessThanOrEqual(2, $queries['count'], 'one table check and one read of the latest live checks, not one per alert and account');
        $items = collect($queries['result'][ErrorTriage::YOU])->flatMap(fn (array $group): array => $group['items'])->keyBy('id');
        $this->assertSame([], $queries['result'][ErrorTriage::AUTO]);
        $this->assertTrue($items[$stale->id]['escalated'], 'not healed after 48 hours');
        $this->assertFalse($items[$closed->id]['escalated'], 'the closed account is the operator\'s from the start');
        $this->assertFalse($items[$denied->id]['escalated']);
        $this->assertStringContainsString('Canlı doğrulama: PERMISSION_DENIED', $items[$denied->id]['what']);

        $this->assertSame(1, $this->liveCheckQueries(fn (): array => app(ErrorTriage::class)->groups())['count'], 'the table check is kept');

        // Outside the page the notifier reads the table itself: a check written after the page was built counts.
        $this->liveCheck(105, LiveVerifier::FAIL, 'Hesap okunuyor ama reklam yayınlayamaz: DISABLED.', now());
        $this->assertSame(ErrorTriage::YOU, ErrorTriage::cause($stale->fresh()));
        $this->assertSame('Hesap okunuyor ama reklam yayınlayamaz: DISABLED.', ErrorTriage::liveProblem(105));
        $this->assertNull(ErrorTriage::liveProblem(101));
    }

    /**
     * @param  callable(): array<string, mixed>  $run
     * @return array{count: int, result: array<string, mixed>}
     */
    private function liveCheckQueries(callable $run): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $result = $run();
        $count = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'live_checks'))->count();
        DB::disableQueryLog();

        return ['count' => $count, 'result' => $result];
    }

    private function liveCheck(int $resourceId, string $status, ?string $message, DateTimeInterface $at): void
    {
        DB::table('live_checks')->insert(['check_key' => 'meta:'.$resourceId, 'provider' => 'meta', 'capability' => 'meta_ads', 'subject_type' => 'external_resource',
            'subject_id' => $resourceId, 'label' => 'Meta Ads · '.$resourceId, 'status' => $status, 'message' => $message, 'checked_at' => $at]);
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
