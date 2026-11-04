<?php

namespace App\Livewire\Operator\Sales;

use App\Models\Brand;
use App\Models\Customer;
use App\Models\Reminder;
use App\Models\SalesLead;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\Sales\SalesLeads;
use App\Services\Sales\SalesReplyWriter;
use App\Services\Sales\SalesShare;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Satış (yakup, 2026-11-04): one place for every prospect. Bugün = whose next step is today, with the text ready;
 * Adaylar = every open card by stage; İstekler = customers' chats waiting for an answer (one click makes a task);
 * Sonuçlar = where leads come from and what they bring. MoxDOP never sends a message.
 */
#[Layout('operator.layouts.app')]
#[Title('Satış')]
final class SalesPage extends Component
{
    public const array TABS = ['bugun' => 'Bugün', 'adaylar' => 'Adaylar', 'istekler' => 'Müşteri istekleri', 'sonuclar' => 'Sonuçlar'];

    #[Url(as: 'sekme', except: 'bugun')]
    public string $tab = 'bugun';

    #[Url(as: 'asama', except: '')]
    public string $stageFilter = '';

    #[Url(as: 'q', except: '')]
    public string $q = '';

    public bool $adding = false;

    /** @var array{name: string, clinic: string, city: string, phone: string, website: string, source: string, note: string} */
    public array $form = ['name' => '', 'clinic' => '', 'city' => '', 'phone' => '', 'website' => '', 'source' => 'phone', 'note' => ''];

    public string $message = '';

    public bool $pasting = false;

    public string $paste = '';

    public string $pasteNote = '';

    public function mount(): void
    {
        $this->message = (string) session('sales_notice', '');
        $quick = (string) session('sales_quick', '');
        if ($quick !== '') {
            $this->adding = true;
            $this->form['note'] = mb_substr($quick, 0, 1000);
        }
    }

    public function updatedTab(): void
    {
        if (! isset(self::TABS[$this->tab])) {
            $this->tab = 'bugun';
        }
    }

    public function quickAdd(SalesLeads $leads): void
    {
        $this->resetValidation();
        try {
            $lead = $leads->quickAdd($this->form, auth()->user());
        } catch (ValidationException $exception) {
            $this->addError('form', (string) collect($exception->errors())->flatten()->first());

            return;
        }
        $this->form = ['name' => '', 'clinic' => '', 'city' => '', 'phone' => '', 'website' => '', 'source' => 'phone', 'note' => ''];
        $this->adding = false;
        $this->redirectRoute('operator.sales.lead', ['lead' => $lead->id], navigate: true);
    }

    /** "Gönderdim" on a Bugün row: the text went out from the phone; the next step is scheduled. */
    public function markSent(int $id, SalesLeads $leads): void
    {
        $lead = SalesLead::query()->findOrFail($id);
        $leads->markSent($lead, filled($lead->draft) ? (string) $lead->draft : null, auth()->user());
        $this->say($lead->title().': gönderildi olarak işlendi; sonraki adım '.$lead->next_step_at?->timezone('Europe/Istanbul')->format('d.m.Y').'.');
    }

    /** "Sohbeti yapıştır": a chat copied from WhatsApp opens (or finds) the card and Claude answers at once. */
    public function pasteChat(SalesShare $share, SalesLeads $leads, SalesReplyWriter $writer): void
    {
        $this->resetErrorBag('paste');
        if (trim($this->paste) === '') {
            $this->addError('paste', 'Sohbeti yapıştır.');

            return;
        }
        $result = $share->importChat(mb_substr(trim($this->paste), 0, 400_000), '', auth()->user());
        $lead = $result['lead'];
        if ($lead === null || ! str_contains($result['notice'], 'mesaj karta işlendi')) {
            $this->addError('paste', $result['notice']);

            return;
        }
        if (trim($this->pasteNote) !== '') {
            $leads->event($lead, 'note', mb_substr(trim($this->pasteNote), 0, 4000), meta: ['by' => auth()->id(), 'for_ai' => true]);
        }
        if ((int) ini_get('max_execution_time') > 0) {
            @set_time_limit(150); // web only: the CLI (queue, tests) has no limit and must keep none
        }
        $ok = $writer->write($lead->id, 50);
        $this->paste = '';
        $this->pasteNote = '';
        session()->flash('sales_notice', $result['notice'].($ok ? ' Claude cevabı yazdı.' : ' Claude yazamadı; kartta "Yeniden yaz" ile dene.'));
        $this->redirectRoute('operator.sales.lead', ['lead' => $lead->id], navigate: true);
    }

    /** "Yaz" on a Bugün row without a text: Claude writes it in the background. */
    public function draftFor(int $id): void
    {
        $lead = SalesLead::query()->findOrFail($id);
        SalesReplyWriter::queue($lead);
        $this->say($lead->title().': Claude yazıyor, birkaç saniye içinde burada.');
    }

    /** "Bugün değil": the step moves to tomorrow morning. */
    public function snooze(int $id, SalesLeads $leads): void
    {
        $lead = SalesLead::query()->findOrFail($id);
        $leads->setNextStep($lead, (string) $lead->next_step, CarbonImmutable::now('Europe/Istanbul')->addDay()->toDateString(), auth()->user());
        $this->say($lead->title().' yarına alındı.');
    }

    public function setStage(int $id, string $stage, SalesLeads $leads): void
    {
        $lead = SalesLead::query()->findOrFail($id);
        if ($stage === 'lost') {
            $leads->setStage($lead, 'lost', 'silent', auth()->user());
        } else {
            $leads->setStage($lead, $stage, null, auth()->user());
        }
        $this->say($lead->title().': '.$lead->fresh()->stageLabel().'.');
    }

