<?php

namespace App\Livewire\Operator\Portfolio;

use App\Models\Agreement;
use App\Models\Brand;
use App\Models\BrandSession;
use App\Models\FinanceDocument;
use App\Models\User;
use App\Panel\Jobs\PushPanelDocumentToDrive;
use App\Panel\Models\PanelBrandSetting;
use App\Panel\Models\PanelDocument;
use App\Panel\Models\PanelMeeting;
use App\Panel\Models\PanelMembership;
use App\Panel\Models\PanelTicket;
use App\Panel\Services\PanelAccount;
use App\Panel\Services\PanelDrive;
use App\Panel\Services\PanelNotifier;
use App\Panel\Services\PanelSupport;
use App\Panel\Support\OperatorAccess;
use App\Panel\Support\PanelContext;
use App\Panel\Support\RichText;
use App\Services\Access\Departure;
use App\Services\Finance\FinanceDocuments;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

/**
 * The customer panel seen from the brand page (yakup 2026-11-27, the app prototype he approved): what the brand's
 * panel shows and who uses it, its requests, the monthly report it reads, its invoices and receipts, and the files it
 * shares. Every outgoing word is a text the operator copies and sends himself; nothing is mailed from here. Counts
 * and the agency's own records only: no lead of the brand is read on this screen.
 */
final class BrandPanelTab extends Component
{
    use WithFileUploads;

    public const array VIEWS = ['panel' => 'Müşteri paneli', 'talepler' => 'Talepler', 'rapor' => 'Aylık rapor', 'finans' => 'Finans', 'dosyalar' => 'Dosyalar'];

    public int $brandId;

    public string $view = 'panel';

    #[Url(as: 'talep')]
    public ?int $ticketId = null;

    public string $status = 'waiting';

    public string $reply = '';

    public bool $adding = false;

    /** @var array<string, string> */
    public array $form = ['subject' => '', 'category' => 'wish', 'body' => '', 'origin' => 'phone'];

    public array $uploads = [];

    public array $invoices = [];

    /** @var array<int, array<string, string>> the day, hour and link typed for a meeting, by its id */
    public array $plan = [];

    /** @var array<int, string> */
    public array $summary = [];

