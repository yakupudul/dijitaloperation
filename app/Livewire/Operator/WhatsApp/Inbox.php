<?php

namespace App\Livewire\Operator\WhatsApp;

use App\Jobs\WhatsApp\CheckWhatsAppConnection;
use App\Jobs\WhatsApp\GenerateWhatsAppSuggestion;
use App\Models\AgencySetting;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\WhatsAppBackupImport;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppSignupAttempt;
use App\Models\WhatsAppWebhookReceipt;
use App\Services\Assistant\WhatsAppContactLinker;
use App\Services\WhatsApp\Backup\WhatsAppBackupImporter;
use App\Services\WhatsApp\WhatsAppBrain;
use App\Services\WhatsApp\WhatsAppConnection;
use App\Services\WhatsApp\WhatsAppSignup;
use App\Services\WhatsApp\WhatsAppSuggestions;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
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

    public string $signup_config_id = '';

    public string $signup_mode = 'coexistence';

    public string $q = '';

    public bool $showSettings = false;

    /** The manual (own WhatsApp account) connection form is open in the settings. */
    public bool $manualOpen = false;

    public string $notice = '';

    public string $waba_id = '';

    public string $phone_number_id = '';

    public string $business_phone = '';

    public bool $enabled = true;

    public bool $automatic_suggestions = false;

    public string $business_context = '';

    /** Answers typed under "Senden istediklerim", by question index. */
    public array $answers = [];

    /** OpenAI model that drafts the reply suggestions (stored on the integration config). */
    public string $ai_model = '';

    /** KVKK: message texts older than this many days are blanked; empty = kept. */
    public string $retention_days = '';

    /** "Cevap üret" covers chats from this many days before the newest message, at most BULK_LIMIT per click. */
    private const BULK_DAYS = 30;

    private const BULK_LIMIT = 50;

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
        $this->signup_config_id = (string) ($config['signup_config_id'] ?? config('whatsapp.signup_config_id', ''));
        $this->signup_mode = (string) ($config['signup_mode'] ?? 'coexistence');
        $this->enabled = $integration?->isActive() ?? true;
        $this->automatic_suggestions = (bool) ($config['automatic_suggestions'] ?? false);
        $this->ai_model = WhatsAppSuggestions::model($integration);
        $days = AgencySetting::query()->value('whatsapp_retention_days');
        $this->retention_days = $days !== null ? (string) $days : '';
        // Opened from a link (?conversation=): prefill the customer field as a click in the list would.
        $linked = $this->conversation ? WhatsAppConversation::query()->where('integration_id', $integration?->id)->find($this->conversation)?->customer_id : null;
        $this->linkCustomer = $linked ? (string) $linked : '';
        $this->notice = (string) session('whatsapp_notice', '');
        if (trim($this->business_context) === '') {
            // Faz 12: no built-in prices or names; the operator writes the agency's own terms here.
            $agency = (string) (AgencySetting::query()->value('agency_name') ?? '');
            $this->business_context = ($agency !== '' ? $agency.'. ' : '').'Hizmetler ve fiyatlar: (buraya yazın). KDV, domain, hosting, teslim tarihi ve ödeme planı ayrıca netleştirilmeli; dahil olduğu varsayılmamalı. Burada yazmayan fiyatı uydurma. Kısa, samimi, profesyonel ve baskısız Türkçe yaz.';
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

    /** Own business (the Meta app owner's portfolio cannot be picked in the Meta popup): connect with IDs and a token. */
    public function openManual(): void
    {
        $this->showSettings = true;
        $this->manualOpen = true;
        $this->dispatch('wa-open-manual');
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

    /** Coexistence: the operator asks Meta for the phone's chat history (allowed within 24 hours of onboarding). */
    public function requestHistory(WhatsAppSignup $signup): void
    {
        $this->resetValidation();
        $this->notice = $signup->requestHistory(auth()->user())
            ? 'Meta\'dan geçmiş mesajlar istendi. Birkaç dakika içinde görüşme listesine gelmeye başlar.'
            : 'Meta geçmiş mesaj aktarımını başlatmadı; nedeni aşağıda yazıyor. 24 saat dolmadan tekrar deneyebilirsiniz.';
    }

    /** "Çıkar": the uploaded backup is opened with the 64-digit key in the background (the key never enters Livewire state). */
    public function extractBackup(string $key, WhatsAppBackupImporter $importer): bool
    {
        $this->resetValidation();
        try {
            $importer->extract(auth()->user(), $key);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return false;
        }
        $this->notice = 'Yedek çıkarılıyor. Büyük yedekler birkaç dakika sürer; bu sayfa kendiliğinden yenilenir.';

        return true;
    }

    /** Removes the saved backup key: the next backup asks for it again. */
    public function forgetBackupKey(WhatsAppBackupImporter $importer): void
    {
        $importer->forgetKey(auth()->user());
        $this->notice = 'Kayıtlı anahtar silindi; sonraki yedekte anahtar sorulur.';
    }

    /** Drops an uploaded backup that will not be extracted. */
    public function discardBackup(WhatsAppBackupImporter $importer): void
    {
        foreach (WhatsAppBackupImport::query()->whereIn('status', ['uploading', 'uploaded', 'failed'])->get() as $import) {
            $importer->discard($import);
        }
        $this->notice = 'Yüklenen yedek silindi.';
    }

    /** "Yeniden öğren": the brain reads the chats again. */
    public function learnBrain(WhatsAppBrain $brain): void
    {
        $this->resetValidation();
        try {
            $brain->request(auth()->user());
            $this->notice = 'Beyin görüşmeleri okuyor; birkaç dakika içinde burada görünür.';
        } catch (ValidationException $exception) {
            $this->addError('brain', collect($exception->errors())->flatten()->first());
        }
    }

    /** The operator's own instructions for the replies (prices, rules, what to say); they win over what was learned. */
    public function saveInstructions(WhatsAppConnection $connection): void
    {
        $this->resetValidation();
        $this->validate(['business_context' => ['required', 'string', 'max:12000']], [], ['business_context' => 'talimatlar']);
        $integration = $connection->integration() ?? CoreIntegration::query()->firstOrCreate(['provider' => WhatsAppConnection::PROVIDER], [
            'name' => 'WhatsApp Business', 'status' => CoreIntegration::STATUS_ACTIVE, 'config' => [],
        ]);
        DB::transaction(function () use ($integration): void {
            $current = CoreIntegration::query()->lockForUpdate()->findOrFail($integration->id);
            $config = $current->config ?? [];
            if (($config['business_context'] ?? '') !== $this->business_context) {
                $current->update(['config' => [...$config, 'business_context' => $this->business_context]]);
                WhatsAppConnection::contextChanged($current);
            }
        });
        $this->notice = 'Talimatlar kaydedildi. Bundan sonraki cevaplar bunlara göre hazırlanır.';
    }

    /** Settings › Bağlantıyı sıfırla: back to the first setup step; nothing is changed at Meta. */
    public function resetConnection(WhatsAppConnection $connection): void
    {
        $this->resetValidation();
        try {
            $connection->reset(auth()->user());
        } catch (ValidationException $exception) {
            $this->addError('connection', collect($exception->errors())->flatten()->first());

            return;
        }
        $this->fill([
            'app_id' => '', 'signup_config_id' => '', 'signup_mode' => 'coexistence', 'waba_id' => '', 'phone_number_id' => '',
            'business_phone' => '', 'enabled' => true, 'conversation' => null, 'linkCustomer' => '', 'q' => '',
            'showSettings' => false, 'manualOpen' => false,
        ]);
        $this->notice = 'WhatsApp bağlantısı sıfırlandı. 1. adımdaki Meta uygulama bilgilerini yeniden girin.';
    }

    public function updatedQ(): void
    {
        $this->q = mb_substr($this->q, 0, 100);
    }

    public function selectConversation(int $id, WhatsAppConnection $connection): void
    {
        $row = WhatsAppConversation::query()->where('integration_id', $connection->integration()?->id)->findOrFail($id);
        $this->conversation = $id;
        $this->resetPage('chatPage');
        // Prefill the link field from THIS conversation so a stale value from another one is never saved.
        $this->linkCustomer = $row->customer_id ? (string) $row->customer_id : '';
    }

    public function saveSettings(array $secrets, WhatsAppConnection $connection): bool
    {
        $this->resetValidation();
        $this->notice = '';
        try {
            $input = $this->only(['waba_id', 'phone_number_id', 'business_phone', 'enabled']);
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

    /**
     * Reply suggestions (OpenAI model, automatic drafting, the agency's service terms) and the KVKK message retention;
     * saved apart from the connection so changing the model never touches the Meta settings.
     */
    public function saveAiSettings(WhatsAppConnection $connection): void
    {
        $this->resetValidation();
        $this->notice = '';
        $this->validate([
            'ai_model' => ['required', 'string', 'in:'.implode(',', array_keys(WhatsAppSuggestions::modelOptions()))],
            'automatic_suggestions' => ['boolean'],
            'business_context' => ['required', 'string', 'max:12000'],
            'retention_days' => ['nullable', 'integer', 'min:30', 'max:3650'],
        ], [], ['ai_model' => 'yanıt önerisi modeli', 'business_context' => 'hizmetler ve fiyatlar', 'retention_days' => 'saklama süresi']);
        $integration = $connection->integration();
        if ($integration === null) {
            $this->addError('ai_model', 'Önce WhatsApp bağlantısını kurun.');

            return;
        }
        DB::transaction(function () use ($integration): void {
            $current = CoreIntegration::query()->lockForUpdate()->findOrFail($integration->id);
            $config = $current->config ?? [];
            $contextChanged = ($config['business_context'] ?? '') !== $this->business_context;
            $current->update(['config' => [...$config, 'ai_model' => $this->ai_model,
                'automatic_suggestions' => $this->automatic_suggestions, 'business_context' => $this->business_context]]);
            if ($contextChanged) {
                WhatsAppConnection::contextChanged($current);
            }
        });
        AgencySetting::query()->first()?->forceFill([
            'whatsapp_retention_days' => $this->retention_days !== '' ? (int) $this->retention_days : null,
        ])->save();
        $this->notice = 'Yanıt önerisi ayarları kaydedildi. Yeni öneriler '.$this->ai_model.' ile hazırlanır.';
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
            $this->notice = 'Öneri hazırlanıyor; birkaç saniye içinde burada görünür.';
        });
        // Right away through OpenAI; the minute tick picks it up if the queue is unreachable.
        try {
            GenerateWhatsAppSuggestion::dispatch($id);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * "Cevap üret" on the list: drafts for every chat whose last message is the customer's, from the last
     * BULK_DAYS days of the newest message (older chats are history, not waiting for an answer), newest first.
     */
    public function generateAll(WhatsAppConnection $connection): void
    {
        $integration = $connection->integration();
        if (! $integration?->isActive()) {
            $this->notice = 'Öneri için bağlantı etkin olmalı.';

            return;
        }
        $ids = $this->awaitingReply($integration->id)->limit(self::BULK_LIMIT)->pluck('id');
        if ($ids->isEmpty()) {
            $this->notice = 'Cevap bekleyen görüşme yok (son '.self::BULK_DAYS.' günde son mesajı müşteriden gelen ve cevabı hazır olmayan).';

            return;
        }
        WhatsAppConversation::query()->whereKey($ids)->whereNotIn('suggestion_status', ['requested', 'running'])
            ->update(['suggestion_status' => 'requested', 'error_code' => null, 'updated_at' => now()]);
        foreach ($ids as $id) {
            try {
                GenerateWhatsAppSuggestion::dispatch($id);
            } catch (Throwable $exception) {
                // The minute tick picks requested drafts up when the queue is unreachable.
                report($exception);
                break;
            }
        }
        $this->notice = $ids->count().' görüşmeye cevap hazırlanıyor; hazır oldukça listede "Öneri hazır" görünür.';
    }

    /** "Senden istediklerim": each answer is added to the instructions and its question leaves the list. */
    public function saveAnswers(WhatsAppConnection $connection): void
    {
        $this->resetValidation();
        $integration = $connection->integration();
        $questions = array_values((array) data_get($integration?->config, 'brain.open_questions', []));
        $answered = collect($this->answers)->map(fn ($answer) => trim((string) $answer))->filter()
            ->filter(fn (string $answer, $index): bool => isset($questions[(int) $index]));
        if ($integration === null || $answered->isEmpty()) {
            $this->addError('answers', 'En az bir soruya cevap yazın.');

            return;
        }
        if ($answered->contains(fn (string $answer): bool => mb_strlen($answer) > 2000)) {
            $this->addError('answers', 'Bir cevap en çok 2000 karakter olabilir.');

            return;
        }
        DB::transaction(function () use ($integration, $questions, $answered): void {
            $current = CoreIntegration::query()->lockForUpdate()->findOrFail($integration->id);
            $config = $current->config ?? [];
            $lines = $answered->map(fn (string $answer, $index): string => '- '.$questions[(int) $index].' → '.$answer)->implode("\n");
            $context = trim((string) ($config['business_context'] ?? ''));
            $config['business_context'] = mb_substr(trim($context."\n\n".$lines), 0, 12000);
            $remaining = array_values(array_diff_key($questions, $answered->keys()->mapWithKeys(fn ($index) => [(int) $index => true])->all()));
            data_set($config, 'brain.open_questions', $remaining);
            $current->update(['config' => $config]);
            WhatsAppConnection::contextChanged($current);
            $this->business_context = $config['business_context'];
        });
        $this->answers = [];
        $this->notice = 'Cevapların talimatlara eklendi. Bundan sonraki cevaplar bunlara göre hazırlanır.';
    }

    /** Chats whose last message is the customer's and that have no current draft, newest first. */
    private function awaitingReply(int $integrationId): Builder
    {
        $newest = WhatsAppConversation::query()->where('integration_id', $integrationId)->max('last_message_at');

        return WhatsAppConversation::query()->where('integration_id', $integrationId)
            ->whereNull('opted_out_at')->whereNotNull('last_incoming_at')
            ->whereColumn('last_incoming_at', '>=', 'last_message_at')
            ->when($newest, fn (Builder $query) => $query->where('last_message_at', '>=', Carbon::parse($newest)->subDays(self::BULK_DAYS)))
            ->whereNotIn('suggestion_status', ['requested', 'running'])
            ->where(fn (Builder $query) => $query->where('suggestion_status', '!=', 'ready')->orWhereNull('suggested_revision')
                ->orWhereColumn('suggested_revision', '<', 'revision'))
            ->orderByDesc('last_message_at')->orderByDesc('id');
    }

    public function retryReceipt(int $id, WhatsAppConnection $connection): void
    {
        WhatsAppWebhookReceipt::query()->where('integration_id', $connection->integration()?->id)
            ->whereKey($id)->where('status', 'failed')
            ->update(['status' => 'pending', 'error_code' => null, 'updated_at' => now()]);
        $this->notice = 'Mesaj aktarımı yeniden sıraya alındı.';
    }

    public string $linkCustomer = '';

    /** Link the selected conversation to a customer by hand (kept by the automatic linker). */
    public function saveLink(WhatsAppContactLinker $linker): void
    {
        $conversation = $this->selectedConversation();
        $customerId = $this->linkCustomer !== '' ? (int) $this->linkCustomer : null;
        if ($customerId !== null && ! Customer::query()->whereKey($customerId)->exists()) {
            $this->addError('linkCustomer', 'Seçilen müşteri bulunamadı.');

            return;
        }
        $linker->setManual($conversation, $customerId);
        $this->notice = $this->linkCustomer !== '' ? 'Görüşme müşteriye bağlandı.' : 'Görüşmenin müşteri bağlantısı kaldırıldı.';
    }

    /** The customer list in the conversation header saves on change. */
    public function updatedLinkCustomer(WhatsAppContactLinker $linker): void
    {
        if ($this->conversation !== null) {
            $this->saveLink($linker);
        }
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
        })->orderByDesc('last_message_at')->orderByDesc('id')->get();
        $selected = $this->conversation ? (clone $query)->find($this->conversation) : null;
        $messages = $selected?->messages()->orderByDesc('sent_at')->orderByDesc('id')->paginate(50, ['*'], 'chatPage');
        $attempt = WhatsAppSignupAttempt::query()->where('integration_id', $integration?->id)
            ->select(['id', 'user_id', 'session_hash', 'status', 'step', 'mode', 'details', 'trace', 'launched_at', 'updated_at', 'expires_at'])
            ->orderByDesc('created_at')->first();

        return view('livewire.operator.whatsapp.inbox', [
            'integration' => $integration, 'rows' => $rows, 'selected' => $selected, 'messages' => $messages,
            'state' => $connection->state($integration),
            'config' => $integration?->config ?? [],
            'credentialStatus' => $connection->credentialStatus($integration),
            'historyDeadline' => $connection->historyDeadline($integration),
            'signupAttempt' => $attempt,
            'backupImport' => WhatsAppBackupImport::query()->latest()->first(),
            'awaitingReply' => $integration ? min($this->awaitingReply($integration->id)->count(), self::BULK_LIMIT) : 0,
            'backupKeySaved' => filled(data_get($integration?->config, 'backup_key')),
            'backupConversations' => (clone $query)->where('phone_number_id', WhatsAppBackupImporter::LINE)->count(),
            'conversationCount' => $this->showSettings ? (clone $query)->count() : 0,
            // The connect page only opens in the session that started the attempt.
            'attemptOwned' => $attempt !== null && $attempt->user_id === auth()->id() && hash_equals($attempt->session_hash, hash('sha256', session()->getId())),
            'linkedCustomer' => $selected?->customer_id ? Customer::query()->find($selected->customer_id) : null,
            'customerOptions' => $selected ? Customer::query()->orderBy('name')->pluck('name', 'id') : collect(),
            'modelOptions' => WhatsAppSuggestions::modelOptions($this->ai_model),
            'receipts' => WhatsAppWebhookReceipt::query()->where('integration_id', $integration?->id)
                ->select(['id', 'status', 'accepted_count', 'ignored_count', 'error_code', 'created_at'])
                ->orderByDesc('id')->limit(10)->get(),
        ]);
    }
}