    /** "Konuşmaları tara": every WhatsApp chat without a card gets one. */
    public function scan(SalesLeads $leads): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
        $created = $leads->scanConversations();
        $this->say($created > 0 ? $created.' görüşmeden kart açıldı.' : 'Kartı olmayan görüşme kalmadı.');
    }

    /** A customer's request becomes a task: a reminder for tomorrow 10:00 on Bugün and the phone. */
    public function toTask(int $conversationId, SalesLeads $leads): void
    {
        $conversation = WhatsAppConversation::query()->whereNotNull('customer_id')->findOrFail($conversationId);
        $customer = Customer::query()->find($conversation->customer_id);
        $last = WhatsAppMessage::query()->where('conversation_id', $conversation->id)->where('direction', 'incoming')->orderByDesc('sent_at')->first();
        $snippet = mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $last?->body)), 0, 140);
        $brandId = Brand::query()->where('customer_id', $conversation->customer_id)->orderBy('id')->value('id');
        Reminder::query()->create([
            'user_id' => auth()->id(), 'customer_id' => $conversation->customer_id, 'brand_id' => $brandId,
            'title' => 'Müşteri isteği: '.($customer?->name ?? $conversation->contact_name ?? $conversation->contact_id),
            'notes' => $snippet !== '' ? $snippet : null, 'remind_at' => CarbonImmutable::now('Europe/Istanbul')->addDay()->setTime(10, 0), 'repeat' => 'none',
        ]);
        $conversation->update(['done_at' => now()]);
        $this->say('Görev açıldı: yarın 10:00\'da Bugün\'de ve telefonda hatırlatılır.');
    }

    public function answered(int $conversationId): void
    {
        WhatsAppConversation::query()->whereNotNull('customer_id')->findOrFail($conversationId)->update(['done_at' => now()]);
        $this->say('Cevaplandı olarak işlendi.');
    }

    public function render(SalesLeads $leads): View
    {
        $this->updatedTab();
        $due = $leads->due()->get();
        $open = SalesLead::query()->open();
        $stageCounts = (clone $open)->get(['stage'])->countBy('stage')->all();
        $list = collect();
        if ($this->tab === 'adaylar') {
            $query = SalesLead::query()->when($this->stageFilter !== '' && isset(SalesLead::STAGES[$this->stageFilter]), fn ($q) => $q->where('stage', $this->stageFilter), fn ($q) => $q->open())
                ->when(trim($this->q) !== '', function ($q): void {
                    $term = '%'.mb_substr(trim($this->q), 0, 80).'%';
                    $q->where(fn ($n) => $n->where('name', 'like', $term)->orWhere('clinic', 'like', $term)->orWhere('city', 'like', $term)->orWhere('phone', 'like', $term)->orWhere('website_host', 'like', $term));
                });
            $list = $query->orderByRaw('case when next_step_at is null then 1 else 0 end')->orderBy('next_step_at')->orderByDesc('updated_at')->limit(300)->get();
        }
        $requests = $this->tab === 'istekler' ? $leads->customerRequests() : collect();
        $customers = $requests->isNotEmpty() ? Customer::query()->whereKey($requests->pluck('customer_id')->unique())->get(['id', 'name', 'monthly_fee'])->keyBy('id') : collect();
        $previews = [];
        if ($requests->isNotEmpty()) {
            $latest = WhatsAppMessage::query()->whereIn('conversation_id', $requests->pluck('id'))->where('direction', 'incoming')->selectRaw('max(id) as id')->groupBy('conversation_id')->pluck('id');
            foreach (WhatsAppMessage::query()->whereKey($latest)->get(['conversation_id', 'body']) as $row) {
                $previews[$row->conversation_id] = mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $row->body)), 0, 160);
            }
        }

        return view('livewire.operator.sales.sales-page', [
            'due' => $due,
            'dueCount' => $due->count(),
            'openCount' => (clone $open)->count(),
            'stageCounts' => $stageCounts,
            'list' => $list,
            'requests' => $requests,
            'requestCustomers' => $customers,
            'previews' => $previews,
            'results' => $this->tab === 'sonuclar' ? $leads->results() : [],
            'money' => $this->tab === 'sonuclar' ? $this->money() : null,
            'isAdmin' => (bool) auth()->user()?->hasRole(Roles::ADMIN),
            'wonThisMonth' => SalesLead::query()->where('stage', 'won')->where('won_at', '>=', now('Europe/Istanbul')->startOfMonth())->count(),
        ]);
    }

    /** @return array{mrr: float, customers: int, priced: int, won_month: int, lost_month: int} */
    private function money(): array
    {
        $active = Customer::query()->where('status', 'active');

        return [
            'mrr' => (float) (clone $active)->sum('monthly_fee'),
            'customers' => (clone $active)->count(),
            'priced' => SalesLead::query()->where('stage', 'priced')->count(),
            'won_month' => SalesLead::query()->where('stage', 'won')->where('won_at', '>=', now('Europe/Istanbul')->startOfMonth())->count(),
            'lost_month' => SalesLead::query()->where('stage', 'lost')->where('lost_at', '>=', now('Europe/Istanbul')->startOfMonth())->count(),
        ];
    }

    private function say(string $message): void
    {
        $this->message = $message;
        $this->dispatch('operator-notice', message: $message, tone: 'success');
    }
}
