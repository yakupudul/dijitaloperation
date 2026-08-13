<?php

namespace App\Livewire\Demo;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class LocaleSwitcher extends Component
{
    public string $locale = 'en';

    public function mount(): void
    {
        $user = Auth::user();
        $this->locale = in_array((string) ($user?->locale ?? 'en'), ['en', 'tr'], true)
            ? (string) $user->locale
            : app()->getLocale();
    }

    public function setLocale(string $locale): void
    {
        if (! in_array($locale, ['en', 'tr'], true)) {
            return;
        }

        $user = Auth::user();
        if ($user) {
            $user->forceFill(['locale' => $locale])->save();
        }

        session(['locale' => $locale]);
        app()->setLocale($locale);
        $this->locale = $locale;

        $this->redirect(request()->header('Referer') ?: route('demo.dashboard'), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.demo.locale-switcher');
    }
}
