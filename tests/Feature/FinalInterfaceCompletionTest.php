<?php

namespace Tests\Feature;

use App\Livewire\Demo\Files\FilesIndex;
use App\Livewire\Demo\Instagram\OverviewPage as InstagramOverviewPage;
use App\Livewire\Demo\Integrations\SiteConnectorShow;
use App\Livewire\Demo\ProfilePage;
use App\Livewire\Demo\Settings\AiControlPlanePage;
use App\Livewire\Demo\SettingsPage;
use App\Models\DigitalAsset;
use App\Models\OperatorFile;
use App\Models\User;
use App\Support\Demo\DemoMenu;
use App\Support\Demo\DemoState;
use App\Support\Demo\SiteConnectorFixtures;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

class FinalInterfaceCompletionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        $this->admin = User::factory()->create(['locale' => 'en']);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);

        DemoState::reset();
    }

    public function test_files_upload_and_authenticated_download(): void
    {
        Storage::fake('local');

        Livewire::test(FilesIndex::class)
            ->set('upload', UploadedFile::fake()->create('brief.pdf', 120, 'application/pdf'))
            ->set('uploadScope', 'personal')
            ->call('uploadFile')
            ->assertHasNoErrors();

        $file = OperatorFile::query()->first();
        $this->assertNotNull($file);
        $this->assertSame('brief.pdf', $file->original_name);
        $this->assertSame($this->admin->id, $file->user_id);

        $this->get(route('operator.files.download', $file))
            ->assertOk()
            ->assertHeader('content-disposition');
    }

    public function test_files_download_requires_auth_and_authorization(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('operator-files/secret.txt', 'private-bytes');

        $file = OperatorFile::factory()->create([
            'user_id' => $this->admin->id,
            'disk' => 'local',
            'path' => 'operator-files/secret.txt',
            'original_name' => 'secret.txt',
            'mime' => 'text/plain',
        ]);

        auth()->logout();
        $this->get(route('operator.files.download', $file))
            ->assertRedirect('/app/login');

        $other = User::factory()->create();
        $other->assignRole(Roles::TEAM_MEMBER);
        $this->actingAs($other);

        $this->get(route('operator.files.download', $file))
            ->assertForbidden();
    }

    public function test_files_reject_php_and_exe_uploads(): void
    {
        Storage::fake('local');

        Livewire::test(FilesIndex::class)
            ->set('upload', UploadedFile::fake()->create('shell.php', 20, 'application/x-php'))
            ->call('uploadFile')
            ->assertHasErrors(['upload']);

        Livewire::test(FilesIndex::class)
            ->set('upload', UploadedFile::fake()->create('payload.exe', 20, 'application/x-msdownload'))
            ->call('uploadFile')
            ->assertHasErrors(['upload']);

        $this->assertSame(0, OperatorFile::query()->count());
    }

    public function test_profile_locale_save_persists_and_sets_app_locale(): void
    {
        Livewire::test(ProfilePage::class)
            ->set('locale', 'tr')
            ->set('timezone', 'Europe/Istanbul')
            ->call('save')
            ->assertHasNoErrors();

        $this->admin->refresh();
        $this->assertSame('tr', $this->admin->locale);
        $this->assertSame('Europe/Istanbul', $this->admin->timezone);
        $this->assertSame('tr', app()->getLocale());
    }

    public function test_site_connector_download_is_labeled_demo(): void
    {
        $response = $this->get(route('operator.integrations.site-connector.download', ['connector' => 'wordpress']));

        $response->assertOk();
        $disposition = (string) $response->headers->get('content-disposition');
        $this->assertStringContainsString('moxdop-wordpress-connector-0.1.0-demo.zip', $disposition);
        $this->assertStringContainsString('DEMO CONNECTOR PACKAGE', (string) $response->headers->get('X-MoxDOP-Package'));

        Livewire::test(SiteConnectorShow::class, ['connector' => 'wordpress'])
            ->assertSee('DEMO CONNECTOR PACKAGE')
            ->assertSee('not production', false)
            ->call('setTab', 'releases')
            ->assertSee('v0.1.0 Demo');

        $zipPath = SiteConnectorFixtures::ensureDemoZip();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath) === true);
        $readme = $zip->getFromName('README.txt');
        $zip->close();
        $this->assertIsString($readme);
        $this->assertStringContainsString('DEMO CONNECTOR PACKAGE — NOT PRODUCTION INSTALLABLE', $readme);
    }

    public function test_instagram_workspace_returns_ok_with_useful_tabs(): void
    {
        $this->get(route('operator.instagram'))->assertNotFound();

        $asset = DigitalAsset::factory()->create([
            'type' => 'instagram',
            'name' => 'Northwind Instagram',
        ]);

        $this->get(route('operator.instagram', ['assetId' => $asset->id]))
            ->assertOk()
            ->assertSee('Instagram')
            ->assertSee('Northwind Instagram')
            ->assertSee(__('operator.commercial.outside_scope'))
            ->assertDontSee('@atlasdentalankara')
            ->assertDontSee('Website URL mismatch');

        Livewire::test(InstagramOverviewPage::class, ['assetId' => (string) $asset->id])
            ->call('setTab', 'profile')
            ->assertSee('Northwind Instagram')
            ->assertDontSee('atlasdentalankara')
            ->call('setTab', 'operations')
            ->assertDontSee('Bio website path');
    }

    public function test_demo_menu_includes_files_item(): void
    {
        $items = collect(DemoMenu::groups())->flatMap(fn (array $group): array => $group['items']);
        $files = $items->firstWhere('route', 'operator.files');

        $this->assertNotNull($files);
        $this->assertSame('operator.files', $files['route']);
        $this->assertSame(__('operator.nav.files'), $files['label']);

        $this->get(route('operator.files'))
            ->assertOk()
            ->assertSee(__('operator.files.title'));
    }

    public function test_settings_blade_has_no_system_panel_links(): void
    {
        $html = Livewire::test(SettingsPage::class, ['section' => 'ai'])
            ->html();

        $this->assertStringNotContainsString('href="/system', $html);
        $this->assertStringNotContainsString("href='/system", $html);
        $this->assertStringContainsString('/app/settings/ai/control-plane', $html);

        $advanced = Livewire::test(SettingsPage::class, ['section' => 'advanced'])->html();
        $this->assertStringNotContainsString('Open system panel', $advanced);
        $this->assertStringNotContainsString('href="/system', $advanced);
        $this->assertStringContainsString(__('operator.nav.dashboard'), $advanced);
    }

    public function test_ai_control_plane_lists_registered_routes(): void
    {
        $this->get(route('operator.settings.ai.control-plane'))
            ->assertOk()
            ->assertSee('AI Control Plane')
            ->assertDontSee('href="/system', false);

        Livewire::test(AiControlPlanePage::class)
            ->assertOk()
            ->assertSee('website.ai_guidance')
            ->assertSee('google_ads.ai_guidance')
            ->assertSee('meta_ads.ai_guidance');
    }

    public function test_profile_and_site_connectors_routes_are_reachable(): void
    {
        $this->get(route('operator.profile'))->assertOk()->assertSee(__('operator.profile.title'));
        $this->get(route('operator.integrations.site-connectors'))
            ->assertOk()
            ->assertSee('WordPress')
            ->assertSee(__('operator.site_connectors.title'));
        $this->get(route('operator.integrations'))
            ->assertOk()
            ->assertSee(__('operator.site_connectors.title'));
    }
}
