<?php

namespace App\Livewire\Operator\Agency;

use App\Enums\CustomerStatus;
use App\Models\ContentCalendarItem;
use App\Models\Customer;
use App\Models\CustomerInteraction;
use App\Models\Invoice;
use App\Models\ServiceCommitment;
use App\Models\TimeEntry;
use App\Services\Agency\AgencyOperations;
use App\Support\Demo\DemoState;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Ajans işletmesi: profitability, invoices, monthly commitments, time spent and customer contacts in one place.
 */
#[Layout('operator.layouts.app')]
#[Title('Ajans işletmesi')]
final class AgencyPage extends Component
{
    public const array TABS = ['profit' => 'Kârlılık', 'invoices' => 'Tahsilat', 'commitments' => 'Taahhütler', 'time' => 'Zaman', 'contacts' => 'İletişim'];

    #[Url]
    public string $tab = 'profit';

    #[Url]
    public string $month = '';

    /** @var array<string, mixed> */
    public array $time = ['customer_id' => null, 'worked_on' => '', 'minutes' => 60, 'category' => 'seo', 'note' => ''];

    /** @var array<string, mixed> */
    public array $commitment = ['customer_id' => null, 'title' => '', 'monthly_quantity' => 1, 'counts_from' => ''];

    /** @var array<string, mixed> */
    public array $invoice = ['customer_id' => null, 'period' => '', 'amount' => '', 'number' => '', 'due_on' => ''];

    /** @var array<string, mixed> */
    public array $contact = ['customer_id' => null, 'channel' => 'call', 'direction' => 'out', 'summary' => '', 'next_action' => '', 'next_action_at' => ''];

    public ?string $hourlyCost = null;

