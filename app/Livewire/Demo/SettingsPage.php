<?php

namespace App\Livewire\Demo;

use App\Support\Demo\DemoState;
use App\Support\Demo\GlobalOperatingFixtures;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Settings')]
class SettingsPage extends Component
{
    #[Url(as: 'section', history: true)]
    public string $section = 'general';

    public function mount(): void
    {
        $ids = array_column(GlobalOperatingFixtures::settingsSections(), 'id');
        if (! in_array($this->section, $ids, true)) {
            $this->section = 'general';
        }
    }

    public function setSection(string $section): void
    {
        $ids = array_column(GlobalOperatingFixtures::settingsSections(), 'id');
        if (in_array($section, $ids, true)) {
            $this->section = $section;
        }
    }

    public function resetDemo(): void
    {
        DemoState::reset();
        DemoState::flash('Demo Mode reset to seed state.');
    }

    public function render(): View
    {
        return view('livewire.demo.settings', [
            'sections' => GlobalOperatingFixtures::settingsSections(),
            'settings' => GlobalOperatingFixtures::settingsPayload(),
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
