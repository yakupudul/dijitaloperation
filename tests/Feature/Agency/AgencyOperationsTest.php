<?php

namespace Tests\Feature\Agency;

use App\Enums\CustomerStatus;
use App\Livewire\Operator\Agency\AgencyPage;
use App\Models\Brand;
use App\Models\ContentCalendarItem;
use App\Models\Customer;
use App\Models\CustomerInteraction;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Agency\AgencyOperations;
use App\Services\CommandCenter\CommandCenter;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** Ajans işletmesi: profitability, commitments, invoices and follow-ups, and what reaches the command center. */
final class AgencyOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_profitability_commitments_invoices_and_follow_ups(): void
    {
        $this->travelTo('2026-10-25 10:00:00');
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $customer = Customer::factory()->create(['name' => 'Atlas Sağlık', 'status' => CustomerStatus::Active, 'monthly_fee' => 10000]);
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        if (! DB::table('agency_settings')->exists()) {
            DB::table('agency_settings')->insert(['created_at' => now(), 'updated_at' => now()]);
        }

        Livewire::actingAs($admin)->test(AgencyPage::class)
            ->set('hourlyCost', '750')->call('saveHourlyCost')
            ->set('time.customer_id', $customer->id)->set('time.minutes', 600)->set('time.worked_on', '2026-10-10')->call('addTime')
            ->set('commitment.customer_id', $customer->id)->set('commitment.title', 'Aylık blog yazısı')->set('commitment.monthly_quantity', 2)->set('commitment.counts_from', 'blog')->call('addCommitment')
            ->set('contact.customer_id', $customer->id)->set('contact.summary', 'Kampanya bütçesini konuştuk.')->set('contact.next_action', 'Teklifi gönder')->set('contact.next_action_at', '2026-10-24T10:00')->call('addContact')
            ->call('draftInvoices')->assertHasNoErrors();

        $profit = app(AgencyOperations::class)->profitability('2026-10')[0];
        $this->assertSame(10.0, $profit['hours']);
        $this->assertSame(2500.0, $profit['margin'], '10 000 − 10 h × 750');
        $this->assertSame('thin', $profit['state'], 'a 25% margin is flagged');

        ContentCalendarItem::query()->create(['brand_id' => $brand->id, 'channel' => 'blog', 'title' => 'Blog 1', 'scheduled_for' => now(), 'status' => 'published', 'published_at' => now()]);
        $commitment = app(AgencyOperations::class)->commitments('2026-10')[0];
        $this->assertSame(1, $commitment['auto'], 'counted from the content calendar');
        $this->assertSame('behind', $commitment['state'], '1 of 2 with the month nearly over');

        $invoice = Invoice::query()->sole();
        $this->assertSame('draft', $invoice->status);
        $invoice->update(['status' => 'issued', 'due_on' => '2026-10-15']);

        $keys = app(CommandCenter::class)->items()->pluck('key')->all();
        $followUp = CustomerInteraction::query()->sole();
        $this->assertContains('followup:'.$followUp->id, $keys);
        $this->assertContains('invoice:'.$invoice->id, $keys);
        $this->assertContains('commitment:'.$commitment['id'], $keys);

        app(CommandCenter::class)->act(['invoice:'.$invoice->id, 'followup:'.$followUp->id], 'done', $admin);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertNotNull($followUp->fresh()->next_action_done_at);
        foreach (['profit', 'invoices', 'commitments', 'time', 'contacts'] as $tab) {
            $this->actingAs($admin)->get(route('operator.agency', ['tab' => $tab, 'month' => '2026-10']))->assertOk()->assertSee('Ajans işletmesi');
        }
    }
}
