<?php

namespace App\Livewire\Operator\WhatsApp;

use App\Jobs\WhatsApp\CheckWhatsAppConnection;
use App\Jobs\WhatsApp\GenerateWhatsAppSuggestion;
use App\Models\AgencySetting;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\WhatsAppBackupImport;
use App\Models\WhatsAppBackupLine;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppSignupAttempt;
use App\Models\WhatsAppWebhookReceipt;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Assistant\WhatsAppContactLinker;
use App\Services\Operator\AgencySettingService;
use App\Services\WhatsApp\Backup\WhatsAppBackupImporter;
use App\Services\WhatsApp\WhatsAppBrain;
use App\Services\WhatsApp\WhatsAppConnection;
use App\Services\WhatsApp\WhatsAppContactNames;
use App\Services\WhatsApp\WhatsAppSignup;
use App\Services\WhatsApp\WhatsAppSuggestions;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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

    /** inbox | brain | setup; empty picks the inbox once there are conversations, the setup before. */
    #[Url]
    public string $tab = '';

    /** Conversation list filter: open | waiting | ready | done | all. */
    #[Url]
    public string $filter = 'open';

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

    /**
     * "Çıkar": the uploaded backup is opened with the 64-digit key in the background (the key never enters Livewire
     * state). $line: an extra backup; none for the inbox's own.
     */
    public function extractBackup(string $key, WhatsAppBackupImporter $importer, ?int $line = null): bool
    {
        $this->resetValidation();
        try {
            $importer->extract(auth()->user(), $key, $line);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($line === null ? $field : $field.'.'.$line, $messages[0]);
            }

            return false;
        }
        $this->notice = 'Yedek çıkarılıyor. Büyük yedekler birkaç dakika sürer; bu sayfa kendiliğinden yenilenir.';

        return true;
    }

    /** "Rehberden isimleri al": names from the phone's address book (.vcf, reduced to names and numbers in the browser). */
    public function importContacts(string $vcard, WhatsAppConnection $connection, WhatsAppContactNames $names, ?int $line = null): void
    {
        $this->resetValidation();
        $integration = $line === null ? $connection->integration() : WhatsAppBackupLine::archive();
        $connection->authorize(auth()->user());
        if ($integration === null || ! str_contains(strtoupper($vcard), 'BEGIN:VCARD')) {
            $this->addError('contacts', 'Bu dosya rehber dosyası (.vcf) değil. Telefonda Kişiler › Dışa aktar ile .vcf dosyası oluşturun.');

            return;
        }
        $result = $names->apply($integration, $vcard);
        $this->notice = $result['matched'] > 0
            ? $result['contacts'].' rehber numarasından '.$result['matched'].' görüşmeye isim yazıldı.'
            : $result['contacts'].' rehber numarası okundu ama görüşmelerdeki numaralarla eşleşen olmadı.';
    }

    /** "Yeniden başlat": an extraction that has shown no progress for a few minutes is started again. */
    public function restartBackup(WhatsAppBackupImporter $importer): void
    {
        $this->resetValidation();
        try {
            $importer->restart(auth()->user());
        } catch (ValidationException $exception) {
            $this->addError('backup', $exception->errors()['backup'][0] ?? 'Yeniden başlatılamadı.');

            return;
        }
        $this->notice = 'Çıkarma yeniden başlatıldı. Daha önce aktarılan mesajlar atlanır, yalnız eksikler eklenir.';
    }

    /** Removes the saved backup key: the next backup asks for it again. */
    public function forgetBackupKey(WhatsAppBackupImporter $importer, ?int $line = null): void
    {
        $importer->forgetKey(auth()->user(), $line);
        $this->notice = 'Kayıtlı anahtar silindi; sonraki yedekte anahtar sorulur.';
    }

    /** "Göster": the saved key, handed to the browser only (never kept in the component's state). */
    public function revealBackupKey(WhatsAppBackupImporter $importer, WhatsAppConnection $connection, ?int $line = null): string
    {
        $connection->authorize(auth()->user());
        $key = $importer->savedKey($line);

        return $key === null ? '' : implode(' ', str_split($key, 4));
    }

    /** Drops an uploaded backup that will not be extracted. */
    public function discardBackup(WhatsAppBackupImporter $importer, ?int $line = null): void
    {
        foreach (WhatsAppBackupImporter::forLine(WhatsAppBackupImport::query(), $line)->whereIn('status', ['uploading', 'uploaded', 'failed'])->get() as $import) {
            $importer->discard($import);
        }
        $this->notice = 'Yüklenen yedek silindi.';
    }

    /** "Yedekten gelenleri temizle": the inbox is emptied of backup chats so one backup can fill it alone. */
    public function clearBackupInbox(WhatsAppBackupImporter $importer): void
    {
        $this->resetValidation();
        try {
            $removed = $importer->clearInbox(auth()->user());
        } catch (ValidationException $exception) {
            $this->addError('backup', $exception->errors()['backup'][0] ?? 'Temizlenemedi.');

            return;
        }
        $this->conversation = null;
        $this->notice = $removed.' görüşme silindi. Şimdi istediğiniz yedeği seçip kendi anahtarıyla çıkarın; gelen kutusunda yalnız o olur.';
    }

    /** "Yedekten Excel al" › "Excel hazırla": the uploaded file is read into its own Excel with the key typed for it. */
    public function extractExcel(string $key, WhatsAppBackupImporter $importer): bool
    {
        $this->resetValidation();
        try {
            $importer->extract(auth()->user(), $key, null, true);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field.'.excel', $messages[0]);
            }

            return false;
        }
        $this->notice = 'Excel hazırlanıyor. Büyük yedekler birkaç dakika sürer; bu sayfa kendiliğinden yenilenir.';

        return true;
    }

    /** Drops an Excel-only upload that will not be read. */
    public function discardExcelUpload(WhatsAppBackupImporter $importer): void
    {
        foreach (WhatsAppBackupImporter::forLine(WhatsAppBackupImport::query(), null, true)->whereIn('status', ['uploading', 'uploaded', 'failed'])->get() as $import) {
            $importer->discard($import);
        }
        $this->notice = 'Yüklenen yedek silindi.';
    }

    /** "Sil" on a backup's Excel in the list. */
    public function deleteBackupExcel(string $id, WhatsAppBackupImporter $importer): void
    {
        $importer->deleteExcel(auth()->user(), $id);
        $this->notice = 'Excel silindi.';
    }

    /** "Başka yedek ekle": a named extra backup; its file and key are added on its card. */
    public function createBackupLine(string $name, WhatsAppBackupImporter $importer): bool
    {
        $this->resetValidation();
        try {
            $line = $importer->createLine(auth()->user(), $name);
        } catch (ValidationException $exception) {
            $this->addError('line_name', $exception->errors()['line_name'][0] ?? 'Yedek eklenemedi.');

            return false;
        }
        $this->notice = '"'.$line->name.'" eklendi. Şimdi yedek dosyasını seçin.';

        return true;
    }

    /** "Yedeği sil": an extra backup with its chats, file and key; the inbox's own backup is not touched. */
    public function deleteBackupLine(int $line, WhatsAppBackupImporter $importer): void
    {
        $this->resetValidation();
        try {
            $importer->deleteLine(auth()->user(), $line);
        } catch (ValidationException $exception) {
            $this->addError('backup.'.$line, $exception->errors()['backup'][0] ?? 'Silinemedi.');

            return;
        }
        $this->notice = 'Yedek, görüşmeleri ve anahtarıyla silindi.';
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
        app(AgencySettingService::class)->forget();
        $this->notice = 'Yanıt önerisi ayarları kaydedildi. Yeni öneriler '.(AiTaskQueue::takesAll() ? 'Claude kuyruğunda' : $this->ai_model.' ile').' hazırlanır.';
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
        return $this->waiting($integrationId)
            ->whereNotIn('suggestion_status', ['requested', 'running'])
            ->where(fn (Builder $query) => $query->where('suggestion_status', '!=', 'ready')->orWhereNull('suggested_revision')
                ->orWhereColumn('suggested_revision', '<', 'revision'))
            ->orderByDesc('last_message_at')->orderByDesc('id');
    }

    /** Open chats whose last message is the customer's, from the last BULK_DAYS days of the newest message. */
    private function waiting(int $integrationId): Builder
    {
        $newest = WhatsAppConversation::query()->where('integration_id', $integrationId)->max('last_message_at');

        return WhatsAppConversation::query()->where('integration_id', $integrationId)->whereNull('done_at')
            ->whereNull('opted_out_at')->whereNotNull('last_incoming_at')
            ->whereColumn('last_incoming_at', '>=', 'last_message_at')
            ->when($newest, fn (Builder $query) => $query->where('last_message_at', '>=', Carbon::parse($newest)->subDays(self::BULK_DAYS)));
    }

    /** The list for the chosen filter. */
    private function filtered(int $integrationId, string $filter): Builder
    {
        $base = WhatsAppConversation::query()->where('integration_id', $integrationId);

        return match ($filter) {
            'waiting' => $this->waiting($integrationId),
            'ready' => $base->whereNull('done_at')->where('suggestion_status', 'ready')->whereColumn('suggested_revision', 'revision'),
            'done' => $base->whereNotNull('done_at'),
            'all' => $base,
            default => $base->whereNull('done_at'),
        };
    }

    /** "Tamamlandı": answered from the phone; the chat leaves the open list until the customer writes again. */
    public function markDone(int $id, WhatsAppConnection $connection, bool $next = false): void
    {
        $integrationId = $connection->integration()?->id;
        $row = WhatsAppConversation::query()->where('integration_id', $integrationId)->findOrFail($id);
        $row->update(['done_at' => now()]);
        if (! $next) {
            $this->notice = 'Görüşme tamamlandı; müşteri yeniden yazarsa açık listeye döner.';

            return;
        }
        // "Tamamla ve sıradaki": the next chat with a ready draft, else the next one waiting for an answer.
        $following = $this->filtered($integrationId, 'ready')->orderByDesc('last_message_at')->orderByDesc('id')->first()
            ?? $this->waiting($integrationId)->orderByDesc('last_message_at')->orderByDesc('id')->first();
        if ($following === null) {
            $this->conversation = null;
            $this->notice = 'Görüşme tamamlandı. Cevap bekleyen başka görüşme yok.';

            return;
        }
        $this->selectConversation($following->id, $connection);
        $this->notice = 'Görüşme tamamlandı; sıradaki açıldı.';
    }

    public function reopen(int $id, WhatsAppConnection $connection): void
    {
        WhatsAppConversation::query()->where('integration_id', $connection->integration()?->id)->findOrFail($id)->update(['done_at' => null]);
        $this->notice = 'Görüşme yeniden açıldı.';
    }

    public function updatedFilter(): void
    {
        if (! in_array($this->filter, ['open', 'waiting', 'ready', 'done', 'all'], true)) {
            $this->filter = 'open';
        }
    }

    /**
     * The last message of each listed chat for the list preview ("Siz: …" for outgoing).
     *
     * @param  list<int>  $ids
     * @return array<int, array{text: string, outgoing: bool}>
     */
    private function previews(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $latest = WhatsAppMessage::query()->whereIn('conversation_id', $ids)->selectRaw('max(id) as id')->groupBy('conversation_id')->pluck('id');

        return WhatsAppMessage::query()->whereKey($latest)->get(['id', 'conversation_id', 'direction', 'body'])
            ->mapWithKeys(fn (WhatsAppMessage $message): array => [$message->conversation_id => [
                'text' => mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $message->body)), 0, 90),
                'outgoing' => $message->direction === 'outgoing',
            ]])->all();
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

    /** Jobs waiting on the heavy queue the extraction runs on (null when the queue cannot be read). */
    private function heavyQueueSize(): ?int
    {
        try {
            return Queue::connection('redis')->size('heavy');
        } catch (Throwable) {
            return null;
        }
    }

    /** The last four characters of a saved key, the rest hidden: enough to tell two keys apart. */
    private static function keyHint(?string $key): ?string
    {
        return $key === null ? null : '•••• •••• … '.substr($key, -4);
    }

    /**
     * The extra backups for their cards: latest upload, last extracted file, key and number of chats.
     *
     * @return list<array{line: WhatsAppBackupLine, import: ?WhatsAppBackupImport, current: array{import: ?WhatsAppBackupImport, file_kept: bool}, key_hint: ?string, conversations: int}>
     */
    private function backupLines(WhatsAppBackupImporter $importer): array
    {
        $archive = WhatsAppBackupLine::archiveId();
        $counts = $archive === null ? collect() : WhatsAppConversation::query()->where('integration_id', $archive)
            ->selectRaw('phone_number_id, count(*) as total')->groupBy('phone_number_id')->pluck('total', 'phone_number_id');

        return WhatsAppBackupLine::query()->orderBy('id')->get()->map(fn (WhatsAppBackupLine $line): array => [
            'line' => $line,
            'import' => $line->imports()->whereIn('status', ['queued', 'running'])->latest()->first() ?? $line->imports()->latest()->first(),
            'current' => $importer->current($line->id),
            'key_hint' => self::keyHint($importer->savedKey($line->id)),
            'conversations' => (int) ($counts[$line->conversationLine()] ?? 0),
        ])->all();
    }

    public function render(WhatsAppConnection $connection): View
    {
        $integration = $connection->integration();
        $query = WhatsAppConversation::query()->where('integration_id', $integration?->id);
        $this->updatedFilter();
        $importer = app(WhatsAppBackupImporter::class);
        if (WhatsAppBackupImport::query()->whereIn('status', ['queued', 'running'])->exists()) {
            // An extraction cut off without its failure hook would otherwise show "çıkarılıyor" for good.
            $importer->recoverStalled();
        }
        // The inbox's own backup; a running extraction first: a completed one created in the same second must not hide it.
        $backupImport = WhatsAppBackupImporter::forLine(WhatsAppBackupImport::query(), null)->whereIn('status', ['queued', 'running'])->latest()->first()
            ?? WhatsAppBackupImporter::forLine(WhatsAppBackupImport::query(), null)->latest()->first();
        $rows = ($integration ? $this->filtered($integration->id, $this->filter) : (clone $query))->when(trim($this->q) !== '', function ($builder): void {
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
            'previews' => $this->previews($rows->pluck('id')->all()),
            'filterCounts' => $integration ? collect(['open', 'waiting', 'ready', 'done', 'all'])
                ->mapWithKeys(fn (string $filter): array => [$filter => $this->filtered($integration->id, $filter)->count()])->all() : [],
            'totalConversations' => (clone $query)->count(),
            'state' => $connection->state($integration),
            'config' => $integration?->config ?? [],
            'credentialStatus' => $connection->credentialStatus($integration),
            'historyDeadline' => $connection->historyDeadline($integration),
            'signupAttempt' => $attempt,
            'backupImport' => $backupImport,
            'backupQueueAhead' => $backupImport?->status === 'queued' ? $this->heavyQueueSize() : null,
            'awaitingReply' => $integration ? min($this->awaitingReply($integration->id)->count(), self::BULK_LIMIT) : 0,
            'backupKeySaved' => filled(data_get($integration?->config, 'backup_key')),
            'backupKeyHint' => self::keyHint($importer->savedKey()),
            'backupCurrent' => $importer->current(null),
            'backupLines' => $this->backupLines($importer),
            'excelImport' => WhatsAppBackupImporter::forLine(WhatsAppBackupImport::query(), null, true)->whereIn('status', ['queued', 'running'])->latest()->first()
                ?? WhatsAppBackupImporter::forLine(WhatsAppBackupImport::query(), null, true)->latest()->first(),
            'backupExcels' => WhatsAppBackupImport::query()->with('line:id,name')->where('status', 'completed')->latest('finished_at')->limit(30)->get()
                ->filter(fn (WhatsAppBackupImport $import): bool => is_file($import->excelPath()))->values(),
            'anyBackupBusy' => WhatsAppBackupImport::query()->whereIn('status', ['queued', 'running'])->exists(),
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
