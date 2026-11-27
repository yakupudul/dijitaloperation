<?php

namespace App\Livewire\Panel;

use App\Models\Brand;
use App\Models\Reminder;
use App\Models\User;
use App\Panel\Models\PanelAnnouncement;
use App\Panel\Models\PanelDocument;
use App\Panel\Models\PanelMeeting;
use App\Panel\Models\PanelTicket;
use App\Panel\Services\PanelSupport;
use App\Panel\Support\PanelPage;
use App\Panel\Support\RichText;
use App\Services\Calendar\QuickCapture;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

/** Destek: the brand's requests to the agency, the files they share, meeting requests and the agency's announcements. */
#[Layout('panel.layouts.app')]
#[Title('Destek')]
final class SupportPage extends Component
{
    use PanelPage;
    use WithFileUploads;

    public const array TABS = ['talepler', 'dosyalar', 'toplantilar', 'duyurular'];

    public const array HELP = [
        ['Yeni bir aday nasıl eklenir?', 'Adaylar ekranında “Aday ekle” ile ad ve telefonu yazın. Aynı telefon daha önce kayıtlıysa yeni kayıt açılmaz, talep o kişinin geçmişine eklenir.'],
        ['Sitemdeki form buraya nasıl düşer?', 'Gelen kutusu › Web formu sekmesinde “Bağla”ya basın; çıkan adresi formunuzun webhook ayarına yapıştırın. Sitenizi biz yönetiyorsak bu adımı biz yaparız.'],
        ['Aynı şeyi her seferinde yazmamak için ne yapabilirim?', 'Adaylar ekranındaki “Hazır listeler” ile konuları, not şablonlarını ve hazır WhatsApp mesajlarını bir kez yazın, sonra seçin.'],
        ['Bir adayı neden “Olumsuz” kapatmalıyım?', 'Neden seçtiğinizde ay sonunda hangi konuda kaç kişinin neden gittiğini görürsünüz. Kapatılan kişi yeniden yazarsa kaydı kendiliğinden “Yeni” olur.'],
        ['Personelim hangi bilgileri görür?', 'Ekip ekranında her kişiye bir rol verirsiniz. İsterseniz o kişiye özel bir yetkiyi açıp kapatabilirsiniz, örneğin telefon numaraları gizli kalsın.'],
        ['Verilerimi indirebilir miyim?', 'Markanın yöneticisi Adaylar ekranında “Excel\'e aktar” ile listeyi indirir. Her indirme kayda geçer.'],
    ];

    public string $tab = 'talepler';

    #[Url(as: 'talep')]
    public ?int $ticketId = null;

    public bool $adding = false;

    /** @var array<string, string> */
    public array $form = ['subject' => '', 'category' => 'question', 'body' => ''];

    public string $reply = '';

    /** Files waiting to go with the message being written, or to the shelf. */
    public array $uploads = [];

    public bool $asking = false;

    /** @var array<string, string> */
    public array $meeting = ['topic' => '', 'preferred' => '', 'note' => ''];

    protected function permission(): string
    {
        return 'menu.support';
    }

    public function mount(string $tab = 'talepler'): void
    {
        // Older bell notices and bookmarks point at ?sekme=duyurular.
        $asked = (string) request()->query('sekme', '');
        $this->tab = in_array($asked, self::TABS, true) ? $asked : (in_array($tab, self::TABS, true) ? $tab : 'talepler');
    }

    private function ticket(): ?PanelTicket
    {
        return $this->ticketId ? PanelTicket::query()->find($this->ticketId) : null;
    }

    /** @return list<UploadedFile> */
    private function files(): array
    {
        if ($this->uploads === []) {
            return [];
        }
        $this->validate(['uploads' => ['array', 'max:10'], 'uploads.*' => ['file', 'max:'.(int) config('panel.max_file_kb'), 'mimes:'.PanelSupport::FILE_KINDS]],
            ['uploads.max' => 'Bir seferde en çok 10 dosya ekleyin.', 'uploads.*.mimes' => 'Bu dosya türü alınmıyor.', 'uploads.*.max' => 'Bir dosya 20 MB\'tan büyük olamaz.'], ['uploads.*' => 'dosya']);
        $files = array_values($this->uploads);
        $this->uploads = [];

        return $files;
    }

    public function dropUpload(int $index): void
    {
        unset($this->uploads[$index]);
        $this->uploads = array_values($this->uploads);
    }

    private function written(string $html, string $field, string $name): string
    {
        $clean = RichText::clean($html);
        if ($clean === '' || mb_strlen(RichText::plain($clean)) > 8000) {
            $this->addError($field, $clean === '' ? ucfirst($name).' boş olamaz.' : ucfirst($name).' çok uzun.');
        }

        return $clean;
    }