    public function mount(AgencyOperations $operations): void
    {
        $this->tab = array_key_exists($this->tab, self::TABS) ? $this->tab : 'profit';
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month) !== 1) {
            $this->month = now()->format('Y-m');
        }
        $this->time['worked_on'] = now()->toDateString();
        $this->invoice['period'] = $this->month;
        $cost = $operations->hourlyCost();
        $this->hourlyCost = $cost !== null ? (string) $cost : null;
    }

    public function addTime(): void
    {
        $data = $this->validate([
            'time.customer_id' => ['required', 'integer', Rule::exists('customers', 'id')],
            'time.worked_on' => ['required', 'date'],
            'time.minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            'time.category' => ['required', Rule::in(array_keys(TimeEntry::CATEGORIES))],
            'time.note' => ['nullable', 'string', 'max:300'],
        ])['time'];
        TimeEntry::query()->create($data + ['user_id' => auth()->id()]);
        $this->time['note'] = '';
        DemoState::flash('Süre kaydedildi.');
    }

    public function saveHourlyCost(): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
        $value = $this->hourlyCost === null || trim($this->hourlyCost) === '' ? null : (float) str_replace(',', '.', $this->hourlyCost);
        DB::table('agency_settings')->exists()
            ? DB::table('agency_settings')->update(['hourly_cost' => $value, 'updated_at' => now()])
            : DB::table('agency_settings')->insert(['hourly_cost' => $value, 'created_at' => now(), 'updated_at' => now()]);
        DemoState::flash('Saatlik maliyet kaydedildi.');
    }

    public function addCommitment(): void
    {
        $data = $this->validate([
            'commitment.customer_id' => ['required', 'integer', Rule::exists('customers', 'id')],
            'commitment.title' => ['required', 'string', 'max:160'],
            'commitment.monthly_quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'commitment.counts_from' => ['nullable', Rule::in(array_keys(ContentCalendarItem::CHANNELS))],
        ])['commitment'];
        ServiceCommitment::query()->create($data + ['counts_from' => $data['counts_from'] ?: null]);
        $this->commitment['title'] = '';
        DemoState::flash('Taahhüt eklendi.');
    }

    public function markCommitment(int $id, int $delta, AgencyOperations $operations): void
    {
        $commitment = ServiceCommitment::query()->findOrFail($id); // a removed commitment: notice, not a foreign-key error
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month) !== 1) {
            $this->month = now()->format('Y-m');
        }
        $operations->markCommitment((int) $commitment->id, $this->month, $delta >= 0 ? 1 : -1);
    }

    public function stopCommitment(int $id): void
    {
        ServiceCommitment::query()->whereKey($id)->update(['active' => false]);
    }

    public function addInvoice(): void
    {
        $data = $this->validate([
            'invoice.customer_id' => ['required', 'integer', Rule::exists('customers', 'id')],
            'invoice.period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'invoice.amount' => ['required', 'numeric', 'min:0'],
            'invoice.number' => ['nullable', 'string', 'max:40'],
            'invoice.due_on' => ['nullable', 'date'],
        ])['invoice'];
        Invoice::query()->create($data + ['status' => 'draft', 'number' => $data['number'] ?: null, 'due_on' => $data['due_on'] ?: null]);
        $this->invoice['amount'] = '';
        $this->invoice['number'] = '';
        DemoState::flash('Fatura kaydı eklendi.');
    }

    public function setInvoiceStatus(int $id, string $status): void
    {
        abort_unless(in_array($status, ['issued', 'paid', 'cancelled'], true), 422);
        Invoice::query()->whereKey($id)->update(match ($status) {
            'issued' => ['status' => 'issued', 'issued_on' => now()->toDateString()],
            'paid' => ['status' => 'paid', 'paid_on' => now()->toDateString()],
            default => ['status' => 'cancelled'],
        });
    }

    public function draftInvoices(AgencyOperations $operations): void
    {
        DemoState::flash($operations->draftMonthlyInvoices($this->month).' taslak fatura oluşturuldu.');
    }

    public function addContact(): void
    {
        $data = $this->validate([
            'contact.customer_id' => ['required', 'integer', Rule::exists('customers', 'id')],
            'contact.channel' => ['required', Rule::in(array_keys(CustomerInteraction::CHANNELS))],
            'contact.direction' => ['required', Rule::in(['in', 'out'])],
            'contact.summary' => ['required', 'string', 'max:2000'],
            'contact.next_action' => ['nullable', 'string', 'max:300'],
            'contact.next_action_at' => ['nullable', 'date', 'required_with:contact.next_action'],
        ])['contact'];
        CustomerInteraction::query()->create($data + ['occurred_at' => now(), 'user_id' => auth()->id(), 'next_action' => $data['next_action'] ?: null, 'next_action_at' => $data['next_action_at'] ?: null]);
        $this->contact = ['customer_id' => $data['customer_id'], 'channel' => 'call', 'direction' => 'out', 'summary' => '', 'next_action' => '', 'next_action_at' => ''];
        DemoState::flash('Görüşme kaydedildi'.(filled($data['next_action']) ? '; takip Komuta merkezine düşecek.' : '.'));
    }

    public function render(AgencyOperations $operations): View
    {
        $customers = Customer::query()->where('status', CustomerStatus::Active->value)->orderBy('name')->pluck('name', 'id');
        $months = [];
        for ($i = -1; $i < 12; $i++) {
            $key = now()->startOfMonth()->subMonthsNoOverflow($i)->format('Y-m');
            $months[$key] = $key;
        }

        return view('livewire.operator.agency.agency', [
            'customers' => $customers,
            'months' => $months,
            'profit' => $this->tab === 'profit' ? $operations->profitability($this->month) : [],
            'invoices' => $this->tab === 'invoices' ? Invoice::query()->with('customer')->where(fn ($q) => $q->where('period', $this->month)->orWhereIn('status', ['draft', 'issued']))->orderByRaw("case status when 'issued' then 0 when 'draft' then 1 else 2 end")->orderBy('due_on')->limit(300)->get() : collect(),
            'commitments' => $this->tab === 'commitments' ? $operations->commitments($this->month) : [],
            'entries' => $this->tab === 'time' ? TimeEntry::query()->with(['customer', 'brand'])->where('worked_on', '>=', $this->month.'-01')->where('worked_on', '<=', $this->month.'-31')->orderByDesc('worked_on')->limit(300)->get() : collect(),
            'contacts' => $this->tab === 'contacts' ? CustomerInteraction::query()->with(['customer', 'user'])->latest('occurred_at')->limit(100)->get() : collect(),
            'upcoming' => $this->tab === 'contacts' ? CustomerInteraction::query()->with('customer')->whereNotNull('next_action_at')->whereNull('next_action_done_at')->orderBy('next_action_at')->limit(50)->get() : collect(),
            'isAdmin' => (bool) auth()->user()?->hasRole(Roles::ADMIN),
        ]);
    }
}
