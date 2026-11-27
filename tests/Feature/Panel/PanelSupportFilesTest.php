<?php

namespace Tests\Feature\Panel;

use App\Livewire\Panel\SupportPage;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Reminder;
use App\Models\User;
use App\Panel\Jobs\PushPanelDocumentToDrive;
use App\Panel\Models\PanelBrandSetting;
use App\Panel\Models\PanelDocument;
use App\Panel\Models\PanelMeeting;
use App\Panel\Models\PanelTicket;
use App\Panel\Models\PanelTicketMessage;
use App\Panel\Services\PanelDrive;
use App\Panel\Services\PanelSupport;
use App\Panel\Support\PanelContext;
use App\Panel\Support\RichText;
use App\Services\Integrations\Google\GoogleWorkspace;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/** Destek: formatted messages stay clean, files stay inside their brand and move to the brand's Drive folder, meetings are asked from the panel. */
class PanelSupportFilesTest extends PanelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake((string) config('panel.files_disk'));
    }

    private function drive(): void
    {
        config(['moxdop.google.client_id' => 'test-client-id', 'moxdop.google.client_secret' => 'test-client-secret']);
        $integration = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE, 'config' => ['granted_scopes' => [GoogleWorkspace::DRIVE]]]);
        CoreIntegrationCredential::factory()->provider()->create(['integration_id' => $integration->id]);
        CoreIntegrationCredential::factory()->authorization()->create(['integration_id' => $integration->id, 'expires_at' => now()->addHour(),
            'encrypted_payload' => ['access_token' => 'workspace-token', 'refresh_token' => 'r', 'scope' => GoogleWorkspace::DRIVE]]);
    }

    public function test_formatting_survives_and_everything_else_is_dropped(): void
    {
        $clean = RichText::clean('<p onclick="x()">Merhaba <b>kalın</b> <i>eğik</i></p><script>alert(1)</script><ul><li style="color:red">bir</li></ul><a href="javascript:alert(1)">kötü</a><a href="https://moximu.com/a" target="_blank">iyi</a><img src=x onerror=alert(1)>');
        $this->assertStringContainsString('<strong>kalın</strong>', $clean);
        $this->assertStringContainsString('<em>eğik</em>', $clean);
        $this->assertStringContainsString('<li>bir</li>', $clean);
        $this->assertStringContainsString('href="https://moximu.com/a"', $clean);
        foreach (['script', 'alert', 'onclick', 'style', 'javascript', '<img', 'onerror'] as $bad) {
            $this->assertStringNotContainsString($bad, $clean);
        }
        $this->assertSame('<p>iki<br>satır</p>', str_replace("\n", '', RichText::clean("iki\nsatır")));
        $this->assertSame('', RichText::clean('<p><br></p>'));
        $this->assertSame('Merhaba kalın', RichText::plain('<p>Merhaba <strong>kalın</strong></p>'));
    }

    public function test_a_request_carries_formatted_text_and_several_files_and_they_stand_on_the_shelf(): void
    {
        Queue::fake();
        $this->enter($this->managerA, $this->brandA);
        Livewire::test(SupportPage::class)->set('adding', true)
            ->set('form.subject', 'Yeni görseller')->set('form.body', '<p>Ekte <strong>üç</strong> görsel var.</p><script>x</script>')
            ->set('uploads', [UploadedFile::fake()->image('bir.jpg'), UploadedFile::fake()->create('fiyat.pdf', 40, 'application/pdf')])
            ->call('open')->assertHasNoErrors()->assertSee('bir.jpg')->assertSee('fiyat.pdf')->assertSeeHtml('<strong>üç</strong>')->assertDontSeeHtml('<script>x');
        $message = PanelTicketMessage::query()->firstOrFail();
        $this->assertSame('Ekte üç görsel var.', $message->body);
        $this->assertSame(2, PanelDocument::query()->where('ticket_message_id', $message->id)->count());
        // No Drive connection: files stay on the server and nothing is queued.
        Queue::assertNothingPushed();

        Livewire::test(SupportPage::class, ['tab' => 'dosyalar'])->assertSee('bir.jpg')->assertSee('Yeni görseller')
            ->set('uploads', [UploadedFile::fake()->create('logo.svg', 4, 'image/svg+xml')])->call('shelve')->assertHasNoErrors()->assertSee('logo.svg');
        $this->assertSame(3, PanelDocument::query()->count());
        Livewire::test(SupportPage::class, ['tab' => 'dosyalar'])->set('uploads', [UploadedFile::fake()->create('zarar.exe', 4)])->call('shelve')->assertHasErrors('uploads.0');

        // The file opens for its own brand only.
        $doc = PanelDocument::query()->where('name', 'fiyat.pdf')->firstOrFail();
        $this->asPanel($this->managerA)->get($this->url('/destek/belge/'.$doc->id))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->asPanel($this->managerB)->get($this->url('/destek/belge/'.$doc->id))->assertNotFound();
        $this->enter($this->managerB, $this->brandB);
        Livewire::test(SupportPage::class, ['tab' => 'dosyalar'])->assertDontSee('fiyat.pdf');
    }

    public function test_a_file_moves_to_the_brands_drive_folder_and_leaves_the_server(): void
    {
        $this->drive();
        Queue::fake();
        $this->enter($this->managerA, $this->brandA);
        Livewire::test(SupportPage::class, ['tab' => 'dosyalar'])->set('uploads', [UploadedFile::fake()->createWithContent('not.txt', 'merhaba')])->call('shelve')->assertHasNoErrors();
        Queue::assertPushed(PushPanelDocumentToDrive::class, 1);
        $doc = PanelDocument::query()->firstOrFail();
        $path = (string) $doc->path;
        Storage::disk((string) config('panel.files_disk'))->assertExists($path);

        $made = [];
        Http::fake(function (Request $request) use (&$made) {
            if (str_contains($request->url(), '/upload/drive/v3/files')) {
                return Http::response(['id' => 'file-1', 'size' => '7']);
            }
            if ($request->method() === 'POST') {
                $made[] = $request['name'];

                return Http::response(['id' => 'folder-'.count($made)]);
            }
            if (str_contains($request->url(), 'alt=media')) {
                return Http::response('merhaba');
            }

            return Http::response(['id' => 'x', 'trashed' => false]);
        });
        app(PanelContext::class)->leave();
        (new PushPanelDocumentToDrive($doc->id))->handle(app(PanelDrive::class), app(PanelContext::class));

        $this->assertSame([PanelDrive::ROOT, 'Akasya'], $made);
        $doc = app(PanelContext::class)->system(fn () => $doc->fresh());
        $this->assertSame('file-1', $doc->drive_file_id);
        $this->assertNull($doc->path);
        Storage::disk((string) config('panel.files_disk'))->assertMissing($path);
        $this->assertSame('folder-2', PanelBrandSetting::forBrand($this->brandA->id)->drive_folder_id);
        // It still opens from the panel, now read from Drive.
        $this->asPanel($this->managerA)->get($this->url('/destek/belge/'.$doc->id))->assertOk()->assertSee('merhaba');
    }

    public function test_a_failed_copy_keeps_the_file_on_the_server(): void
    {
        $this->drive();
        Queue::fake();
        $kept = app(PanelSupport::class)->keep($this->brandA->id, [UploadedFile::fake()->createWithContent('not.txt', 'merhaba')], $this->managerA->id, null);
        Http::fake(fn () => Http::response(['error' => ['message' => 'quota']], 403));
        $this->assertFalse(app(PanelDrive::class)->push($kept[0]));
        $doc = app(PanelContext::class)->system(fn () => $kept[0]->fresh());
        $this->assertNotNull($doc->path);
        $this->assertNotNull($doc->drive_error);
        Storage::disk((string) config('panel.files_disk'))->assertExists((string) $doc->path);
    }

    public function test_a_meeting_is_asked_from_the_panel_and_lands_on_the_operators_list(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $this->enter($this->managerA, $this->brandA);
        Livewire::test(SupportPage::class, ['tab' => 'toplantilar'])->set('asking', true)
            ->set('meeting.topic', 'Aylık değerlendirme')->set('meeting.preferred', 'Salı öğleden sonra')->call('askMeeting')->assertHasNoErrors()->assertSee('Aylık değerlendirme');
        $meeting = PanelMeeting::query()->firstOrFail();
        $this->assertSame(PanelMeeting::REQUESTED, $meeting->status);
        $this->assertTrue(Reminder::query()->where('user_id', $admin->id)->where('title', 'like', '%Akasya%Aylık değerlendirme%')->exists());

        $this->enter($this->managerB, $this->brandB);
        Livewire::test(SupportPage::class, ['tab' => 'toplantilar'])->assertDontSee('Aylık değerlendirme')->call('cancelMeeting', $meeting->id);
        $this->assertSame(PanelMeeting::REQUESTED, app(PanelContext::class)->system(fn () => $meeting->fresh()->status), 'another brand cannot cancel it');
        $this->enter($this->managerA, $this->brandA);
        Livewire::test(SupportPage::class, ['tab' => 'toplantilar'])->call('cancelMeeting', $meeting->id);
        $this->assertSame(PanelMeeting::CANCELLED, $meeting->fresh()->status);
    }

    public function test_a_request_taken_on_the_phone_shows_in_the_brands_panel(): void
    {
        $ticket = app(PanelSupport::class)->openByOperator($this->brandA->id, User::factory()->create()->id, 'Telefon numarası değişsin', 'wish', 'Telefonda istendi: sitedeki numara güncellensin.', 'phone');
        $this->assertSame('phone', app(PanelContext::class)->system(fn () => $ticket->fresh()->origin));
        $this->enter($this->managerA, $this->brandA);
        Livewire::test(SupportPage::class)->assertSee('Telefon numarası değişsin');
        $this->enter($this->managerB, $this->brandB);
        Livewire::test(SupportPage::class)->assertDontSee('Telefon numarası değişsin');
        $this->assertSame(1, app(PanelContext::class)->system(fn () => PanelTicket::query()->count()));
    }
}
