<?php

namespace App\Livewire\Demo;

use App\Support\Operator\AgencySettingCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class LocaleSwitcher extends Component
{
    public string $locale = 'en';

    public function mount(): void
    {
        $user = Auth::user();
        $candidate = (string) ($user?->locale ?? app()->getLocale());
        $this->locale = AgencySettingCatalog::isLocale($candidate) ? $candidate : AgencySettingCatalog::LOCALE_EN;
    }

    public function setLocale(string $locale): void
    {
        if (! AgencySettingCatalog::isLocale($locale)) {
            return;
        }

        $user = Auth::user();
        if ($user) {
            $user->forceFill(['locale' => $locale])->save();
        }

        session(['locale' => $locale]);
        app()->setLocale($locale);
        $this->locale = $locale;

        $this->redirect(request()->header('Referer') ?: route('operator.dashboard'), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.demo.locale-switcher');
    }
}
