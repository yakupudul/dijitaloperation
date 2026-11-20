<?php

namespace Tests\Feature\Panel;

use App\Models\User;
use App\Panel\Models\PanelBrandSetting;
use App\Panel\Models\PanelEvent;
use App\Panel\Models\PanelFile;
use App\Panel\Models\PanelPerson;
use App\Panel\Models\PanelReminder;
use App\Panel\Models\PanelRequest;
use App\Panel\Support\PanelContext;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use LogicException;

/** The brand lock: no screen, link or model read of one brand shows another brand's people. */
class PanelBrandLockTest extends PanelTestCase
{
    public function test_models_return_nothing_without_a_brand(): void
    {
        app(PanelContext::class)->leave();
        foreach ([PanelPerson::class, PanelRequest::class, PanelEvent::class, PanelReminder::class, PanelFile::class] as $model) {
            $this->assertSame(0, $model::query()->count(), $model.' must fail closed');
        }
        $this->assertNull(PanelPerson::query()->find($this->personA->id));
        $this->assertSame(2, app(PanelContext::class)->system(fn (): int => PanelPerson::query()->count()));
    }

    public function test_models_are_limited_to_the_entered_brand(): void
    {
        $this->enter($this->managerA, $this->brandA);
        $this->assertSame(['Ayşe Akasya'], PanelPerson::query()->pluck('name')->all());
        $this->assertNull(PanelPerson::query()->find($this->personB->id));
        $this->assertSame(0, PanelEvent::query()->where('person_id', $this->personB->id)->count());
        // A raw or-where cannot widen the scope past the brand.
        $this->assertSame(1, PanelPerson::query()->where('id', $this->personA->id)->orWhere('id', $this->personB->id)->count());
    }

    public function test_a_row_cannot_be_written_without_a_brand_or_moved_to_another(): void
    {
        app(PanelContext::class)->leave();
        try {
            (new PanelPerson)->fill(['name' => 'Kimsesiz'])->save();
            $this->fail('A person without a brand was saved.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }
        $this->enter($this->managerA, $this->brandA);
        $person = PanelPerson::query()->firstOrFail();
        $person->brand_id = $this->brandB->id;
        $this->expectException(LogicException::class);
        $person->save();
    }

    public function test_a_new_row_takes_the_entered_brand_whatever_it_was_told(): void
    {
        $this->enter($this->managerA, $this->brandA);
        $person = new PanelPerson;
        $person->brand_id = $this->brandB->id;
        $person->fill(['name' => 'Yanlış Marka', 'phone' => '0555 000 00 01'])->save();
        $this->assertSame($this->brandA->id, (int) $person->fresh()->brand_id);
    }

    public function test_screens_show_only_the_own_brand(): void
    {
        $this->asPanel($this->managerA);
        $this->get($this->url('/'))->assertOk()->assertSee('Ayşe Akasya')->assertDontSee('Bora Begonya')->assertSee('Akasya');
        $this->get($this->url('/adaylar'))->assertOk()->assertSee('Ayşe Akasya')->assertDontSee('Bora Begonya')->assertDontSee('0533 444 55 66');
        $this->get($this->url('/adaylar?gorunum=board'))->assertOk()->assertSee('Ayşe Akasya')->assertDontSee('Bora Begonya');
        $this->get($this->url('/adaylar/'.$this->personA->id))->assertOk()->assertSee('0532 111 22 33');
        $this->get($this->url('/adaylar/'.$this->personB->id))->assertNotFound();
        $this->get($this->url('/kullanicilar'))->assertOk()->assertSee('a@example.test')->assertDontSee('b@example.test');
    }

    public function test_a_file_of_another_brand_is_not_served(): void
    {
        $file = app(PanelContext::class)->system(function (): PanelFile {
            $file = new PanelFile;
            $file->brand_id = $this->brandB->id;
            $file->fill(['person_id' => $this->personB->id, 'name' => 'rapor.pdf', 'path' => 'panel/x/rapor.pdf'])->save();

            return $file;
        });
        $this->asPanel($this->managerA)->get($this->url('/dosya/'.$file->id))->assertNotFound();
    }

    public function test_the_panel_host_serves_no_operator_screen_and_the_operator_host_no_panel_screen(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole(Roles::ADMIN);

        // An operator session means nothing on the panel host; operator paths do not exist there.
        $this->actingAs($admin)->get($this->url('/brands'))->assertNotFound();
        $this->actingAs($admin)->get($this->url('/settings/users'))->assertNotFound();
        $this->actingAs($admin)->get($this->url('/'))->assertRedirect($this->url('/giris'));
        auth()->forgetGuards();
        $this->get($this->url('/login'))->assertNotFound();

        // The panel paths do not exist on the operator host, and a panel session opens nothing there.
        foreach (['/adaylar', '/giris', '/adaylar/'.$this->personA->id, '/kullanicilar'] as $path) {
            $response = $this->get('http://localhost'.$path);
            $this->assertContains($response->status(), [302, 404], $path);
            $response->assertDontSee('Ayşe Akasya');
        }
        auth()->forgetGuards();
        $this->assertContains($this->asPanel($this->managerA)->get('http://localhost/brands')->status(), [302, 403]);
    }

    public function test_a_guest_is_sent_to_the_panel_sign_in(): void
    {
        $this->get($this->url('/adaylar'))->assertRedirect($this->url('/giris'));
        $this->get($this->url('/giris'))->assertOk()->assertSee('Giriş yapın');
    }

    public function test_sign_in_opens_the_own_brand_and_a_closed_brand_opens_nothing(): void
    {
        $this->post($this->url('/giris'), ['email' => 'a@example.test', 'password' => 'secret-pass-1'])->assertRedirect(rtrim($this->url('/'), '/'));
        $this->get($this->url('/adaylar'))->assertOk()->assertSee('Ayşe Akasya');
        $this->post($this->url('/cikis'))->assertRedirect($this->url('/giris'));

        PanelBrandSetting::forBrand($this->brandA->id)->update(['is_enabled' => false]);
        $this->post($this->url('/giris'), ['email' => 'a@example.test', 'password' => 'secret-pass-1'])->assertSessionHasErrors('email');
        $this->post($this->url('/giris'), ['email' => 'a@example.test', 'password' => 'wrong'])->assertSessionHasErrors('email');
    }
}
