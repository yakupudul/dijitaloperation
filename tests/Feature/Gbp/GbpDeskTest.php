<?php

namespace Tests\Feature\Gbp;

use App\Ai\Agents\GbpBranchPageAgent;
use App\Enums\CustomerStatus;
use App\Livewire\Demo\Gbp\OverviewPage;
use App\Livewire\Operator\Gbp\Desk\BranchPagesPage;
use App\Livewire\Operator\Gbp\Desk\PhotosPage;
use App\Livewire\Operator\Gbp\Desk\ProfileFieldsPage;
use App\Livewire\Operator\Gbp\Desk\ReviewsPage;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\GbpBranchPage;
use App\Models\GbpPhoto;
use App\Models\Page;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\Gbp\Desk\BranchPages;
use App\Services\Gbp\Desk\GbpDesk;
use App\Services\Gbp\Desk\GbpPerformance;
use App\Services\Gbp\Desk\PhotoPlan;
use App\Services\Gbp\Desk\ReviewDesk;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * ADR-079 İşletme profilleri masası: description / holiday hours / website link written to Google and undone, photos
 * from the brand's site, the branch page (AI text + profile facts, linking the profile to it), the review list and kit,
 * the monthly report, and every tab renders.
 */
final class GbpDeskTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Brand $brand;

    private DigitalAsset $location;

    private DigitalAsset $site;

    private int $resourceId;

    /** Google's live copy of the location (what GET returns; PATCH changes it). */
    private array $live = ['profile' => ['description' => 'Eski açıklama'], 'websiteUri' => 'https://panorama.test/', 'specialHours' => ['specialHourPeriods' => []]];

    /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-06 08:00', 'Europe/Istanbul'));
        $this->seed(RoleAndPermissionSeeder::class);
        config(['moxdop.anthropic.api_key' => 'test-anthropic-value', 'moxdop.google.client_id' => 'cid', 'moxdop.google.client_secret' => 'csecret']);
        CoreIntegration::factory()->anthropic()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $dental = ServiceCategory::query()->firstOrCreate(['code' => 'dental'], ['name' => 'Diş sağlığı', 'normalized_key' => 'dis sagligi']);
        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id, 'name' => 'Panorama', 'sector_id' => $dental->id]);
        $this->site = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'name' => 'panorama.test', 'domain' => 'panorama.test', 'primary_url' => 'https://panorama.test/']);
        $this->location = $this->profile('İşletme Profili · Panorama Çankaya', 'accounts/11/locations/22');
        DB::table('gbp_location_snapshots')->insert(['run_id' => 1, 'external_resource_id' => $this->resourceId, 'location_name' => 'locations/22', 'title' => 'Panorama Çankaya',
            'place_id' => 'ChIJtest123', 'maps_uri' => 'https://maps.google.com/?cid=1', 'website_uri' => 'https://panorama.test/', 'primary_category' => 'Diş kliniği',
            'storefront_address' => json_encode(['addressLines' => ['Atatürk Bulvarı 10'], 'sublocality' => 'Çankaya', 'locality' => 'Ankara', 'administrativeArea' => 'Ankara', 'regionCode' => 'TR']),
            'phone_numbers' => json_encode(['primaryPhone' => '0312 555 55 55']), 'profile' => json_encode(['description' => 'Kısa açıklama']),
            'regular_hours' => json_encode(['periods' => [['openDay' => 'MONDAY', 'openTime' => ['hours' => 9], 'closeTime' => ['hours' => 18], 'closeDay' => 'MONDAY']]]),
            'captured_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        Http::fake(function (Request $request) {
            $this->calls[] = [$request->method(), $request->url(), $request->data()];
            if ($request->method() === 'GET' && str_contains($request->url(), 'mybusinessbusinessinformation')) {
                return Http::response($this->live);
            }
            if ($request->method() === 'PATCH') {
                $body = $request->data();
                if (isset($body['profile']['description'])) {
                    $this->live['profile']['description'] = $body['profile']['description'];
                }
                if (array_key_exists('websiteUri', $body)) {
                    $this->live['websiteUri'] = $body['websiteUri'];
                }
                if (isset($body['specialHours'])) {
                    $this->live['specialHours'] = $body['specialHours'];
                }

                return Http::response($this->live);
            }
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/media')) {
                return Http::response(['name' => 'accounts/11/locations/22/media/m1', 'googleUrl' => 'https://lh3.googleusercontent.com/m1']);
            }

            return Http::response([]);
        });
    }

    private function profile(string $name, string $externalId): DigitalAsset
    {
        $asset = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_business_profile', 'status' => 'active', 'name' => $name]);
        $google = CoreIntegration::query()->where('provider', 'google')->first() ?? tap(CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE]), function (CoreIntegration $google): void {
            CoreIntegrationCredential::factory()->provider()->create(['integration_id' => $google->id, 'encrypted_payload' => ['client_id' => 'cid', 'client_secret' => 'csecret']]);
            CoreIntegrationCredential::factory()->authorization()->create(['integration_id' => $google->id, 'encrypted_payload' => ['access_token' => 'a', 'refresh_token' => 'r'], 'expires_at' => now()->addYear()]);
        });
        $resource = CoreExternalResource::factory()->create(['integration_id' => $google->id, 'provider' => 'google', 'resource_type' => 'google_business_profile',
            'external_id' => $externalId, 'status' => CoreExternalResource::STATUS_AVAILABLE]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $asset->id, 'external_resource_id' => $resource->id, 'capability' => 'google_business_profile', 'status' => CoreAssetBinding::STATUS_ACTIVE]);
        $this->resourceId = (int) $resource->id;

        return $asset;
    }

    /** A second profile of the brand without collected data (the brand becomes multi-branch). */
    private function secondBranch(): DigitalAsset
    {
        $resourceId = $this->resourceId;
        $second = $this->profile('İşletme Profili · Panorama Kızılay', 'accounts/11/locations/'.(33 + DigitalAsset::query()->count()));
        $this->resourceId = $resourceId;

        return $second;
    }

    /** @return list<array{0: string, 1: string, 2: array<string, mixed>}> */
    private function writes(): array
    {
        return array_values(array_filter($this->calls, fn (array $c): bool => $c[0] !== 'GET' && str_contains($c[1], 'googleapis.com')));
    }

    private function description(): string
    {
        return 'Panorama Çankaya, Ankara Çankaya’da implant, zirkonyum kaplama ve ortodonti tedavileri sunan bir diş kliniğidir. Muayenede ağız ve diş sağlığı ayrıntılı değerlendirilir, tedavi seçenekleri birlikte planlanır.';
    }

    public function test_description_goes_to_google_and_undo_restores_the_previous_text(): void
    {
        $action = app(ExternalWriteService::class)->requestProfileFields($this->admin, $this->location, ['description' => $this->description()], 'Açıklama');

        $this->assertSame('succeeded', $action->refresh()->status);
        $this->assertSame($this->description(), $this->live['profile']['description']);
        $this->assertStringContainsString('locations/22?updateMask=profile.description', $this->writes()[0][1]);
        $this->assertSame('Eski açıklama', $action->result['before']['description']);

        app(ExternalWriteService::class)->requestUndo($this->admin, $action);

        $this->assertSame('undone', $action->refresh()->status);
        $this->assertSame('Eski açıklama', $this->live['profile']['description']);
    }

    public function test_holiday_hours_change_only_the_sent_dates_and_undo_puts_those_dates_back(): void
    {
        $other = ['startDate' => ['year' => 2026, 'month' => 10, 'day' => 29], 'endDate' => ['year' => 2026, 'month' => 10, 'day' => 29], 'closed' => true];
        $before = ['startDate' => ['year' => 2027, 'month' => 1, 'day' => 1], 'endDate' => ['year' => 2027, 'month' => 1, 'day' => 1], 'openTime' => ['hours' => 10], 'closeTime' => ['hours' => 14]];
        $this->live['specialHours']['specialHourPeriods'] = [$other, $before];

        $action = app(ExternalWriteService::class)->requestProfileFields($this->admin, $this->location, ['special_hours' => [['date' => '2027-01-01', 'closed' => true]]], 'Özel gün saatleri · Yılbaşı');

        $this->assertSame('succeeded', $action->refresh()->status);
        $periods = $this->live['specialHours']['specialHourPeriods'];
        $this->assertCount(2, $periods);
        $this->assertSame($other, $periods[0]);
        $this->assertTrue($periods[1]['closed']);

        app(ExternalWriteService::class)->requestUndo($this->admin, $action);

        $this->assertSame([$other, $before], $this->live['specialHours']['specialHourPeriods']);
    }

    public function test_profile_fields_refuse_contact_data_past_dates_foreign_links_and_non_admins(): void
    {
        $writes = app(ExternalWriteService::class);
        $refused = function (callable $call): string {
            try {
                $call();
            } catch (ValidationException $exception) {
                return (string) collect($exception->errors())->flatten()->first();
            }
            $this->fail('Expected a refusal.');
        };

        $this->assertStringContainsString('telefon', $refused(fn () => $writes->requestProfileFields($this->admin, $this->location, ['description' => $this->description().' Bilgi için 0312 555 55 55'], 'Açıklama')));
        $this->assertStringContainsString('100', $refused(fn () => $writes->requestProfileFields($this->admin, $this->location, ['description' => 'Kısa'], 'Açıklama')));
        $this->assertStringContainsString('2026-10-01', $refused(fn () => $writes->requestProfileFields($this->admin, $this->location, ['special_hours' => [['date' => '2026-10-01', 'closed' => true]]], 'x')));
        $this->assertStringContainsString('2026-12-31', $refused(fn () => $writes->requestProfileFields($this->admin, $this->location, ['special_hours' => [['date' => '2026-12-31', 'open' => '18:00', 'close' => '09:00']]], 'x')));
        $this->assertStringContainsString('kendi sitesinde', $refused(fn () => $writes->requestProfileFields($this->admin, $this->location, ['website_uri' => 'https://baska.test/cankaya/'], 'x')));
        $this->assertSame(0, ExternalWriteAction::query()->count());

        $operator = User::factory()->create(['is_active' => true]);
        $operator->assignRole(Roles::TEAM_MEMBER);
        $this->expectException(HttpException::class);
        $writes->requestProfileFields($operator, $this->location, ['description' => $this->description()], 'Açıklama');
    }

    public function test_photos_come_from_the_brands_site_go_once_per_profile_and_can_be_removed(): void
    {
        $attachment = fn (string $id, string $url, array $meta): bool => DB::table('website_cms_object_snapshot')->insert([
            'digital_asset_id' => $this->site->id, 'cms' => 'wordpress', 'object_type' => 'attachment', 'object_id' => $id, 'permalink' => $url, 'title' => basename($url), 'metadata' => json_encode($meta),
            'observed_at' => now(), 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => hash('sha256', $id),
        ]);
        $attachment('1', 'https://panorama.test/wp-content/uploads/klinik-ic-mekan.jpg', ['mime_type' => 'image/jpeg', 'width' => 1600, 'height' => 1067, 'file_size' => 240000]);
        $attachment('2', 'https://panorama.test/wp-content/uploads/ikon.png', ['mime_type' => 'image/png', 'width' => 64, 'height' => 64, 'file_size' => 12000]);
        $attachment('3', 'https://panorama.test/wp-content/uploads/brosur.pdf', ['mime_type' => 'application/pdf']);

        $plan = app(PhotoPlan::class);
        $candidates = $plan->candidates($this->location);
        $this->assertSame(['https://panorama.test/wp-content/uploads/klinik-ic-mekan.jpg'], array_column($candidates, 'url'));
        $this->assertSame('INTERIOR', $candidates[0]['category']);
        $this->assertTrue($plan->status([$this->location->id => $this->resourceId])[$this->location->id]['stale']);

        $this->assertSame(1, $plan->send($this->admin, $this->location, [['url' => $candidates[0]['url'], 'category' => 'INTERIOR']]));

        $media = collect($this->writes())->first(fn (array $c): bool => str_ends_with($c[1], '/media'));
        $this->assertSame(['mediaFormat' => 'PHOTO', 'locationAssociation' => ['category' => 'INTERIOR'], 'sourceUrl' => 'https://panorama.test/wp-content/uploads/klinik-ic-mekan.jpg'], $media[2]);
        $photo = GbpPhoto::query()->sole();
        $this->assertSame(GbpPhoto::UPLOADED, $photo->status);
        $this->assertSame([], $plan->candidates($this->location));
        $this->assertFalse($plan->status([$this->location->id => $this->resourceId])[$this->location->id]['stale']);
        $this->assertSame(0, $plan->send($this->admin, $this->location, [['url' => $candidates[0]['url']]]));

        app(ExternalWriteService::class)->requestUndo($this->admin, $photo->writeAction);

        $this->assertSame(GbpPhoto::REMOVED, $photo->refresh()->status);
        $this->assertSame(['DELETE', 'https://mybusiness.googleapis.com/v4/accounts/11/locations/22/media/m1'], array_slice(collect($this->writes())->last(), 0, 2));
    }

    public function test_branch_page_is_written_from_the_profile_and_the_profile_is_linked_to_the_page_with_utm(): void
    {
        Page::query()->create(['website_asset_id' => $this->site->id, 'url' => 'https://panorama.test/implant/', 'url_hash' => hash('sha256', '/implant/'), 'path' => '/implant/',
            'title' => 'Diş İmplantı', 'category' => 'hizmet', 'language' => 'tr', 'is_indexable' => true, 'word_count' => 300]);
        $this->secondBranch();
        $desk = app(GbpDesk::class);
        $pages = app(BranchPages::class);
        $locations = $desk->locations();
        $this->assertSame('missing', $pages->states($locations, $desk->snapshots([$this->location->id]))[$this->location->id]['state']);

        GbpBranchPageAgent::fake([[
            'title' => 'Panorama Çankaya Diş Kliniği', 'slug' => 'cankaya-dis-klinigi', 'meta_title' => 'Çankaya Diş Kliniği | Panorama', 'meta_description' => 'Çankaya şubemizde implant ve diğer tedaviler.',
            'focus_keyword' => 'çankaya diş kliniği', 'intro' => ['Çankaya şubemiz Atatürk Bulvarı üzerindedir. <b>Randevu</b> için 0312 555 55 55.', 'Şubemizde implant tedavisi yapılır.'],
            'services' => [['name' => 'İmplant', 'text' => 'Eksik dişler için implant tedavisi.', 'page_url' => 'https://panorama.test/implant/'], ['name' => 'Beyazlatma', 'text' => 'Ofis tipi beyazlatma.', 'page_url' => 'https://evil.test/x']],
            'access' => 'Kızılay metro durağına yürüme mesafesindedir.', 'faq' => [['question' => 'Otopark var mı?', 'answer' => 'Binanın önünde ücretli otopark vardır.']],
        ]]);
        $this->assertNotNull($pages->prepare($this->location));

        $row = GbpBranchPage::query()->sole();
        $content = (array) $row->content;
        $this->assertSame(GbpBranchPage::READY, $row->status);
        $this->assertSame(['Şubemizde implant tedavisi yapılır.'], $content['intro']);
        $this->assertSame(['https://panorama.test/implant/', ''], array_column($content['services'], 'page_url'));
        $this->assertSame('0312 555 55 55', $content['facts']['phone']);
        $this->assertSame('09:00–18:00', $content['facts']['hours']['Pazartesi']);
        $html = BranchPages::html($content);
        $this->assertStringContainsString('Atatürk Bulvarı 10', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertSame('ready', $pages->states($locations, $desk->snapshots([$this->location->id]))[$this->location->id]['state']);
        $schema = BranchPages::schema($this->location, $desk->snapshots([$this->location->id])[$this->location->id], 'https://panorama.test/cankaya-dis-klinigi/');
        $this->assertSame('Dentist', $schema['@type']);
        $this->assertSame('Monday', $schema['openingHoursSpecification'][0]['dayOfWeek']);

        Livewire::actingAs($this->admin)->test(BranchPagesPage::class)->call('toggle', $this->location->id)
            ->assertSee('Sayfa önizlemesi')->assertSee('Atatürk Bulvarı 10')->assertSet('form.slug', 'cankaya-dis-klinigi');

        $branchPage = Page::query()->create(['website_asset_id' => $this->site->id, 'url' => 'https://panorama.test/cankaya-subesi/', 'url_hash' => hash('sha256', '/cankaya-subesi/'),
            'path' => '/cankaya-subesi/', 'title' => 'Çankaya Şubesi', 'category' => 'lokasyon', 'language' => 'tr', 'is_indexable' => true, 'word_count' => 400]);
        $state = $pages->states($locations, $desk->snapshots([$this->location->id]))[$this->location->id];
        $this->assertSame('unlinked', $state['state']);
        $this->assertSame($branchPage->id, $state['page']->id);

        $pages->link($this->admin, $this->location, $branchPage);

        $this->assertSame('https://panorama.test/cankaya-subesi/?'.BranchPages::UTM, $this->live['websiteUri']);
        DB::table('gbp_location_snapshots')->update(['website_uri' => $this->live['websiteUri']]);
        $this->assertSame('linked', $pages->states($locations, $desk->snapshots([$this->location->id]))[$this->location->id]['state']);
    }

    public function test_review_list_drafts_and_sends_and_the_kit_has_googles_review_link(): void
    {
        $review = fn (string $id, string $stars, ?array $reply, string $created): int => DB::table('gbp_reviews')->insertGetId(['external_resource_id' => $this->resourceId, 'run_id' => 1,
            'location_name' => 'locations/22', 'review_id' => $id, 'reviewer' => json_encode(['displayName' => 'Ayşe '.$id]), 'star_rating' => $stars, 'comment' => 'Yorum '.$id,
            'create_time' => $created, 'review_reply' => $reply !== null ? json_encode($reply) : null, 'raw_payload' => '{}', 'collected_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $old = $review('r1', 'TWO', null, '2026-10-01 10:00:00');
        $review('r2', 'FIVE', null, '2026-10-05 20:00:00');
        $review('r3', 'FIVE', ['comment' => 'Teşekkürler'], '2026-09-20 10:00:00');

        $desk = app(ReviewDesk::class);
        $stats = $desk->stats([$this->location->id => $this->resourceId])[$this->location->id];
        $this->assertSame(['recent' => 3, 'average' => 4.0, 'unanswered' => 2, 'reply_rate' => 33, 'late' => 1], $stats);
        $list = $desk->unanswered([$this->location->id => $this->resourceId]);
        $this->assertSame([$old], array_slice(array_column($list, 'id'), 0, 1));
        $this->assertTrue($list[0]['late']);
        $this->assertSame('4 gün', $list[0]['waiting']);
        $this->assertSame([$old], array_column($desk->unanswered([$this->location->id => $this->resourceId], 'low'), 'id'));

        Livewire::actingAs($this->admin)->test(ReviewsPage::class)
            ->set('replies.r'.$old, 'Geri bildiriminiz için teşekkür ederiz, yaşadığınız sorunu çözmek isteriz.')
            ->call('send', $old)
            ->assertSet('message', 'Yanıt Google’a gönderiliyor.');

        $put = collect($this->writes())->first(fn (array $c): bool => $c[0] === 'PUT');
        $this->assertStringEndsWith('/reviews/r1/reply', $put[1]);
        $this->assertCount(1, $desk->unanswered([$this->location->id => $this->resourceId]));

        $kit = $desk->kit($this->location);
        $this->assertSame('https://search.google.com/local/writereview?placeid=ChIJtest123', $kit['link']);
        $this->assertStringContainsString($kit['link'], ReviewDesk::requestMessage('Panorama', $kit['link']));
        $this->actingAs($this->admin)->get(route('operator.gbp-review-card', ['assetId' => $this->location->id]))
            ->assertOk()->assertSee('Panorama Çankaya')->assertSee('Google’da', false);
    }

    public function test_review_grid_picks_fills_a_shared_reply_previews_and_publishes_in_bulk(): void
    {
        $review = fn (string $id, string $stars, string $comment, ?array $reply, string $created, string $name): int => DB::table('gbp_reviews')->insertGetId(['external_resource_id' => $this->resourceId,
            'run_id' => 1, 'location_name' => 'locations/22', 'review_id' => $id, 'reviewer' => json_encode(['displayName' => $name]), 'star_rating' => $stars, 'comment' => $comment,
            'create_time' => $created, 'review_reply' => $reply !== null ? json_encode($reply) : null, 'raw_payload' => '{}', 'collected_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $silent = $review('r1', 'FIVE', '', null, '2026-10-02 10:00:00', 'Ayşe Yılmaz');
        $review('r2', 'TWO', 'Çok beklettiler.', null, '2026-10-03 10:00:00', 'Mehmet K');
        $review('r3', 'FIVE', 'Harika', ['comment' => 'Teşekkürler'], '2026-10-04 10:00:00', 'Can');

        $desk = app(ReviewDesk::class);
        $all = $desk->reviews([$this->location->id => $this->resourceId], 'tumu');
        $this->assertSame(3, $all['total']);
        $this->assertSame(['Teşekkürler'], array_values(array_filter(array_column($all['rows'], 'reply'))));
        $this->assertSame(1, $desk->reviews([$this->location->id => $this->resourceId], 'yanitli')['total']);
        $this->assertSame('Teşekkür ederiz!', ReviewDesk::personalize('Teşekkür ederiz {ad}!', 'Bir Google kullanıcısı'));
        $this->assertSame('Teşekkür ederiz Ayşe!', ReviewDesk::personalize('Teşekkür ederiz {ad}!', 'Ayşe Yılmaz'));

        Livewire::actingAs($this->admin)->test(ReviewsPage::class)
            ->assertSee('Çok beklettiler.')
            ->call('setStatus', 'tumu')
            ->assertSee('Yanıtlandı')
            ->call('pick', 'silent')
            ->assertSet('selected', [$silent])
            ->set('bulkText', 'Teşekkür ederiz {ad}, yine bekleriz!')
            ->call('fillSelected')
            ->assertSet('replies.r'.$silent, 'Teşekkür ederiz Ayşe, yine bekleriz!')
            ->call('openPreview')
            ->assertSee('Yayımlamadan önce oku')
            ->call('publishSelected')
            ->assertSet('message', '1 yanıt Google’a gönderiliyor.')
            ->assertSet('selected', []);

        $put = collect($this->writes())->first(fn (array $c): bool => $c[0] === 'PUT');
        $this->assertStringEndsWith('/reviews/r1/reply', $put[1]);
        $this->assertSame('Teşekkür ederiz Ayşe, yine bekleriz!', $put[2]['comment']);
    }

    public function test_edited_replies_are_kept_and_download_as_a_pdf_for_the_brand(): void
    {
        $id = DB::table('gbp_reviews')->insertGetId(['external_resource_id' => $this->resourceId, 'run_id' => 1, 'location_name' => 'locations/22', 'review_id' => 'r9',
            'reviewer' => json_encode(['displayName' => 'Zeynep A']), 'star_rating' => 'FOUR', 'comment' => 'Güler yüzlü ekip.', 'create_time' => '2026-10-04 10:00:00',
            'review_reply' => null, 'raw_payload' => '{}', 'collected_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->admin)->get(route('operator.gbp-review-replies-pdf'))->assertNotFound();

        Livewire::actingAs($this->admin)->test(ReviewsPage::class)->set('replies.r'.$id, 'Güzel sözleriniz için teşekkür ederiz Zeynep Hanım.');
        $this->assertSame('Güzel sözleriniz için teşekkür ederiz Zeynep Hanım.', app(ReviewDesk::class)->unanswered([$this->location->id => $this->resourceId])[0]['draft'], 'the edit is kept');

        $response = $this->actingAs($this->admin)->get(route('operator.gbp-review-replies-pdf', ['marka' => $this->brand->id, 'yorumlar' => (string) $id]));
        $response->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('yorum-yanitlari-panorama-', (string) $response->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
    }

    public function test_the_profiles_asset_page_shows_the_desk_checks_and_what_was_sent(): void
    {
        app(ExternalWriteService::class)->requestProfileFields($this->admin, $this->location, ['description' => $this->description()], 'Açıklama');

        Livewire::actingAs($this->admin)->test(OverviewPage::class, ['assetId' => (string) $this->location->id])
            ->assertSee('İşletme profilleri ·')
            ->assertSee('Şube sayfası')
            ->assertSee('MoxDOP’un bu profilde yaptıkları')
            ->assertSee('Açıklama')
            ->assertSee('Uygulandı');
    }

    public function test_monthly_report_compares_the_last_full_month_with_the_one_before(): void
    {
        $row = fn (string $date, string $metric, int $value): bool => DB::table('gbp_performance_daily')->insert(['external_resource_id' => $this->resourceId, 'run_id' => 1, 'location_name' => 'locations/22',
            'reporting_date' => $date, 'metric' => $metric, 'value' => $value, 'collected_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        foreach (range(1, 30) as $day) {
            $row(sprintf('2026-09-%02d', $day), 'BUSINESS_IMPRESSIONS_MOBILE_MAPS', 10);
            $row(sprintf('2026-09-%02d', $day), 'CALL_CLICKS', 1);
        }
        foreach (range(1, 31) as $day) {
            $row(sprintf('2026-08-%02d', $day), 'BUSINESS_IMPRESSIONS_MOBILE_MAPS', 8);
        }
        $row('2026-08-01', 'CALL_CLICKS', 20);

        $this->assertSame('2026-09', GbpPerformance::reportMonth());
        $report = app(GbpPerformance::class)->report([$this->resourceId])[$this->resourceId];

        $this->assertSame(300, $report['current']['views']);
        $this->assertSame(30, $report['current']['calls']);
        $this->assertSame(248, $report['previous']['views']);
        $this->assertSame(21, $report['change']['views']);
        $this->assertSame(50, $report['change']['calls']);
        $this->assertTrue($report['complete']);
        $this->assertNull($report['last_year']);
    }

    public function test_every_tab_renders_for_an_admin(): void
    {
        $this->actingAs($this->admin);
        foreach (['operator.gbp-desk', 'operator.gbp-posts', 'operator.gbp-branch-pages', 'operator.gbp-profile-fields', 'operator.gbp-photos'] as $route) {
            $this->get(route($route))->assertOk()->assertSee('İşletme profilleri')->assertSee('Panorama Çankaya');
        }
        $this->get(route('operator.gbp-reviews'))->assertOk()->assertSee('Yanıt bekleyen yorum yok.');
        $this->get(route('operator.gbp-profile-fields', ['bolum' => 'saatler']))->assertOk()->assertSee('Cumhuriyet Bayramı');
        $this->get(route('operator.gbp-reviews', ['bolum' => 'iste']))->assertOk()->assertSee('writereview?placeid=ChIJtest123', false);
        $this->get(route('operator.gbp-desk', ['isletme' => $this->location->id]))->assertOk();

        Livewire::test(PhotosPage::class)->call('toggle', $this->location->id)->assertSee('Sitedeki fotoğraflar')->assertSee('Şube fotoğrafı yükle');

        Livewire::test(ProfileFieldsPage::class)
            ->call('startEdit', $this->location->id)
            ->assertSet('editText', 'Kısa açıklama')
            ->set('editText', $this->description())
            ->call('sendDescription')
            ->assertSet('message', 'Açıklama Google’a gönderiliyor.');
        $this->assertSame($this->description(), $this->live['profile']['description']);
    }

    public function test_branch_states_say_what_each_profile_needs_and_existing_pages_can_be_chosen(): void
    {
        $desk = app(GbpDesk::class);
        $pages = app(BranchPages::class);
        $state = fn (DigitalAsset $l): array => $pages->states($desk->locations(), $desk->snapshots($desk->locations()->pluck('id')->all()))[$l->id];
        $home = Page::query()->create(['website_asset_id' => $this->site->id, 'url' => 'https://panorama.test/', 'url_hash' => hash('sha256', '/'), 'path' => '/',
            'title' => 'Panorama', 'category' => 'anasayfa', 'language' => 'tr', 'is_indexable' => true, 'word_count' => 400]);

        $single = $state($this->location);
        $this->assertSame('single', $single['state']);
        $this->assertSame($home->id, $single['page']->id);
        $this->assertTrue($single['link']['on_site']);
        $this->assertTrue($single['link']['home']);

        $second = $this->secondBranch();
        $this->assertSame('missing', $state($this->location)['state']);
        $this->assertSame('no_data', $state($second)['state']);

        $contact = Page::query()->create(['website_asset_id' => $this->site->id, 'url' => 'https://panorama.test/iletisim/cankaya/', 'url_hash' => hash('sha256', '/iletisim/cankaya/'),
            'path' => '/iletisim/cankaya/', 'title' => 'Çankaya İletişim', 'category' => 'iletisim', 'language' => 'tr', 'is_indexable' => true, 'word_count' => 200]);
        $this->assertSame([$contact->id], $pages->searchPages($this->location, '')->pluck('id')->all());
        $this->assertSame([$contact->id], $pages->searchPages($this->location, 'Çankaya')->pluck('id')->all());

        $pages->choose($this->admin, $this->location, $contact);
        $chosen = $state($this->location);
        $this->assertSame('unlinked', $chosen['state']);
        $this->assertTrue($chosen['chosen']);
        $this->assertSame(3, $chosen['step']);

        $pages->unchoose($this->admin, $this->location);
        $this->assertSame('missing', $state($this->location)['state']);
        $this->assertSame(0, GbpBranchPage::query()->count());

        $other = DigitalAsset::factory()->create(['type' => 'website', 'domain' => 'baska.test']);
        $foreign = Page::query()->create(['website_asset_id' => $other->id, 'url' => 'https://baska.test/x/', 'url_hash' => hash('sha256', 'x'), 'path' => '/x/', 'title' => 'X', 'is_indexable' => true, 'word_count' => 10]);
        $this->expectException(ValidationException::class);
        $pages->choose($this->admin, $this->location, $foreign);
    }

    public function test_hub_page_lists_every_branch_from_the_profiles(): void
    {
        $pages = app(BranchPages::class);
        $this->secondBranch();
        $this->secondBranch();
        $hub = $pages->hub((int) $this->brand->id);
        $this->assertTrue($hub['needed']);
        $this->assertNull($hub['page']);
        $this->assertSame(3, $hub['count']);
        $this->assertSame(1, $hub['ready']);

        $content = $pages->hubContent((int) $this->brand->id);
        $this->assertSame('Panorama Şubeleri', $content['title']);
        $this->assertSame(1, $content['branches']);
        $this->assertStringContainsString('<h2>Panorama Çankaya</h2>', $content['html']);
        $this->assertStringContainsString('Atatürk Bulvarı 10', $content['html']);
        $this->assertStringContainsString('Yol tarifi al', $content['html']);

        $this->expectException(ValidationException::class);
        $pages->sendHub($this->admin, (int) $this->brand->id);
    }

    public function test_branch_screen_filters_by_work_and_offers_the_next_step(): void
    {
        $this->secondBranch();
        Livewire::actingAs($this->admin)->test(BranchPagesPage::class)
            ->assertSee('Hazırlanacak')->assertSee('Profil verisi bekleyen')->assertSee('1 sayfayı hazırla')->assertSee('Sayfayı hazırla')
            ->call('setFilter', 'veri')
            ->assertSee('Panorama Kızılay')->assertDontSee('Sitedeki sayfayı seç')
            ->call('setFilter', 'hazirla')
            ->assertSee('Panorama Çankaya')->assertDontSee('Panorama Kızılay')
            ->call('startPicking', $this->location->id)
            ->assertSee('Sayfa başlığı ya da adresinde ara');
    }
}
