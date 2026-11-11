<?php

namespace App\Livewire\Operator\Settings;

use App\Models\SettingChange;
use App\Services\Operations\SettingChanges;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

/**
 * Ayarlar › Değişiklik kaydı (Admin, Bağlantı Düzeni 2.5): who changed which setting when, the value before and
 * after, and "Geri al" that puts the old value back.
 */
#[Layout('operator.layouts.app')]
#[Title('Değişiklik kaydı')]
final class SettingChangesPage extends Component
{
    public string $message = '';

    public function mount(): void
    {
        $this->authorizeAdmin();
    }

    public function undo(int $id, SettingChanges $changes): void
    {
        $this->authorizeAdmin();
        $change = SettingChange::query()->findOrFail($id);
        try {
            $changes->undo($change);
            $this->message = '"'.$change->label.'" eski değerine döndü.';
        } catch (Throwable $error) {
            $this->message = 'Geri alınamadı: '.$error->getMessage();
        }
    }

    public function render(): View
    {
        return view('livewire.operator.settings.setting-changes', [
            'changes' => SettingChange::query()->with('user:id,name')->latest('id')->limit(150)->get(),
        ]);
    }

    private function authorizeAdmin(): void
    {
        abort_unless(auth()->user()?->is_active && auth()->user()?->hasRole(Roles::ADMIN), 403);
    }
}
