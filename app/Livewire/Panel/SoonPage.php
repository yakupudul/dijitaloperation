<?php

namespace App\Livewire\Panel;

use App\Panel\Support\PanelPage;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Gelen kutusu, Performans and Destek: in the menu from the first version, opened by the versions that follow. */
#[Layout('panel.layouts.app')]
final class SoonPage extends Component
{
    use PanelPage;

    public string $screen = 'inbox';

    public function mount(): void
    {
        $this->screen = match ((string) request()->route()?->getName()) {
            'panel.performance' => 'performance',
            'panel.support' => 'support',
            default => 'inbox',
        };
    }

    protected function permission(): string
    {
        // On a later click the route is Livewire's own; the screen then comes from the component's state.
        $screen = match ((string) request()->route()?->getName()) {
            'panel.performance' => 'performance',
            'panel.support' => 'support',
            'panel.inbox' => 'inbox',
            default => $this->screen,
        };

        return 'menu.'.$screen;
    }

    public function render(): View
    {
        $screens = [
            'inbox' => ['Gelen kutusu', 'Web formu, çağrı, WhatsApp ve Instagram konuşmaları tek ekranda toplanacak. Konuşmanın yanından not, hatırlatma ve randevu ekleyeceksiniz.', 'Şimdilik sitenizdeki formdan gelen talepler ve elle yazdığınız çağrılar Adaylar ekranına düşüyor.'],
            'performance' => ['Performans', 'Satış, web sitesi, Google Ads ve Meta reklamları sekmeleriyle hangi kaynağın kaç aday ve kaç müşteri getirdiğini göreceksiniz.', 'Şimdilik kaynak ve aşama dağılımını Adaylar ekranındaki süzgeçlerle görebilirsiniz.'],
            'support' => ['Destek', 'Moximu\'ya buradan talep açacak, yanıtları ve duyuruları burada göreceksiniz.', 'Şimdilik her zamanki WhatsApp hattımızdan yazabilirsiniz.'],
        ];

        return view('livewire.panel.soon', ['screenTitle' => $screens[$this->screen][0], 'coming' => $screens[$this->screen][1], 'now' => $screens[$this->screen][2]])
            ->title($screens[$this->screen][0]);
    }
}
