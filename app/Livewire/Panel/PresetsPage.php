<?php

namespace App\Livewire\Panel;

use App\Panel\Models\PanelPreset;
use App\Panel\Services\PanelPresets;
use App\Panel\Support\PanelPage;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Hazır listeler: the texts a brand writes once and picks afterwards (topics, note templates, ready messages). */
#[Layout('panel.layouts.app')]
#[Title('Hazır listeler')]
final class PresetsPage extends Component
{
    use PanelPage;

    public const array KINDS = [
        PanelPreset::INTEREST => ['title' => 'İlgilenilen konular', 'hint' => 'Aday eklerken “İlgilendiği konu” alanında seçilir.', 'body' => false, 'name' => 'Konu', 'placeholder' => 'Örn. Ücretsiz ön görüşme'],
        PanelPreset::NOTE => ['title' => 'Not şablonları', 'hint' => 'Not yazarken “Şablondan seç” ile kutuya gelir, üstüne ekleme yapabilirsiniz.', 'body' => true, 'name' => 'Şablon adı', 'placeholder' => 'Örn. Ulaşılamadı'],
        PanelPreset::MESSAGE => ['title' => 'Hazır WhatsApp mesajları', 'hint' => 'Kişinin sayfasında “Hazır mesaj” ile WhatsApp yazılı olarak açılır. {ad} yazdığınız yere kişinin adı gelir.', 'body' => true, 'name' => 'Mesaj adı', 'placeholder' => 'Örn. Randevu hatırlatma'],
    ];

    /** @var array<string, array{label: string, body: string}> */
    public array $draft = [];

    protected function permission(): string
    {
        return 'menu.leads';
    }

    public function mount(): void
    {
        foreach (array_keys(self::KINDS) as $kind) {
            $this->draft[$kind] = ['label' => '', 'body' => ''];
        }
    }

    private function mayWrite(): bool
    {
        return $this->may('leads.create') || $this->may('notes.write');
    }

    public function add(string $kind, PanelPresets $presets): void
    {
        abort_unless($this->mayWrite() && isset(self::KINDS[$kind]), 403);
        $needsBody = self::KINDS[$kind]['body'];
        $data = $this->validate([
            "draft.$kind.label" => ['required', 'string', 'max:'.($needsBody ? 80 : 190)],
            "draft.$kind.body" => [$needsBody ? 'required' : 'nullable', 'string', 'max:5000'],
        ], [], ["draft.$kind.label" => 'ad', "draft.$kind.body" => 'metin'])['draft'][$kind];
        $preset = $presets->add($kind, $data['label'], $needsBody ? $data['body'] : null, (int) $this->panelUser()->id);
        $this->draft[$kind] = ['label' => '', 'body' => ''];
        $this->notify('“'.$preset->label.'” eklendi.');
    }

    public function remove(int $id): void
    {
        abort_unless($this->panel()->isManager(), 403);
        $preset = PanelPreset::query()->findOrFail($id);
        $preset->delete();
        $this->notify('“'.$preset->label.'” listeden çıkarıldı.');
    }

    public function render(PanelPresets $presets): View
    {
        return view('livewire.panel.presets', [
            'lists' => collect(array_keys(self::KINDS))->mapWithKeys(fn (string $kind): array => [$kind => $presets->of($kind)]),
            'canWrite' => $this->mayWrite(),
            'isManager' => $this->panel()->isManager(),
        ]);
    }
}
