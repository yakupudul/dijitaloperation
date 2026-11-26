<?php

namespace App\Livewire\Panel;

use App\Models\Brand;
use App\Panel\Models\PanelAnnouncement;
use App\Panel\Models\PanelTicket;
use App\Panel\Models\PanelTicketMessage;
use App\Panel\Services\PanelSupport;
use App\Panel\Support\PanelPage;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/** Destek: the brand's requests to the agency, the agency's announcements, and short how-to answers. */
#[Layout('panel.layouts.app')]
#[Title('Destek')]
final class SupportPage extends Component
{
    use PanelPage;
    use WithFileUploads;

    public const array HELP = [
        ['Yeni bir aday nasıl eklenir?', 'Adaylar ekranında “Aday ekle” ile ad ve telefonu yazın. Aynı telefon daha önce kayıtlıysa yeni kayıt açılmaz, talep o kişinin geçmişine eklenir.'],
        ['Sitemdeki form buraya nasıl düşer?', 'Gelen kutusu › Web formu sekmesinde “Bağla”ya basın; çıkan adresi formunuzun webhook ayarına yapıştırın. Sitenizi biz yönetiyorsak bu adımı biz yaparız.'],
        ['Aynı şeyi her seferinde yazmamak için ne yapabilirim?', 'Adaylar ekranındaki “Hazır listeler” ile konuları, not şablonlarını ve hazır WhatsApp mesajlarını bir kez yazın, sonra seçin.'],
        ['Bir adayı neden “Olumsuz” kapatmalıyım?', 'Neden seçtiğinizde ay sonunda hangi konuda kaç kişinin neden gittiğini görürsünüz. Kapatılan kişi yeniden yazarsa kaydı kendiliğinden “Yeni” olur.'],
        ['Personelim hangi bilgileri görür?', 'Kullanıcılar ekranında her kişiye bir rol verirsiniz. İsterseniz o kişiye özel bir yetkiyi açıp kapatabilirsiniz, örneğin telefon numaraları gizli kalsın.'],
        ['Verilerimi indirebilir miyim?', 'Markanın yöneticisi Adaylar ekranında “Excel\'e aktar” ile listeyi indirir. Her indirme kayda geçer.'],
    ];

    #[Url(as: 'sekme')]
    public string $tab = 'talepler';

    #[Url(as: 'talep')]
    public ?int $ticketId = null;

    public bool $adding = false;

    /** @var array<string, string> */
    public array $form = ['subject' => '', 'category' => 'question', 'body' => ''];

    public string $reply = '';

    public $upload = null;

    protected function permission(): string
    {
        return 'menu.support';
    }

    private function ticket(): ?PanelTicket
    {
        return $this->ticketId ? PanelTicket::query()->find($this->ticketId) : null;
    }

    /** @return array{0: ?string, 1: ?string} */
    private function stored(int $ticketId): array
    {
        if ($this->upload === null) {
            return [null, null];
        }
        $this->validate(['upload' => ['file', 'max:'.(int) config('panel.max_file_kb'), 'mimes:pdf,jpg,jpeg,png,webp,heic']], ['upload.mimes' => 'Ekran görüntüsü ya da PDF yükleyin.', 'upload.max' => 'Dosya 20 MB\'tan büyük olamaz.'], ['upload' => 'dosya']);
        $name = mb_substr((string) $this->upload->getClientOriginalName(), 0, 190);
        $path = $this->upload->store('panel/'.$this->panel()->brandId().'/tickets/'.$ticketId, (string) config('panel.files_disk'));
        $this->upload = null;

        return [$path, $name];
    }

    public function open(PanelSupport $support): void
    {
        abort_unless($this->may('support.write'), 403);
        $data = $this->validate([
            'form.subject' => ['required', 'string', 'max:190'],
            'form.category' => ['required', Rule::in(array_keys(PanelTicket::CATEGORIES))],
            'form.body' => ['required', 'string', 'max:5000'],
        ], [], ['form.subject' => 'konu', 'form.body' => 'açıklama'])['form'];
        $ticket = $support->open((int) $this->panel()->brandId(), (int) $this->panelUser()->id, $data['subject'], $data['category'], $data['body']);
        [$path, $name] = $this->stored($ticket->id);
        if ($path !== null) {
            PanelTicketMessage::query()->where('ticket_id', $ticket->id)->oldest('id')->first()?->forceFill(['attachment_path' => $path, 'attachment_name' => $name])->save();
        }
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
        $this->validate(['reply' => ['required', 'string', 'max:5000']], [], ['reply' => 'mesaj']);
        [$path, $name] = $this->stored($ticket->id);
        $support->replyFromBrand($ticket, (int) $this->panelUser()->id, $this->reply, $path, $name);
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

    public function render(): View
    {
        if (! in_array($this->tab, ['talepler', 'duyurular', 'yardim'], true)) {
            $this->tab = 'talepler';
        }
        $ticket = $this->tab === 'talepler' ? $this->ticket() : null;
        $brand = Brand::query()->find($this->panel()->brandId());

        return view('livewire.panel.support', [
            'tickets' => $this->tab === 'talepler' && $ticket === null ? PanelTicket::query()->with('user:id,name')->orderByRaw("case status when 'answered' then 0 when 'open' then 1 else 2 end")->orderByDesc('last_message_at')->limit(100)->get() : collect(),
            'ticket' => $ticket,
            'messages' => $ticket ? $ticket->messages()->with(['user:id,name', 'operator:id,name'])->orderBy('id')->get() : collect(),
            'announcements' => $this->tab === 'duyurular' && $brand ? PanelAnnouncement::query()->forBrand($brand)->orderByDesc('published_at')->limit(50)->get() : collect(),
            'canWrite' => $this->may('support.write'),
        ]);
    }
}