    public function boot(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && OperatorAccess::allowsSection($user, 'client_panel'), 403);
    }

    public function mount(int $brandId, string $view = 'panel'): void
    {
        $this->brandId = $brandId;
        $this->view = isset(self::VIEWS[$view]) ? $view : 'panel';
    }

    private function panel(): PanelContext
    {
        return app(PanelContext::class);
    }

    private function brand(): Brand
    {
        return Brand::query()->with('customer')->findOrFail($this->brandId);
    }

    private function say(string $message, string $tone = 'ok'): void
    {
        $this->dispatch('operator-notice', message: $message, tone: $tone);
    }

    /** @return list<UploadedFile> */
    private function files(): array
    {
        if ($this->uploads === []) {
            return [];
        }
        $this->validate(['uploads' => ['array', 'max:10'], 'uploads.*' => ['file', 'max:'.(int) config('panel.max_file_kb'), 'mimes:'.PanelSupport::FILE_KINDS]],
            ['uploads.max' => 'Bir seferde en çok 10 dosya ekle.', 'uploads.*.mimes' => 'Bu dosya türü alınmıyor.', 'uploads.*.max' => 'Bir dosya 20 MB\'tan büyük olamaz.'], ['uploads.*' => 'dosya']);
        $files = array_values($this->uploads);
        $this->uploads = [];

        return $files;
    }

    public function dropUpload(int $index): void
    {
        unset($this->uploads[$index]);
        $this->uploads = array_values($this->uploads);
    }

    // ------------------------------------------------------------------ Müşteri paneli

    public function makeFolder(PanelDrive $drive): void
    {
        if (! $drive->connected()) {
            $this->say('Google Drive bağlı değil. Sistem › Bağlantılar\'dan Google\'ı yeniden bağla.', 'error');

            return;
        }
        try {
            $drive->folder($this->brand());
            $this->say('Drive klasörü hazır. İstersen kendi müşteri klasörünün içine taşıyabilirsin, adresi değişmez.');
        } catch (Throwable $error) {
            $this->say('Klasör açılamadı: '.$error->getMessage(), 'error');
        }
    }

    public function planMeeting(int $id, PanelNotifier $notifier): void
    {
        $data = $this->validate([
            'plan.'.$id.'.day' => ['required', 'date_format:Y-m-d'],
            'plan.'.$id.'.hour' => ['required', 'date_format:H:i'],
            'plan.'.$id.'.url' => ['nullable', 'url', 'max:500'],
        ], [], ['plan.'.$id.'.day' => 'gün', 'plan.'.$id.'.hour' => 'saat', 'plan.'.$id.'.url' => 'bağlantı'])['plan'][$id];
        $meeting = $this->meeting($id);
        $at = CarbonImmutable::parse($data['day'].' '.$data['hour'], 'Europe/Istanbul');
        $this->panel()->system(fn () => $meeting->forceFill(['status' => PanelMeeting::PLANNED, 'starts_at' => $at->utc(), 'meet_url' => ($data['url'] ?? '') ?: null])->save());
        $notifier->brand($this->brandId, 'meeting', 'Toplantı günü belli oldu: '.$meeting->topic, route('panel.meetings'), 'menu.support');
        unset($this->plan[$id]);
        $this->say('Toplantı planlandı, markanın zili çaldı.');
    }

    public function closeMeeting(int $id): void
    {
        $meeting = $this->meeting($id);
        $text = trim((string) ($this->summary[$id] ?? ''));
        $this->panel()->system(fn () => $meeting->forceFill(['status' => PanelMeeting::DONE, 'summary' => $text !== '' ? mb_substr($text, 0, 2000) : null])->save());
        unset($this->summary[$id]);
        $this->say('Toplantı yapıldı olarak kapandı.');
    }

    private function meeting(int $id): PanelMeeting
    {
        return $this->panel()->system(fn () => PanelMeeting::query()->where('brand_id', $this->brandId)->findOrFail($id));
    }

    /** The leave starts here and only here: the panel turns read only; with an open balance nothing is exported. */
    public function startLeave(): void
    {
        $setting = PanelBrandSetting::forBrand($this->brandId);
        $setting->forceFill(['leaving_at' => now(), 'is_read_only' => true])->save();
        $this->say('Ayrılış başladı: panel yalnız okunur oldu. Açık bakiye varken dışa aktarma ve dosya indirme kapalı.');
    }

    public function stopLeave(): void
    {
        $setting = PanelBrandSetting::forBrand($this->brandId);
        $setting->forceFill(['leaving_at' => null, 'is_read_only' => false])->save();
        $this->say('Ayrılış geri alındı, panel eski haline döndü.');
    }

    // ------------------------------------------------------------------ Talepler

    private function ticket(): ?PanelTicket
    {
        return $this->ticketId ? $this->panel()->system(fn () => PanelTicket::query()->with('user:id,name')->where('brand_id', $this->brandId)->find($this->ticketId)) : null;
    }

    public function openTicket(PanelSupport $support, PanelNotifier $notifier): void
    {
        $data = $this->validate([
            'form.subject' => ['required', 'string', 'max:190'],
            'form.category' => ['required', Rule::in(array_keys(PanelTicket::CATEGORIES))],
            'form.origin' => ['required', Rule::in(['phone', 'whatsapp'])],
            'form.body' => ['required', 'string', 'max:40000'],
        ], [], ['form.subject' => 'konu', 'form.body' => 'ne istendi'])['form'];
        if (RichText::clean($data['body']) === '') {
            $this->addError('form.body', 'Ne istendiğini yaz.');

            return;
        }
        $ticket = $support->openByOperator($this->brandId, (int) auth()->id(), $data['subject'], $data['category'], $data['body'], $data['origin'], $this->files());
        $notifier->brand($this->brandId, 'ticket', 'Sizin için talep açtık: '.$ticket->subject, route('panel.support', ['talep' => $ticket->id]), 'menu.support');
        $this->form = ['subject' => '', 'category' => 'wish', 'body' => '', 'origin' => 'phone'];
        $this->adding = false;
        $this->ticketId = $ticket->id;
        $this->say('Talep açıldı, marka panelinde görüyor.');
    }

    public function send(PanelSupport $support): void
    {
        $ticket = $this->ticket();
        abort_if($ticket === null, 404);
        $this->validate(['reply' => ['required', 'string', 'max:40000']], [], ['reply' => 'cevap']);
        if (RichText::clean($this->reply) === '') {
            $this->addError('reply', 'Cevap boş olamaz.');

            return;
        }
        $support->replyFromOperator($ticket, (int) auth()->id(), $this->reply, $this->files());
        $this->reply = '';
        $this->say('Cevap gönderildi, markanın zili çaldı.');
    }

    public function setStatus(string $status, PanelSupport $support): void
    {
        $ticket = $this->ticket();
        abort_if($ticket === null, 404);
        $support->setStatus($ticket, $status);
    }

    // ------------------------------------------------------------------ Aylık rapor

    private function report(int $id): BrandSession
    {
        return BrandSession::query()->where('brand_id', $this->brandId)->where('status', 'approved')->findOrFail($id);
    }

    public function toggleLine(int $id, int $line): void
    {
        $report = $this->report($id);
        $hidden = array_map('intval', (array) $report->panel_hidden_lines);
        $hidden = in_array($line, $hidden, true) ? array_values(array_diff($hidden, [$line])) : [...$hidden, $line];
        $report->forceFill(['panel_hidden_lines' => array_values(array_unique($hidden))])->save();
    }

    public function publish(int $id, PanelNotifier $notifier): void
    {
        $report = $this->report($id);
        $report->forceFill(['panel_published_at' => now(), 'panel_read_at' => null])->save();
        $notifier->brand($this->brandId, 'report', $report->monthLabel().' raporunuz hazır', route('panel.report'), 'menu.marketing');
        $this->say($report->monthLabel().' raporu panelde yayında, markanın zili çaldı.');
    }

    public function unpublish(int $id): void
    {
        $this->report($id)->forceFill(['panel_published_at' => null])->save();
        $this->say('Rapor panelden kaldırıldı.');
    }

    // ------------------------------------------------------------------ Finans

    private function documents()
    {
        $ids = Agreement::query()->where('brand_id', $this->brandId)->pluck('id');

        return FinanceDocument::query()->where('status', '!=', 'ignored')->where(fn ($q) => $q->where('brand_id', $this->brandId)->orWhereIn('agreement_id', $ids));
    }

    public function toggleInvoice(int $id): void
    {
        $doc = $this->documents()->where('direction', FinanceDocument::OUT)->findOrFail($id);
        $doc->forceFill(['panel_visible' => ! $doc->panel_visible])->save();
        $this->say($doc->panel_visible ? 'Fatura müşterinin panelinde görünüyor.' : 'Fatura panelden kaldırıldı.');
    }

    public function uploadInvoices(FinanceDocuments $documents): void
    {
        $this->validate(['invoices' => ['required', 'array', 'max:10'], 'invoices.*' => ['file', 'max:15360', 'mimes:pdf,xml,jpg,jpeg,png,webp,zip']],
            ['invoices.*.mimes' => 'Fatura PDF, XML, görsel ya da ZIP olmalı.'], ['invoices' => 'fatura', 'invoices.*' => 'fatura']);
        $agreement = Agreement::query()->where('brand_id', $this->brandId)->whereIn('status', ['active', 'paused'])->orderByDesc('id')->first();
        $kept = 0;
        foreach ($this->invoices as $file) {
            $rows = $documents->keep((string) file_get_contents($file->getRealPath()), (string) $file->getClientOriginalName(), (string) $file->getMimeType(),
                ['direction' => FinanceDocument::OUT, 'brand_id' => $this->brandId, 'agreement_id' => $agreement?->id, 'customer_id' => $this->brand()->customer_id, 'source' => 'upload']);
            foreach ($rows as $row) {
                $row->forceFill(['panel_visible' => true, 'brand_id' => $row->brand_id ?: $this->brandId])->save();
                $kept++;
            }
        }
        $this->invoices = [];
        $this->say($kept > 0 ? $kept.' fatura kaydedildi ve müşterinin panelinde açıldı.' : 'Bu dosya daha önce yüklenmiş.', $kept > 0 ? 'ok' : 'error');
    }

    // ------------------------------------------------------------------ Dosyalar

    public function shelve(PanelSupport $support): void
    {
        $files = $this->files();
        if ($files === []) {
            $this->addError('uploads', 'Önce dosya seç.');

            return;
        }
        $kept = $support->keep($this->brandId, $files, null, (int) auth()->id());
        $this->say(count($kept).' dosya markanın rafına kondu.');
    }

    public function retryDrive(int $id): void
    {
        $doc = $this->panel()->system(fn () => PanelDocument::query()->where('brand_id', $this->brandId)->whereNull('drive_file_id')->findOrFail($id));
        PushPanelDocumentToDrive::dispatch((int) $doc->id);
        $this->say('Dosya Drive\'a yeniden gönderiliyor.');
    }

    public function render(PanelAccount $account, PanelDrive $drive, Departure $departure): View
    {
        $brand = $this->brand();
        $panel = $this->panel();
        $setting = PanelBrandSetting::forBrand($this->brandId);
        $data = ['brand' => $brand, 'setting' => $setting, 'tz' => 'Europe/Istanbul', 'driveOn' => $drive->connected(), 'folderUrl' => PanelDrive::folderUrl($setting->drive_folder_id)];

        if ($this->view === 'panel') {
            $members = $panel->system(fn () => PanelMembership::query()->with('user:id,last_login_at')->where('brand_id', $this->brandId)->where('is_active', true)->get());
            $pay = $account->payments($this->brandId);
            $data += [
                'users' => $members->count(),
                'lastLogin' => $members->map(fn (PanelMembership $m) => $m->user?->last_login_at)->filter()->max(),
                'meetings' => $panel->system(fn () => PanelMeeting::query()->where('brand_id', $this->brandId)->whereIn('status', [PanelMeeting::REQUESTED, PanelMeeting::PLANNED])->orderBy('id')->get()),
                'debt' => $pay['debt'], 'late' => $pay['late'],
                'leave' => $departure->plan($brand),
                'invite' => "Merhaba, Moximu paneliniz hazır.\n\nAdres: https://".config('panel.domain')."\nGiriş: size ilettiğimiz e-posta adresi ve geçici şifre. İlk girişte kendi şifrenizi belirlersiniz.\n\nPanelde size ulaşan adayları, aylık raporunuzu, faturalarınızı ve bizden isteklerinizi tek yerde görürsünüz. Takıldığınız yerde buradan yazabilirsiniz.",
            ];
        } elseif ($this->view === 'talepler') {
            $ticket = $this->ticket();
            $data += [
                'ticket' => $ticket,
                'messages' => $ticket ? $panel->system(fn () => $ticket->messages()->with(['user:id,name', 'operator:id,name', 'documents'])->orderBy('id')->get()) : collect(),
                'tickets' => $ticket ? collect() : $panel->system(fn () => PanelTicket::query()->with('user:id,name')->where('brand_id', $this->brandId)
                    ->when($this->status === 'waiting', fn ($q) => $q->where('status', PanelTicket::OPEN))
                    ->when($this->status === 'done', fn ($q) => $q->where('status', '!=', PanelTicket::OPEN))
                    ->orderByDesc('last_message_at')->limit(200)->get()),
                'waiting' => $panel->system(fn () => PanelTicket::query()->where('brand_id', $this->brandId)->where('status', PanelTicket::OPEN)->count()),
            ];
        } elseif ($this->view === 'rapor') {
            $data += ['reports' => BrandSession::query()->where('brand_id', $this->brandId)->where('status', 'approved')->orderByDesc('month')->limit(12)->get()];
        } elseif ($this->view === 'finans') {
            $pay = $account->payments($this->brandId);
            $data += [
                'docs' => $this->documents()->where('direction', FinanceDocument::OUT)->orderByDesc('doc_date')->orderByDesc('id')->limit(60)->get(),
                'receipts' => $this->documents()->where('direction', FinanceDocument::RECEIPT)->orderByDesc('id')->limit(20)->get(),
                'open' => $pay['open'], 'debt' => $pay['debt'], 'late' => $pay['late'],
                'takesInvoices' => $account->takesInvoices($this->brandId),
                'renewals' => $account->renewals($this->brandId),
            ];
        } else {
            $data += ['documents' => $panel->system(fn () => PanelDocument::query()->with(['user:id,name', 'operator:id,name', 'ticket:id,subject'])->where('brand_id', $this->brandId)->orderByDesc('id')->limit(300)->get())];
        }

        return view('livewire.operator.portfolio.brand-panel-tab', $data);
    }
}
