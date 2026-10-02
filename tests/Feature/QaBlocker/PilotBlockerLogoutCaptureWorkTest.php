<?php

namespace Tests\Feature\QaBlocker;

use App\Livewire\Demo\ProfilePage;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PilotBlockerLogoutCaptureWorkTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $customer;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['locale' => 'en']);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);

        $this->customer = Customer::factory()->create(['name' => 'Pilot Customer A']);
        $this->brand = Brand::factory()->create([
            'customer_id' => $this->customer->id,
            'name' => 'Pilot Brand A',
        ]);
    }

    public function test_profile_logout_form_is_not_nested_inside_profile_save_form(): void
    {
        $html = $this->get(route('operator.profile'))
            ->assertOk()
            ->getContent();

        $document = new DOMDocument;
        @$document->loadHTML($html);
        $xpath = new DOMXPath($document);
        $logoutForms = $xpath->query('//form[contains(@action, "/logout")]');

        $this->assertNotFalse($logoutForms);
        $this->assertGreaterThan(0, $logoutForms->length, 'Visible POST /logout form must exist on Profile');

        foreach ($logoutForms as $form) {
            $parent = $form->parentNode;
            while ($parent) {
                if ($parent instanceof DOMElement && strtolower($parent->tagName) === 'form') {
                    $this->fail('Sign out form must not be nested inside another form');
                }
                $parent = $parent->parentNode;
            }
        }

        Livewire::test(ProfilePage::class)
            ->assertSee(__('operator.auth.logout'))
            ->assertSee(__('operator.actions.save'));
    }

    public function test_canonical_post_logout_invalidates_session(): void
    {
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->get('/')->assertRedirect('/login');
    }
}
