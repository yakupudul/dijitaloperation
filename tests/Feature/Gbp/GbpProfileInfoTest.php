<?php

namespace Tests\Feature\Gbp;

use App\Enums\CustomerStatus;
use App\Livewire\Operator\Gbp\Desk\ProfileFieldsPage;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\Page;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\Gbp\Desk\BranchPages;
use App\Services\Gbp\Desk\GbpDesk;
use App\Services\Gbp\Desk\ProfileFields;
use App\Services\Gbp\Desk\ProfileInfo;
use App\Services\Gbp\GbpSuggestions;
use App\Services\Integrations\Google\GoogleApiClient;
use App\Services\Repair\RepairDesk;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * ADR-080 (Onarım Faz 4): weekly hours, phone, primary category, yes / no attributes, appointment link and video written
 * to the Business Profile after the Admin's approval and undone; the nightly preparation fills what the brand's own
 * data can and the rows wait on the Onarım masası.
 */
final class GbpProfileInfoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Brand $brand;

    private DigitalAsset $location;

    /** @var array<string, mixed> Google's live copy of location 22 */
    private array $live = [
        'regularHours' => ['periods' => [['openDay' => 'MONDAY', 'openTime' => ['hours' => 9], 'closeDay' => 'MONDAY', 'closeTime' => ['hours' => 17]]]],
        'phoneNumbers' => ['primaryPhone' => '0312 555 55 55', 'additionalPhones' => ['0312 555 55 56']],
        'categories' => ['primaryCategory' => ['name' => 'categories/gcid:dental_clinic'], 'additionalCategories' => [['name' => 'categories/gcid:dentist']]],
    ];

    /** @var array<string, list<mixed>> */
    private array $attributes = ['attributes/has_wheelchair_accessible_entrance' => [false]];

    /** @var list<array<string, mixed>> */
    private array $links = [];

    /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-08 08:00', 'Europe/Istanbul'));
        $this->seed(RoleAndPermissionSeeder::class);
        config(['moxdop.google.client_id' => 'cid', 'moxdop.google.client_secret' => 'csecret']);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id, 'name' => 'Panorama']);
        DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'name' => 'panorama.test', 'domain' => 'panorama.test', 'primary_url' => 'https://panorama.test/']);
        $this->location = $this->profile('İşletme Profili · Panorama Çankaya', '22', hours: false);
        DB::table('gbp_attribute_snapshots')->insert(['run_id' => 1, 'digital_asset_id' => $this->location->id, 'external_resource_id' => 1, 'location_name' => 'locations/22',
            'attributes' => json_encode(['attributes' => [['name' => 'attributes/has_wheelchair_accessible_entrance', 'valueType' => 'BOOL', 'values' => [false]]]]),
            'available_attributes' => json_encode([
                ['parent' => 'attributes/has_wheelchair_accessible_entrance', 'valueType' => 'BOOL', 'displayName' => 'Tekerlekli sandalye girişi', 'groupDisplayName' => 'Erişilebilirlik'],
                ['parent' => 'attributes/has_restroom', 'valueType' => 'BOOL', 'displayName' => 'Tuvalet', 'groupDisplayName' => 'Olanaklar'],
                ['parent' => 'attributes/url_appointment', 'valueType' => 'URL', 'displayName' => 'Randevu'],
            ]), 'captured_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        Http::fake(function (Request $request) {
            $url = $request->url();
            $this->calls[] = [$request->method(), $url, $request->data()];
            if (str_contains($url, 'mybusinessplaceactions')) {
                if ($request->method() === 'POST') {
                    $link = ['name' => 'locations/22/placeActionLinks/l1', 'placeActionType' => 'APPOINTMENT', 'uri' => $request->data()['uri']];
                    $this->links[] = $link;

                    return Http::response($link);
                }
                if ($request->method() === 'DELETE') {
                    $this->links = array_values(array_filter($this->links, fn (array $l): bool => ! str_ends_with($url, $l['name'])));

                    return Http::response([]);
                }

                return Http::response(['placeActionLinks' => $this->links]);
            }
            if (str_contains($url, '/attributes')) {
                if ($request->method() === 'PATCH') {
                    preg_match('/attributeMask=([^&]+)/', $url, $m);
                    $sent = collect($request->data()['attributes'] ?? [])->keyBy('name');
                    foreach (explode(',', urldecode($m[1])) as $name) {
                        if ($sent->has($name)) {
                            $this->attributes[$name] = $sent[$name]['values'];
                        } else {
                            unset($this->attributes[$name]);
                        }
                    }
                }

                return Http::response(['attributes' => collect($this->attributes)->map(fn (array $v, string $n): array => ['name' => $n, 'valueType' => 'BOOL', 'values' => $v])->values()->all()]);
            }
            if ($request->method() === 'PATCH') {
                $this->live = array_merge($this->live, $request->data());
            }

            return Http::response($this->live);
        });
    }

    private function profile(string $name, string $id, bool $hours): DigitalAsset
    {
        $asset = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_business_profile', 'status' => 'active', 'name' => $name]);
        $google = CoreIntegration::query()->where('provider', 'google')->first() ?? tap(CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE]), function (CoreIntegration $google): void {
            CoreIntegrationCredential::factory()->provider()->create(['integration_id' => $google->id, 'encrypted_payload' => ['client_id' => 'cid', 'client_secret' => 'csecret']]);
            CoreIntegrationCredential::factory()->authorization()->create(['integration_id' => $google->id, 'encrypted_payload' => ['access_token' => 'a', 'refresh_token' => 'r'], 'expires_at' => now()->addYear()]);
        });
        $resource = CoreExternalResource::factory()->create(['integration_id' => $google->id, 'provider' => 'google', 'resource_type' => 'google_business_profile',
            'external_id' => 'accounts/11/locations/'.$id, 'status' => CoreExternalResource::STATUS_AVAILABLE]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $asset->id, 'external_resource_id' => $resource->id, 'capability' => 'google_business_profile', 'status' => CoreAssetBinding::STATUS_ACTIVE]);
        DB::table('gbp_location_snapshots')->insert(['run_id' => (int) $id, 'external_resource_id' => $resource->id, 'location_name' => 'locations/'.$id, 'title' => $name,
            'website_uri' => $hours ? 'https://panorama.test/' : null, 'primary_category' => 'Diş kliniği', 'phone_numbers' => json_encode(['primaryPhone' => '0312 555 55 55']),
            'regular_hours' => json_encode($hours ? ['periods' => [['openDay' => 'MONDAY', 'openTime' => ['hours' => 9], 'closeDay' => 'MONDAY', 'closeTime' => ['hours' => 19]],
                ['openDay' => 'SATURDAY', 'openTime' => ['hours' => 10], 'closeDay' => 'SATURDAY', 'closeTime' => ['hours' => 14]]]] : []),
            'captured_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        return $asset;
    }

    /** @return list<array{0: string, 1: string, 2: array<string, mixed>}> */
    private function writes(): array
    {
        return array_values(array_filter($this->calls, fn (array $c): bool => $c[0] !== 'GET'));
    }

    public function test_hours_phone_category_attributes_and_appointment_go_to_google_and_undo_restores_them(): void
    {
        $action = app(ExternalWriteService::class)->requestProfileFields($this->admin, $this->location, [
            'regular_hours' => [['day' => 'MONDAY', 'open' => '09:00', 'close' => '19:00'], ['day' => 'FRIDAY', 'open' => '20:00', 'close' => '02:00']],
            'phone' => '+90 312 444 44 44',
            'primary_category' => ['id' => 'categories/gcid:dentist', 'name' => 'Diş hekimi'],
            'attributes' => [['name' => 'attributes/has_wheelchair_accessible_entrance', 'value' => true], ['name' => 'attributes/has_restroom', 'value' => true]],
            'appointment_url' => 'https://randevu.example/panorama',
        ], 'Bilgiler');

        $this->assertSame('succeeded', $action->refresh()->status, (string) $action->error);
        $this->assertSame('SATURDAY', $this->live['regularHours']['periods'][1]['closeDay'], 'a close before the opening is the next day');
        $this->assertSame(['primaryPhone' => '+90 312 444 44 44', 'additionalPhones' => ['0312 555 55 56']], $this->live['phoneNumbers']);
        $this->assertSame('categories/gcid:dentist', $this->live['categories']['primaryCategory']['name']);
        $this->assertSame([['name' => 'categories/gcid:dental_clinic']], $this->live['categories']['additionalCategories'], 'the old primary stays, the new one leaves the additional list');
        $this->assertSame([true], $this->attributes['attributes/has_restroom']);
        $this->assertCount(1, $this->links);

        app(ExternalWriteService::class)->requestUndo($this->admin, $action);

        $this->assertSame('undone', $action->refresh()->status, (string) $action->error);
        $this->assertSame(17, $this->live['regularHours']['periods'][0]['closeTime']['hours']);
        $this->assertSame('0312 555 55 55', $this->live['phoneNumbers']['primaryPhone']);
        $this->assertSame('categories/gcid:dental_clinic', $this->live['categories']['primaryCategory']['name']);
        $this->assertSame(['attributes/has_wheelchair_accessible_entrance' => [false]], $this->attributes, 'the restroom attribute set by MoxDOP is cleared again');
        $this->assertSame([], $this->links);
    }

    public function test_fields_are_validated_and_only_these_profile_targets_can_be_written(): void
    {
        $writes = app(ExternalWriteService::class);
        $refused = function (array $fields): string {
            try {
                app(ExternalWriteService::class)->requestProfileFields($this->admin, $this->location, $fields, 'x');
            } catch (ValidationException $exception) {
                return (string) collect($exception->errors())->flatten()->first();
            }
            $this->fail('Expected a refusal.');
        };

        $this->assertStringContainsString('Telefon', $refused(['phone' => '123']));
        $this->assertStringContainsString('Google listesinden', $refused(['primary_category' => ['id' => 'Diş kliniği']]));
        $this->assertStringContainsString('Google listesinde yok', $refused(['attributes' => [['name' => 'attributes/url_appointment', 'value' => true]]]));
        $this->assertStringContainsString('Çalışma saati', $refused(['regular_hours' => [['day' => 'MONDAY', 'open' => '25:00', 'close' => '18:00']]]));
        $this->assertStringContainsString('https', $refused(['appointment_url' => 'http://randevu.example']));
        $this->assertSame(0, ExternalWriteAction::query()->count());
        $this->expectException(ValidationException::class);
        $writes->requestVideo($this->admin, $this->location, 'https://panorama.test/tanitim.gif');
    }

    public function test_write_client_refuses_any_other_profile_field(): void
    {
        $integration = CoreIntegration::query()->where('provider', 'google')->firstOrFail();
        $this->expectException(RuntimeException::class);
        app(GoogleApiClient::class)->writeBusinessProfile($integration, 'patch', 'https://mybusinessbusinessinformation.googleapis.com/v1/locations/22?updateMask=title', ['title' => 'x']);
    }

    public function test_only_a_top_level_booking_page_counts_as_the_appointment_link(): void
    {
        $this->assertTrue(ProfileInfo::isAppointmentPage('https://avrupadent.com.tr/randevu-olustur/'));
        $this->assertTrue(ProfileInfo::isAppointmentPage('https://www.panoramaankara.com/randevu-talebi-olustur/'));
        $this->assertTrue(ProfileInfo::isAppointmentPage('https://site.test/tr/randevu/'));
        $this->assertFalse(ProfileInfo::isAppointmentPage('https://site.test/en/appointment/'), 'another language is not the Turkish profile\'s booking page');
        $this->assertFalse(ProfileInfo::isAppointmentPage('https://www.burcinoncul.com.tr/soru-cevap/kontrol-randevulari-ne-siklikla-yapilir/'));
        $this->assertFalse(ProfileInfo::isAppointmentPage('https://site.test/randevu-almadan-once-bilinmesi-gerekenler/'));
        $this->assertFalse(ProfileInfo::isAppointmentPage('https://site.test/'));
    }

    public function test_video_goes_as_video_media(): void
    {
        $action = app(ExternalWriteService::class)->requestVideo($this->admin, $this->location, 'https://panorama.test/tanitim.mp4');

        $post = collect($this->writes())->first(fn (array $c): bool => str_ends_with($c[1], '/media'));
        $this->assertSame('VIDEO', $post[2]['mediaFormat'] ?? null, (string) $action->refresh()->error);
    }

    public function test_nightly_preparation_fills_from_the_brands_own_data_and_waits_on_the_repair_desk(): void
    {
        $this->profile('İşletme Profili · Panorama Kızılay', '33', hours: true);
        $this->profile('İşletme Profili · Panorama Bahçeli', '44', hours: true);
        $site = DigitalAsset::query()->where('type', 'website')->firstOrFail();
        Page::query()->create(['website_asset_id' => $site->id, 'url' => 'https://panorama.test/online-randevu/', 'url_hash' => sha1('r'), 'path' => '/online-randevu/', 'title' => 'Online randevu']);

        $this->artisan('moxdop:repair:prepare', ['--fields' => 0, '--content' => 0])->assertSuccessful();

        $rows = app(RepairDesk::class)->rows(kind: RepairDesk::GBP_FIELDS)->where('asset_id', $this->location->id);
        $this->assertEqualsCanonicalizing(['regular_hours', 'website_uri', 'appointment_url'],
            Suggestion::query()->whereIn('id', $rows->pluck('id'))->get()->map(fn (Suggestion $s): string => (string) $s->action['field'])->all());
        $hours = $rows->first(fn (array $r): bool => str_contains($r['target'], 'Çalışma saatleri'));
        $this->assertStringContainsString('Pzt 09:00–19:00', $hours['after'][0]);
        $this->assertStringContainsString('Panorama Çankaya', $hours['target']);

        $result = app(RepairDesk::class)->approve([$hours['id']], $this->admin);

        $this->assertSame(1, $result['applied'], implode(' ', $result['failed']));
        $this->assertSame(19, $this->live['regularHours']['periods'][0]['closeTime']['hours']);
        $this->assertSame(Suggestion::APPLIED, Suggestion::query()->find($hours['id'])->status);
    }

    public function test_bilgiler_section_sends_only_what_changed(): void
    {
        Livewire::test(ProfileFieldsPage::class)
            ->call('setSection', 'bilgiler')->assertSee('Bilgiler')->assertSee('Girilmemiş')
            ->call('startInfo', $this->location->id)->assertSee('Tekerlekli sandalye girişi')
            ->set('info.hours.TUESDAY.open', true)->set('info.attributes.has_restroom', 'yes')
            ->call('sendInfo')->assertHasNoErrors();

        $action = ExternalWriteAction::query()->latest('id')->firstOrFail();
        $this->assertSame(['regular_hours', 'attributes'], array_keys($action->request_payload['fields']));
        $this->assertSame([['day' => 'TUESDAY', 'open' => '09:00', 'close' => '18:00']], $action->request_payload['fields']['regular_hours']);
        $this->assertSame([['name' => 'attributes/has_restroom', 'value' => true]], $action->request_payload['fields']['attributes']);
    }

    public function test_stale_rows_leave_the_desk_and_a_field_filled_since_is_refused(): void
    {
        $this->profile('İşletme Profili · Panorama Kızılay', '33', hours: true);
        $this->profile('İşletme Profili · Panorama Bahçeli', '44', hours: true);
        $info = app(ProfileInfo::class);
        $info->prepareAll($this->brand->id);
        $rows = $info->open([$this->location->id])->keyBy(fn (Suggestion $s): string => (string) $s->action['field']);
        $this->assertEqualsCanonicalizing(['regular_hours', 'website_uri'], $rows->keys()->all());

        $rows['website_uri']->forceFill(['status' => Suggestion::RECHECK])->save();
        $this->assertSame(['regular_hours'], $info->open([$this->location->id])->map(fn (Suggestion $s): string => (string) $s->action['field'])->values()->all(), 'a row to re-check is not sent from the desk');

        $resource = (int) CoreAssetBinding::query()->where('digital_asset_id', $this->location->id)->value('external_resource_id');
        DB::table('gbp_location_snapshots')->insert(['run_id' => 99, 'external_resource_id' => $resource, 'location_name' => 'locations/22', 'title' => 'Panorama Çankaya',
            'regular_hours' => json_encode(['periods' => [['openDay' => 'MONDAY', 'openTime' => ['hours' => 8], 'closeDay' => 'MONDAY', 'closeTime' => ['hours' => 16]]]]),
            'captured_at' => now()->addMinute(), 'created_at' => now(), 'updated_at' => now()]);

        try {
            $info->send($this->admin, $rows['regular_hours']);
            $this->fail('Expected a refusal.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('artık dolu', (string) collect($exception->errors())->flatten()->first());
        }
        $this->assertSame(0, ExternalWriteAction::query()->count());
    }

    public function test_a_description_proposal_is_refused_when_the_profiles_description_changed_since(): void
    {
        $proposal = function (string $current): Suggestion {
            app(GbpSuggestions::class)->replaceGroup($this->location, 'description', [['key' => 'description', 'title' => 'Açıklamayı güncelle', 'reason' => 'x', 'priority' => 2,
                'evidence' => [], 'action_type' => 'gbp_description', 'action' => ['current' => $current, 'proposed' => 'Panorama Çankaya, Ankara Çankaya’da implant, zirkonyum kaplama ve ortodonti tedavileri sunan bir diş kliniğidir. Tedavi seçenekleri birlikte planlanır.']]]);

            return Suggestion::query()->where('action_type', 'gbp_description')->where('status', Suggestion::OPEN)->latest('id')->firstOrFail();
        };
        $fields = app(ProfileFields::class);

        try {
            $fields->sendDescription($this->admin, $this->location, 'Panorama Çankaya, Ankara Çankaya’da implant, zirkonyum kaplama ve ortodonti tedavileri sunan bir diş kliniğidir. Tedavi seçenekleri birlikte planlanır.', $proposal('Eski açıklama'));
            $this->fail('Expected a refusal.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('öneri eskidi', (string) collect($exception->errors())->flatten()->first());
        }
        $this->assertSame(0, ExternalWriteAction::query()->count());

        $fields->sendDescription($this->admin, $this->location, 'Panorama Çankaya, Ankara Çankaya’da implant, zirkonyum kaplama ve ortodonti tedavileri sunan bir diş kliniğidir. Tedavi seçenekleri birlikte planlanır.', $proposal(''));
        $this->assertSame(1, ExternalWriteAction::query()->count(), 'the profile still has the description the proposal was written for');
    }

    public function test_hours_are_copied_only_when_every_sibling_with_hours_agrees(): void
    {
        $week = fn (int $close): array => [['openDay' => 'MONDAY', 'openTime' => ['hours' => 9], 'closeDay' => 'MONDAY', 'closeTime' => ['hours' => $close]]];

        $this->assertNull(ProfileInfo::commonHours(collect([['regular_hours' => $week(18)]])), 'one sibling is not enough');
        $this->assertSame($week(18), ProfileInfo::commonHours(collect([['regular_hours' => $week(18)], ['regular_hours' => $week(18)], ['regular_hours' => []]])));
        $this->assertNull(ProfileInfo::commonHours(collect([['regular_hours' => $week(18)], ['regular_hours' => $week(18)], ['regular_hours' => $week(20)]])), 'no plurality vote');
    }

    public function test_website_link_is_the_branchs_own_page_when_the_brand_has_several_profiles(): void
    {
        $resource = (int) CoreAssetBinding::query()->where('digital_asset_id', $this->location->id)->value('external_resource_id');
        DB::table('gbp_location_snapshots')->where('external_resource_id', $resource)->update(['storefront_address' => json_encode(['sublocality' => 'Çankaya', 'locality' => 'Ankara'])]);
        $site = DigitalAsset::query()->where('type', 'website')->firstOrFail();
        Page::query()->create(['website_asset_id' => $site->id, 'url' => 'https://panorama.test/cankaya-subesi/', 'url_hash' => sha1('c'), 'path' => '/cankaya-subesi/',
            'title' => 'Çankaya Şubesi', 'category' => 'lokasyon', 'language' => 'tr', 'is_indexable' => true]);
        $website = function (): ?string {
            $snapshot = app(GbpDesk::class)->snapshots([$this->location->id])[$this->location->id];
            $item = collect(app(ProfileInfo::class)->items($this->location, $snapshot, collect()))->firstWhere('action.field', 'website_uri');

            return $item['action']['fields']['website_uri'] ?? null;
        };

        $this->assertSame('https://panorama.test/', $website(), 'a single profile links the home page');

        $this->profile('İşletme Profili · Panorama Kızılay', '33', hours: true);
        $this->assertSame('https://panorama.test/cankaya-subesi/?'.BranchPages::UTM, $website());
    }
}
