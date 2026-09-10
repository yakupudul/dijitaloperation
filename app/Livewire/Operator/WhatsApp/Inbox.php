<?php

namespace App\Livewire\Operator\WhatsApp;

use App\Jobs\WhatsApp\CheckWhatsAppConnection;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppWebhookReceipt;
use App\Services\WhatsApp\WhatsAppConnection;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

#[Layout('operator.layouts.app')]
#[Title('WhatsApp Asistanı')]
class Inbox extends Component
{
    use WithPagination;

    #[Url]
    public ?int $conversation = null;

    public string $q = '';
    public bool $showSettings = false;
    public string $notice = '';
    public string $waba_id = '';
    public string $phone_number_id = '';
    public string $business_phone = '';
    public bool $enabled = true;
    public bool $automatic_suggestions = true;
    public string $business_context = '';

    public function boot(WhatsAppConnection $connection): void
    {
        $connection->authorize(auth()->user());
    }

    public function mount(WhatsAppConnection $connection): void
    {
        $integration = $connection->integration();
        $config = $integration?->config ?? [];
        foreach (['waba_id', 'phone_number_id', 'business_phone', 'business_context'] as $key) {
            $this->{$key} = (string) ($config[$key] ?? '');
        }
        $this->enabled = $integration?->isActive() ?? true;
        $this->automatic_suggestions = (bool) ($config['automatic_suggestions'] ?? true);
        $this->showSettings = $integration === null;
        if ($integration === null) {
            $this->business_context = 'Moximu — Yakup Udül. Kurumsal web sitesi: tek seferlik 14.000 TL. Mobil uyumlu, yönetim panelli, işletmeye özel tasarım ve 1 yıl destek. KDV, domain, hosting, teslim tarihi ve ödeme planı ayrıca netleştirilmeli; dahil olduğu varsayılmamalı. Diğer hizmetlerin fiyatını uydurma. Kısa, samimi, profesyonel ve baskısız Türkçe yaz. Sırf cevap vermiş olmak için takip mesajı önerme.';
        }
    }

    public function updatedQ(): void
    {
        $this->q = mb_substr($this->q, 0, 100);
        $this->resetPage('conversationsPage');
    }

    public function selectConversation(int $id, WhatsAppConnection $connection): void
    {
        WhatsAppConversation::query()->where('integration_id', $connection->integration()?->id)->findOrFail($id);
        $this->conversation = $id;
        $this->resetPage('chatPage');
    }

    public function saveSettings(array $secrets, WhatsAppConnection $connection): bool
    {
        $this->resetValidation();
        $this->notice = '';
        try {
            $input = $this->only([
                'waba_id', 'phone_number_id', 'business_phone',
                'business_context', 'enabled', 'automatic_suggestions',
            ]);
            foreach (['access_token', 'app_secret', 'verify_token'] as $key) {
                $input[$key] = $secrets[$key] ?? '';
            }
            $connection->save(auth()->user(), $input);
            $this->notice = 'Ayarlar kaydedildi. Gizli alanlar temizlendi; kayıtlı değerler korunuyor.';

            return true;
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError($field, $message);
                }
            }

            return false;
        }
    }

    public function checkConnection(WhatsAppConnection $connection): void
    {
        $integration = $connection->integration();
        if (! $integration?->isActive()) {
            $this->notice = 'Önce bağlantı ayarlarını kaydedip mesaj alımını açın.';
            return;
        }
        try {
            CheckWhatsAppConnection::dispatch($integration->id);
            $this->notice = 'Bağlantı kontrolü sıraya alındı. Sonuç bağlantı ayarlarında görünecek.';
        } catch (Throwable $exception) {
            $this->notice = 'Kontrol sıraya alınamadı. Kuyruk hizmetini kontrol edin.';
        }
    }

    public function generate(int $id, WhatsAppConnection $connection): void
    {
        $integration = $connection->integration();
        if (! $integration?->isActive()) {
            $this->notice = 'Öneri için bağlantı etkin olmalı.';
            return;
        }
        DB::transaction(function () use ($id, $integration): void {
            $row = WhatsAppConversation::query()->where('integration_id', $integration->id)->lockForUpdate()->findOrFail($id);
            if ($row->suggestion_status === 'running') {
                $this->notice = 'Bu görüşme için öneri hazırlanıyor.';
                return;
            }
            $row->update(['suggestion_status' => 'requested', 'error_code' => null]);
            $this->notice = 'Öneri sıraya alındı. Bu ekranı açık tutmanız gerekmez.';
        });
    }

    public function retryReceipt(int $id, WhatsAppConnection $connection): void
    {
        WhatsAppWebhookReceipt::query()->where('integration_id', $connection->integration()?->id)
            ->whereKey($id)->where('status', 'failed')
            ->update(['status' => 'pending', 'error_code' => null, 'updated_at' => now()]);
        $this->notice = 'Mesaj aktarımı yeniden sıraya alındı.';
    }

    public function render(WhatsAppConnection $connection): View
    {
        $integration = $connection->integration();
        $query = WhatsAppConversation::query()->where('integration_id', $integration?->id);
        $rows = (clone $query)->when(trim($this->q) !== '', function ($builder): void {
            $builder->where(function ($nested): void {
                $term = '%'.mb_substr(trim($this->q), 0, 100).'%';
                $nested->where('contact_name', 'like', $term)->orWhere('contact_id', 'like', $term);
            });
        })->orderByDesc('last_message_at')->orderByDesc('id')->paginate(20, ['*'], 'conversationsPage');
        $selected = $this->conversation ? (clone $query)->find($this->conversation) : null;
        $messages = $selected?->messages()->orderByDesc('sent_at')->orderByDesc('id')->paginate(50, ['*'], 'chatPage');

        return view('livewire.operator.whatsapp.inbox', [
            'integration' => $integration, 'rows' => $rows, 'selected' => $selected, 'messages' => $messages,
            'config' => $integration?->config ?? [],
            'credentialStatus' => $connection->credentialStatus($integration),
            'receipts' => WhatsAppWebhookReceipt::query()->where('integration_id', $integration?->id)
                ->select(['id', 'status', 'accepted_count', 'ignored_count', 'error_code', 'created_at'])
                ->orderByDesc('id')->limit(10)->get(),
        ]);
    }
}
