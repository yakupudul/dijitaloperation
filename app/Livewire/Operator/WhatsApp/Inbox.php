<?php

namespace App\Livewire\Operator\WhatsApp;

use App\Jobs\WhatsApp\CheckWhatsAppConnection;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\Prospect;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppSignupAttempt;
use App\Models\WhatsAppWebhookReceipt;
use App\Services\Assistant\WhatsAppContactLinker;
use App\Services\WhatsApp\WhatsAppConnection;
use App\Services\WhatsApp\WhatsAppSignup;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

    public string $app_id = '';

    public string $signup_config_id = '1757572378897162';

    public string $signup_mode = 'coexistence';

    public string $q = '';

    public bool $showSettings = false;

    public string $notice = '';

    public string $waba_id = '';

    public string $phone_number_id = '';

    public string $business_phone = '';

    public bool $enabled = true;

    public bool $automatic_suggestions = false;

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
        $this->app_id = (string) ($config['app_id'] ?? '');
        $this->signup_config_id = (string) ($config['signup_config_id'] ?? '1757572378897162');
        $this->signup_mode = (string) ($config['signup_mode'] ?? 'coexistence');
        $this->enabled = $integration?->isActive() ?? true;
        $this->automatic_suggestions = (bool) ($config['automatic_suggestions'] ?? false);
        $this->showSettings = $integration === null;
        if ($integration === null) {
            $this->business_context = 'Moximu — Yakup Udül. Kurumsal web sitesi: tek seferlik 14.000 TL. Mobil uyumlu, yönetim panelli, işletmeye özel tasarım ve 1 yıl destek. KDV, domain, hosting, teslim tarihi ve ödeme planı ayrıca netleştirilmeli; dahil olduğu varsayılmamalı. Diğer hizmetlerin fiyatını uydurma. Kısa, samimi, profesyonel ve baskısız Türkçe yaz. Sırf cevap vermiş olmak için takip mesajı önerme.';
        }
    }

    public function saveSignupSetup(array $secrets, WhatsAppSignup $signup): bool
    {
        $this->resetValidation();
        $this->notice = '';
        try {
            $signup->saveSetup(auth()->user(), [
                'app_id' => trim($this->app_id), 'signup_config_id' => trim($this->signup_config_id),
                'signup_mode' => $this->signup_mode, 'app_secret' => $secrets['app_secret'] ?? '',
                'verify_token' => $secrets['verify_token'] ?? '',
            ]);
            $this->notice = 'Meta uygulama ayarları kaydedildi. WhatsApp hesabını bağla ile devam edin.';

            return true;
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return false;
        }
    }

    public function beginSignup(WhatsAppSignup $signup): void
    {
        $attempt = $signup->begin(auth()->user(), session()->getId());
        $this->redirectRoute('operator.whatsapp.connect', ['attempt' => $attempt->id]);
    }

    public function subscribeWebhook(WhatsAppSignup $signup): void
    {
        $attempt = $signup->begin(auth()->user(), session()->getId(), true);
        $signup->dispatch($attempt);
        $this->notice = 'WABA webhook aboneliği arka planda kuruluyor. Sonuç bu ekranda görünecek.';
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
            $this->notice = 'Ayarlar kaydedildi. Gizli bilgiler güvenle saklanıyor; boş bırakılan alanların kayıtlı değerleri korundu.';

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

    public function refreshConnectionStatus(): void
    {
        $this->notice = 'Kontrol sonucu yenilendi. Güncel durum aşağıdaki API kontrolü bölümünde.';
    }

    public function checkConnection(WhatsAppConnection $connection): void
    {
        $this->resetValidation();
        $this->notice = '';
        $integration = $connection->integration();
        if (! $integration?->isActive()) {
            $this->addError('connection', 'Önce bağlantı ayarlarını kaydedip mesaj alımını açın.');

            return;
        }
        $requestId = (string) Str::uuid();
        try {
            $queued = DB::transaction(function () use ($integration, $requestId): bool {
                $current = CoreIntegration::query()->lockForUpdate()->findOrFail($integration->id);
                $config = $current->config ?? [];
                app(WhatsAppSignup::class)->assertIdle($current);
                if (($config['connection_check'] ?? '') === 'queued'
                    && ! empty($config['connection_check_requested_at'])
                    && CarbonImmutable::parse($config['connection_check_requested_at'])->greaterThan(now()->subMinutes(2))) {
                    return false;
                }
                $config['connection_error'] = null;
                $config['connection_check'] = 'queued';
                $config['connection_check_request_id'] = $requestId;
                $config['connection_check_requested_at'] = now()->toIso8601String();
                $config['connection_checked_at'] = null;
                $current->update(['config' => $config]);

                return true;
            });
            if (! $queued) {
                $this->notice = 'Kontrol zaten sırada. Sonuç otomatik yenilenecek.';

                return;
            }
            CheckWhatsAppConnection::dispatch($integration->id, $requestId);
            $this->notice = 'Kayıtlı bilgilerle API kontrolü sıraya alındı. Sonuç otomatik yenilenecek.';
        } catch (ValidationException $exception) {
            $this->addError('connection', collect($exception->errors())->flatten()->first());
        } catch (Throwable $exception) {
            DB::transaction(function () use ($integration, $requestId): void {
                $current = CoreIntegration::query()->lockForUpdate()->find($integration->id);
                $config = $current?->config ?? [];
                if (($config['connection_check_request_id'] ?? null) === $requestId) {
                    $config['connection_check'] = 'dispatch_failed';
                    $current->update(['config' => $config]);
                }
            });
            $this->addError('connection', 'Kontrol sıraya alınamadı. Tekrar deneyin; sürerse kuyruk hizmetini kontrol edin.');
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

    public string $linkCustomer = '';

    public string $linkProspect = '';

    public string $followUpOn = '';

    public string $nextStep = '';

    /** Faz 6: link the selected conversation to a customer or prospect by hand (kept by the automatic linker). */
    public function saveLink(WhatsAppContactLinker $linker): void
    {
        $conversation = $this->selectedConversation();
        $linker->setManual($conversation, $this->linkCustomer !== '' ? (int) $this->linkCustomer : null, $this->linkProspect !== '' ? (int) $this->linkProspect : null);
        $this->notice = 'Görüşme bağlandı.';
    }

    public function createProspect(WhatsAppContactLinker $linker): void
    {
        $prospect = $linker->createProspect($this->selectedConversation(), auth()->id());
        $this->notice = $prospect->company_name.' aday olarak eklendi; yarın için takip tarihi kondu.';
    }

    public function saveFollowUp(): void
    {
        $conversation = $this->selectedConversation();
        abort_if($conversation->prospect_id === null, 422);
        $this->validate(['followUpOn' => ['nullable', 'date'], 'nextStep' => ['nullable', 'string', 'max:255']]);
        Prospect::query()->findOrFail($conversation->prospect_id)->forceFill([
            'next_follow_up_on' => $this->followUpOn !== '' ? $this->followUpOn : null, 'next_step' => trim($this->nextStep) ?: null,
        ])->save();
        $this->notice = 'Aday takibi kaydedildi; Bugün ekranında görünür.';
    }

    private function selectedConversation(): WhatsAppConversation
    {
        abort_if($this->conversation === null, 404);

        return WhatsAppConversation::query()->where('integration_id', app(WhatsAppConnection::class)->integration()?->id)->findOrFail($this->conversation);
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
            'signupAttempt' => WhatsAppSignupAttempt::query()->where('integration_id', $integration?->id)
                ->select(['id', 'user_id', 'status', 'step', 'mode', 'details', 'updated_at', 'expires_at'])
                ->orderByDesc('created_at')->first(),
            'linkedCustomer' => $selected?->customer_id ? Customer::query()->find($selected->customer_id) : null,
            'linkedProspect' => $selected?->prospect_id ? Prospect::query()->find($selected->prospect_id) : null,
            'customerOptions' => $selected ? Customer::query()->orderBy('name')->pluck('name', 'id') : collect(),
            'prospectOptions' => $selected ? Prospect::query()->whereNotIn('status', ['won', 'lost'])->orderBy('company_name')->pluck('company_name', 'id') : collect(),
            'receipts' => WhatsAppWebhookReceipt::query()->where('integration_id', $integration?->id)
                ->select(['id', 'status', 'accepted_count', 'ignored_count', 'error_code', 'created_at'])
                ->orderByDesc('id')->limit(10)->get(),
        ]);
    }
}
