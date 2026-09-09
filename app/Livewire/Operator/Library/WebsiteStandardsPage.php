<?php

namespace App\Livewire\Operator\Library;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use MoxDop\Website\Standards\WebsiteStandardCatalog;

#[Layout('operator.layouts.app')]
#[Title('Standartlar')]
final class WebsiteStandardsPage extends Component
{
    public string $assetType = 'website';

    public string $platform = '';

    public string $group = '';

    public string $search = '';

    public array $draft = ['title' => '', 'group' => 'content', 'criterion' => '', 'action' => '', 'source_url' => ''];

    public string $message = '';

    public function setEnabled(string $id, bool $enabled, WebsiteStandardCatalog $catalog): void
    {
        $catalog->setEnabled($id, $enabled, auth()->user());
        $this->message = 'Standart ayarı kaydedildi. Sonraki değerlendirmelerde uygulanacak.';
    }

    public function render(WebsiteStandardCatalog $catalog): View
    {
        $standards = array_filter($catalog->all(), fn ($row) => $this->assetType === 'website' && $row['method'] !== 'expert_review'
            && ($this->platform === '' || ($row['platform'] ?? 'general') === $this->platform)
            && ($this->group === '' || $row['group'] === $this->group)
            && ($this->search === '' || mb_stripos($row['title'].' '.$row['criterion'], $this->search) !== false));

        return view('livewire.operator.library.website-standards-page', ['standards' => $standards, 'groups' => WebsiteStandardCatalog::GROUPS]);
    }
}
