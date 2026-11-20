<?php

namespace App\Livewire\Operator\Settings;

use App\Models\Brand;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Panel\Models\PanelAnnouncement;
use App\Panel\Models\PanelTicket;
use App\Panel\Services\PanelSupport;
use App\Panel\Support\OperatorAccess;
use App\Panel\Support\PanelContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Müşteri paneli › Destek (yakup 2026-11-21): every brand's support requests in one box, and the announcements the
 * agency sends to all brands, one sector or one brand.
 */
#[Layout('operator.layouts.app')]
#[Title('Panel desteği')]
final class PanelSupportPage extends Component
{
    #[Url(as: 'sekme')]
    public string $tab = 'talepler';

    #[Url(as: 'talep')]
    public ?int $ticketId = null;

    #[Url(as: 'durum')]
    public string $status = 'waiting';

    public string $reply = '';

    /** @var array<string, string> */
    public array $form = ['title' => '', 'body' => '', 'link_url' => '', 'target' => 'all', 'brand_id' => '', 'sector_id' => ''];

    public function boot(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && OperatorAccess::allowsSection($user, 'client_panel'), 403);
    }

    private function panel(): PanelContext
    {
        return app(PanelContext::class);
    }

    private function ticket(): ?PanelTicket
    {
        return $this->ticketId ? $this->panel()->system(fn () => PanelTicket::query()->with('brand:id,name', 'user:id,name')->find($this->ticketId)) : null;
    }

    public function send(PanelSupport $support): void
    {
        $ticket = $this->ticket();
        abort_if($ticket === null, 404);
        $this->validate(['reply' => ['required', 'string', 'max:5000']], [], ['reply' => 'cevap']);
        $support->replyFromOperator($ticket, (int) auth()->id(), $this->reply);
        $this->reply = '';
        $this->dispatch('operator-notice', message: 'Cevap gönderildi, markanın zili çaldı.', tone: 'ok');
    }

    public function setStatus(string $status, PanelSupport $support): void
    {
        $ticket = $this->ticket();
        abort_if($ticket === null, 404);
        $support->setStatus($ticket, $status);
    }

    public function announce(PanelSupport $support): void
    {
        $data = $this->validate([
            'form.title' => ['required', 'string', 'max:190'],
            'form.body' => ['required', 'string', 'max:5000'],
            'form.link_url' => ['nullable', 'url', 'max:500'],
            'form.target' => ['required', 'in:all,brand,sector'],
            'form.brand_id' => ['required_if:form.target,brand', 'nullable', 'integer', 'exists:brands,id'],
            'form.sector_id' => ['required_if:form.target,sector', 'nullable', 'integer'],
        ], [], ['form.title' => 'başlık', 'form.body' => 'metin', 'form.link_url' => 'bağlantı', 'form.brand_id' => 'marka', 'form.sector_id' => 'sektör'])['form'];
        $row = PanelAnnouncement::query()->create([
            'title' => trim($data['title']), 'body' => trim($data['body']), 'link_url' => ($data['link_url'] ?? '') ?: null, 'created_by' => auth()->id(),
            'brand_id' => $data['target'] === 'brand' ? (int) $data['brand_id'] : null,
            'sector_id' => $data['target'] === 'sector' ? (int) $data['sector_id'] : null,
        ]);
        $sent = $support->announce($row);
        $this->form = ['title' => '', 'body' => '', 'link_url' => '', 'target' => 'all', 'brand_id' => '', 'sector_id' => ''];
        $this->dispatch('operator-notice', message: 'Duyuru yayında, '.$sent.' kullanıcının zili çaldı.', tone: 'ok');
    }

    public function withdraw(int $id): void
    {
        PanelAnnouncement::query()->findOrFail($id)->forceFill(['published_at' => null])->save();
        $this->dispatch('operator-notice', message: 'Duyuru yayından kaldırıldı.', tone: 'ok');
    }

    public function render(): View
    {
        if (! in_array($this->tab, ['talepler', 'duyurular'], true)) {
            $this->tab = 'talepler';
        }
        $ticket = $this->tab === 'talepler' ? $this->ticket() : null;
        $panel = $this->panel();

        return view('livewire.operator.settings.panel-support', [
            'ticket' => $ticket,
            'messages' => $ticket ? $panel->system(fn () => $ticket->messages()->with(['user:id,name', 'operator:id,name'])->orderBy('id')->get()) : collect(),
            'tickets' => $this->tab === 'talepler' && ! $ticket ? $panel->system(fn () => PanelTicket::query()->with('brand:id,name', 'user:id,name')
                ->when($this->status === 'waiting', fn ($q) => $q->where('status', PanelTicket::OPEN))
                ->when($this->status === 'done', fn ($q) => $q->where('status', '!=', PanelTicket::OPEN))
                ->orderByDesc('last_message_at')->limit(200)->get()) : collect(),
            'waiting' => $panel->system(fn () => PanelTicket::query()->where('status', PanelTicket::OPEN)->count()),
            'announcements' => $this->tab === 'duyurular' ? PanelAnnouncement::query()->with('brand:id,name')->latest('id')->limit(100)->get() : collect(),
            'brands' => $this->tab === 'duyurular' ? Brand::query()->orderBy('name')->get(['id', 'name']) : collect(),
            'sectors' => $this->tab === 'duyurular' ? ServiceCategory::query()->whereIn('id', Brand::query()->whereNotNull('sector_id')->distinct()->pluck('sector_id'))->orderBy('name')->get(['id', 'name']) : collect(),
        ]);
    }
}
