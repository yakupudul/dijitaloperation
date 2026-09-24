<?php

namespace App\Livewire\Operator\Sales;

use App\Models\AgencySetting;
use App\Services\Sales\AgencyLeadInbox;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Satış › Lead kutusu (Faz 8g): the agency's own incoming requests — status, one-click conversion to a prospect,
 * manual entry, and (admin) the website form webhook address.
 */
#[Layout('operator.layouts.app')]
#[Title('Lead kutusu')]
final class LeadInboxPage extends Component
{
    #[Url]
    public string $status = 'open';

    /** @var array{name: string, company: string, phone: string, email: string, message: string, source: string} */
    public array $manual = ['name' => '', 'company' => '', 'phone' => '', 'email' => '', 'message' => '', 'source' => 'phone'];

    public ?string $newToken = null;

    public string $message = '';

    public string $error = '';

    public function setStatus(int $id, string $status): void
    {
        abort_unless(array_key_exists($status, AgencyLeadInbox::STATUSES) && $status !== 'converted', 422);
        DB::table('agency_leads')->where('id', $id)->update(['status' => $status, 'handled_by' => auth()->id(), 'updated_at' => now()]);
    }

    public function convert(int $id, AgencyLeadInbox $inbox): mixed
    {
        $prospect = $inbox->convert($id, auth()->user());

        return $this->redirectRoute('operator.prospect', ['prospectId' => $prospect->id], navigate: true);
    }

    public function addManual(AgencyLeadInbox $inbox): void
    {
        $data = validator($this->manual, [
            'name' => ['nullable', 'string', 'max:160'], 'company' => ['nullable', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40', 'required_without:email'], 'email' => ['nullable', 'email', 'max:190'],
            'message' => ['nullable', 'string', 'max:5000'], 'source' => ['required', 'in:phone,whatsapp,manual,referral'],
        ])->validate();
        $source = $data['source'] === 'referral' ? 'manual' : $data['source'];
        $inbox->receive(array_filter($data, static fn ($v): bool => $v !== null && $v !== ''), $source);
        $this->manual = ['name' => '', 'company' => '', 'phone' => '', 'email' => '', 'message' => '', 'source' => 'phone'];
        $this->error = '';
        $this->message = 'Talep eklendi.';
    }

    public function rotateToken(AgencyLeadInbox $inbox): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
        $this->newToken = $inbox->rotateToken();
        $this->message = 'Yeni adres oluşturuldu; eski adres artık çalışmaz. Bu adresi yalnız şimdi tam görürsün.';
    }

    public function render(): View
    {
        $leads = DB::table('agency_leads')
            ->when($this->status === 'open', fn ($q) => $q->whereIn('status', ['new', 'contacted']))
            ->when(! in_array($this->status, ['open', 'all'], true), fn ($q) => $q->where('status', $this->status))
            ->orderByDesc('received_at')->limit(200)->get();

        return view('livewire.operator.sales.lead-inbox', [
            'leads' => $leads,
            'counts' => DB::table('agency_leads')->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status')->all(),
            'statuses' => AgencyLeadInbox::STATUSES,
            'hint' => AgencySetting::query()->value('lead_inbox_token_hint'),
            'isAdmin' => (bool) auth()->user()?->hasRole(Roles::ADMIN),
        ]);
    }
}