    public function open(PanelSupport $support): void
    {
        abort_unless($this->may('support.write'), 403);
        $data = $this->validate([
            'form.subject' => ['required', 'string', 'max:190'],
            'form.category' => ['required', Rule::in(array_keys(PanelTicket::CATEGORIES))],
            'form.body' => ['required', 'string', 'max:40000'],
        ], [], ['form.subject' => 'konu', 'form.body' => 'açıklama'])['form'];
        $body = $this->written($data['body'], 'form.body', 'açıklama');
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }
        $ticket = $support->open((int) $this->panel()->brandId(), (int) $this->panelUser()->id, $data['subject'], $data['category'], $body, files: $this->files());
        $this->form = ['subject' => '', 'category' => 'question', 'body' => ''];
        $this->adding = false;
        $this->ticketId = $ticket->id;
        $this->notify('Talebiniz Moximu\'ya ulaştı. Cevap gelince zil haber verir.');
    }

    public function send(PanelSupport $support): void
    {
        abort_unless($this->may('support.write'), 403);
        $ticket = $this->ticket();
        abort_if($ticket === null, 404);
        $this->validate(['reply' => ['required', 'string', 'max:40000']], [], ['reply' => 'mesaj']);
        $body = $this->written($this->reply, 'reply', 'mesaj');
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }
        $support->replyFromBrand($ticket, (int) $this->panelUser()->id, $body, files: $this->files());
        $this->reply = '';
        $this->notify('Mesajınız gönderildi.');
    }

    public function solve(PanelSupport $support): void
    {
        abort_unless($this->may('support.write'), 403);
        $ticket = $this->ticket();
        abort_if($ticket === null, 404);
        $support->setStatus($ticket, PanelTicket::SOLVED);
        $this->notify('Talep çözüldü olarak kapandı.');
    }

    /** Files put straight on the shelf, without a request. */
    public function shelve(PanelSupport $support): void
    {
        abort_unless($this->may('support.write'), 403);
        $files = $this->files();
        if ($files === []) {
            $this->addError('uploads', 'Önce dosya seçin.');

            return;
        }
        $kept = $support->keep((int) $this->panel()->brandId(), $files, (int) $this->panelUser()->id, null);
        $this->notify(count($kept).' dosya Moximu\'ya ulaştı.');
    }

    public function askMeeting(): void
    {
        abort_unless($this->may('support.write'), 403);
        $data = $this->validate([
            'meeting.topic' => ['required', 'string', 'max:190'],
            'meeting.preferred' => ['required', 'string', 'max:300'],
            'meeting.note' => ['nullable', 'string', 'max:2000'],
        ], [], ['meeting.topic' => 'konu', 'meeting.preferred' => 'uygun zaman', 'meeting.note' => 'not'])['meeting'];
        $row = PanelMeeting::query()->create(['panel_user_id' => $this->panelUser()->id, 'topic' => trim($data['topic']), 'preferred' => trim($data['preferred']), 'note' => trim((string) $data['note']) ?: null, 'status' => PanelMeeting::REQUESTED]);
        $this->tellOperator($row);
        $this->meeting = ['topic' => '', 'preferred' => '', 'note' => ''];
        $this->asking = false;
        $this->notify('Toplantı isteğiniz ulaştı. Günü ve bağlantıyı buradan bildireceğiz.');
    }

    public function cancelMeeting(int $id): void
    {
        abort_unless($this->may('support.write'), 403);
        PanelMeeting::query()->whereIn('status', [PanelMeeting::REQUESTED, PanelMeeting::PLANNED])->findOrFail($id)->forceFill(['status' => PanelMeeting::CANCELLED])->save();
        $this->notify('Toplantı iptal edildi.');
    }

    /** One line on the operator's Yapılacaklar; a failure here never loses the request. */
    private function tellOperator(PanelMeeting $meeting): void
    {
        try {
            $operator = User::query()->role(Roles::ADMIN)->where('is_active', true)->orderBy('id')->first();
            $brand = Brand::query()->find($meeting->brand_id);
            if ($operator === null || $brand === null) {
                return;
            }
            $row = app(QuickCapture::class)->capture($operator, 'Toplantı isteğine gün ver: '.$brand->name.', '.$meeting->topic, route('operator.brand', ['brand' => $brand->id, 'tab' => 'panel']));
            if ($row instanceof Reminder && (int) $row->brand_id !== (int) $brand->id) {
                $row->forceFill(['brand_id' => $brand->id, 'customer_id' => $brand->customer_id])->save();
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function render(): View
    {
        $ticket = $this->tab === 'talepler' ? $this->ticket() : null;
        $brand = Brand::query()->find($this->panel()->brandId());

        return view('livewire.panel.support', [
            'tickets' => $this->tab === 'talepler' && $ticket === null ? PanelTicket::query()->with('user:id,name')->orderByRaw("case status when 'answered' then 0 when 'open' then 1 else 2 end")->orderByDesc('last_message_at')->limit(100)->get() : collect(),
            'ticket' => $ticket,
            'messages' => $ticket ? $ticket->messages()->with(['user:id,name', 'operator:id,name', 'documents'])->orderBy('id')->get() : collect(),
            'documents' => $this->tab === 'dosyalar' ? PanelDocument::query()->with(['user:id,name', 'ticket:id,subject'])->orderByDesc('id')->limit(300)->get() : collect(),
            'meetings' => $this->tab === 'toplantilar' ? PanelMeeting::query()->orderByRaw("case status when 'planned' then 0 when 'requested' then 1 else 2 end")->orderByDesc('id')->limit(60)->get() : collect(),
            'announcements' => $this->tab === 'duyurular' && $brand ? PanelAnnouncement::query()->forBrand($brand)->orderByDesc('published_at')->limit(50)->get() : collect(),
            'canWrite' => $this->may('support.write'),
        ]);
    }
}
