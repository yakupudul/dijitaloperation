<?php

namespace App\Livewire\Operator\Settings;

use App\Models\AgencySetting;
use App\Services\Assistant\PushNotifier;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Ayarlar › Telefon bildirimleri (admin): ntfy topic URL + optional token, Telegram bot token + chat id, the
 * minimum severity that reaches the phone, a test button and the last sent messages. Secrets are stored
 * encrypted and never shown again.
 */
#[Layout('operator.layouts.app')]
#[Title('Telefon bildirimleri')]
final class PushSettingsPage extends Component
{
    public string $ntfyUrl = '';

    public string $ntfyToken = '';

    public string $telegramToken = '';

    public string $telegramChatId = '';

    public string $minSeverity = 'high';

    public string $message = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
        $settings = AgencySetting::query()->first();
        $this->ntfyUrl = (string) ($settings?->push_ntfy_url ?? '');
        $this->telegramChatId = (string) ($settings?->push_telegram_chat_id ?? '');
        $this->minSeverity = (string) ($settings?->push_min_severity ?: 'high');
    }

    public function save(): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
        $this->validate([
            'ntfyUrl' => ['nullable', 'url:https', 'max:500'],
            'telegramChatId' => ['nullable', 'string', 'max:64', 'regex:/^-?\d+$|^@[\w]+$/'],
            'minSeverity' => ['required', 'in:info,medium,high,critical'],
        ]);
        $settings = AgencySetting::query()->firstOrCreate([]);
        $settings->push_ntfy_url = $this->ntfyUrl !== '' ? $this->ntfyUrl : null;
        $settings->push_telegram_chat_id = $this->telegramChatId !== '' ? $this->telegramChatId : null;
        $settings->push_min_severity = $this->minSeverity;
        if ($this->ntfyToken !== '') {
            $settings->push_ntfy_token = $this->ntfyToken;
        }
        if ($this->telegramToken !== '') {
            $settings->push_telegram_bot_token = $this->telegramToken;
        }
        $settings->save();
        $this->reset('ntfyToken', 'telegramToken');
        $this->message = 'Kaydedildi.';
    }

    public function clearSecrets(): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
        AgencySetting::query()->first()?->forceFill(['push_ntfy_token' => null, 'push_telegram_bot_token' => null])->save();
        $this->message = 'Gizli anahtarlar silindi.';
    }

    public function test(PushNotifier $push): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
        $sent = $push->send('test:'.now()->timestamp, 'MoxDOP deneme bildirimi', 'Bu mesaj geldiyse telefon bildirimleri çalışıyor.', 'critical', null, 0, true);
        $this->message = $push->channels() === [] ? 'Önce ntfy veya Telegram ayarını kaydedin.' : sprintf('%d kanala gönderildi. Gelmediyse aşağıdaki kayıttaki hataya bakın.', $sent);
    }

    public function render(PushNotifier $push): View
    {
        $settings = AgencySetting::query()->first();

        return view('livewire.operator.settings.push-settings', [
            'channels' => $push->channels(),
            'hasNtfyToken' => filled($settings?->push_ntfy_token),
            'hasTelegramToken' => filled($settings?->push_telegram_bot_token),
            'log' => DB::table('push_notifications')->orderByDesc('id')->limit(20)->get(),
        ]);
    }
}
