<?php

namespace App\Livewire\Operator\Social;

use App\Models\AgencySetting;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\SocialThread;
use App\Models\User;
use App\Services\Social\SocialInbox;
use App\Services\Social\SocialMetaConnector;
use App\Services\Social\SocialPlanner;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

/**
 * Sosyal medya (`/sosyal`, ADR-083): the agency's own Instagram, Facebook and LinkedIn. "Plan" shows the coming weeks
 * as cards with the visual, text, channels and time; the Admin approves a whole week with one click, or edits / skips
 * one post. Approved posts go out at their time; LinkedIn waits as "copy and open" until its API access exists.
 * "Hesaplar" connects Meta and adds the LinkedIn page; "Yeni gönderi" uploads a post by hand.
 */
#[Layout('operator.layouts.app')]
#[Title('Sosyal medya')]
final class SocialPage extends Component
{
    use WithFileUploads;

    #[Url(as: 'sekme')]
    public string $tab = 'plan';

    public string $message = '';

    public ?int $editing = null;

    public string $editCaption = '';

    public string $editLinkedin = '';

    public string $editTime = '';

    // Yeni gönderi
    public string $newKind = 'image';

    public string $newPillar = '';

    public string $newTitle = '';

    public string $newCaption = '';

    public string $newLinkedin = '';

    public string $newTime = '';

    /** @var array<int, TemporaryUploadedFile> */
    public array $newFiles = [];

    /** @var array<int, string> thread id => reply text being edited */
    public array $replies = [];

    public string $inboxFilter = 'bekleyen';

    // Hesaplar
    public string $configId = '';

    public string $linkedinName = 'Moximu';

    public string $linkedinUrl = 'https://www.linkedin.com/company/moximu/';

    public function mount(): void
    {
        $this->configId = (string) (SocialMetaConnector::configId() ?? '');
        $this->newTime = now(SocialPlanner::TIMEZONE)->addDay()->setTime(11, 30)->format('Y-m-d\TH:i');
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['plan', 'mesajlar', 'gecmis', 'yeni', 'hesaplar'], true) ? $tab : 'plan';
        $this->message = '';
    }

    public function approveWeek(string $weekStart): void
    {
        $this->authorizeAdmin();
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekStart) !== 1) {
            $this->message = 'Hafta okunamadı.';

            return;
        }
        $start = Carbon::createFromFormat('Y-m-d', $weekStart, SocialPlanner::TIMEZONE)->startOfDay();
        $posts = SocialPost::query()->where('status', SocialPost::DRAFT)
            ->whereBetween('publish_at', [$start->copy()->utc(), $start->copy()->addWeek()->utc()])->get();
        foreach ($posts as $post) {
            app(SocialPlanner::class)->approve($post, (int) auth()->id());
        }
        $this->message = $posts->count().' gönderi onaylandı; her biri kendi saatinde yayınlanır.';
    }

    public function approve(int $id): void
    {
        $this->authorizeAdmin();
        app(SocialPlanner::class)->approve($this->post($id), (int) auth()->id());
        $this->message = 'Onaylandı.';
    }

    public function unapprove(int $id): void
    {
        $this->authorizeAdmin();
        app(SocialPlanner::class)->unapprove($this->post($id));
        $this->message = 'Onay geri alındı.';
    }

    public function skip(int $id): void
    {
        $this->authorizeAdmin();
        app(SocialPlanner::class)->skip($this->post($id));
        $this->message = 'Gönderi atlandı.';
    }

    public function retry(int $id): void
    {
        $this->authorizeAdmin();
        app(SocialPlanner::class)->retry($this->post($id));
        $this->message = 'Yeniden denenecek (5 dakika içinde).';
    }

    public function remove(int $id): void
    {
        $this->authorizeAdmin();
        try {
            app(SocialPlanner::class)->delete($this->post($id));
            $this->message = 'Gönderi silindi.';
        } catch (Throwable $e) {
            $this->message = $e->getMessage();
        }
    }

    public function markDone(int $targetId): void
    {
        $this->authorizeAdmin();
        $target = SocialPostTarget::query()->findOrFail($targetId);
        app(SocialPlanner::class)->markManualDone($target);
        $this->message = 'LinkedIn paylaşımı işaretlendi.';
    }

    public function edit(int $id): void
    {
        $post = $this->post($id);
        $this->editing = $post->id;
        $this->editCaption = (string) $post->caption;
        $this->editLinkedin = (string) $post->linkedin_text;
        $this->editTime = $post->publish_at->timezone(SocialPlanner::TIMEZONE)->format('Y-m-d\TH:i');
    }

    public function cancelEdit(): void
    {
        $this->editing = null;
    }

    public function saveEdit(): void
    {
        $this->authorizeAdmin();
        if ($this->editing === null) {
            return;
        }
        try {
            app(SocialPlanner::class)->edit($this->post($this->editing), [
                'caption' => $this->editCaption, 'linkedin_text' => $this->editLinkedin, 'publish_at' => $this->editTime,
            ]);
            $this->editing = null;
            $this->message = 'Kaydedildi.';
        } catch (Throwable $e) {
            $this->message = $e->getMessage();
        }
    }

    public function createPost(): void
    {
        $this->authorizeAdmin();
        $this->validate([
            'newKind' => ['required', 'in:'.implode(',', SocialPost::KINDS)],
            'newFiles' => ['required', 'array', 'min:1', 'max:10'],
            'newFiles.*' => ['file', 'mimes:jpg,jpeg,png,webp,mp4,mov', 'max:102400'],
            'newTime' => ['required', 'date'],
            'newCaption' => ['nullable', 'string', 'max:2200'],
            'newLinkedin' => ['nullable', 'string', 'max:3000'],
        ], [], ['newFiles' => 'dosyalar', 'newTime' => 'yayın zamanı']);
        try {
            $files = array_map(fn (TemporaryUploadedFile $f): array => ['bytes' => (string) $f->get(), 'name' => 'dosya.'.strtolower($f->getClientOriginalExtension() ?: $f->extension())], $this->newFiles);
            app(SocialPlanner::class)->create([
                'kind' => $this->newKind, 'pillar' => $this->newPillar ?: null, 'title' => $this->newTitle ?: null,
                'caption' => $this->newCaption, 'linkedin_text' => $this->newLinkedin, 'publish_at' => $this->newTime,
            ], $files);
            $this->reset(['newFiles', 'newCaption', 'newLinkedin', 'newTitle', 'newPillar']);
            $this->tab = 'plan';
            $this->message = 'Gönderi plana eklendi; onayınızı bekliyor.';
        } catch (Throwable $e) {
            $this->message = $e->getMessage();
        }
    }

    public function sendReply(int $id): void
    {
        $this->authorizeAdmin();
        $thread = SocialThread::query()->with('account')->findOrFail($id);
        $text = trim((string) ($this->replies[$id] ?? $thread->draft ?? ''));
        try {
            app(SocialInbox::class)->send($thread, $text);
            unset($this->replies[$id]);
            $this->message = 'Cevap gönderildi.';
        } catch (Throwable $e) {
            $thread->forceFill(['send_error' => mb_substr($e->getMessage(), 0, 480)])->save();
            $this->message = 'Gönderilemedi: '.$e->getMessage();
        }
    }

    public function sendAll(): void
    {
        $this->authorizeAdmin();
        // Texts edited on the page are kept as the draft first.
        foreach ($this->replies as $id => $text) {
            SocialThread::query()->whereKey((int) $id)->where('status', SocialThread::OPEN)->whereNotNull('draft')
                ->update(['draft' => trim((string) $text)]);
        }
        $this->replies = [];
        $result = app(SocialInbox::class)->sendAllReady();
        $this->message = $result['sent'].' cevap gönderildi'.($result['failed'] > 0 ? ', '.$result['failed'].' gönderilemedi (kartta sebebi yazıyor).' : '.');
    }

    public function ignoreThread(int $id): void
    {
        $this->authorizeAdmin();
        SocialThread::query()->whereKey($id)->update(['status' => SocialThread::IGNORED, 'draft_status' => null]);
        $this->message = 'Cevapsız bırakıldı.';
    }

    public function reopenThread(int $id): void
    {
        $this->authorizeAdmin();
        $thread = SocialThread::query()->findOrFail($id);
        $thread->forceFill(['status' => SocialThread::OPEN])->save();
        SocialInbox::queueDraft($thread);
        $this->message = 'Yeniden açıldı; cevap yazılıyor.';
    }

    public function redraft(int $id): void
    {
        $this->authorizeAdmin();
        $thread = SocialThread::query()->findOrFail($id);
        unset($this->replies[$id]);
        $thread->forceFill(['draft' => null])->save();
        SocialInbox::queueDraft($thread);
        $this->message = 'Cevap yeniden yazılıyor.';
    }

    public function makeLead(int $id): void
    {
        $this->authorizeAdmin();
        $lead = app(SocialInbox::class)->toLead(SocialThread::query()->with('account')->findOrFail($id));
        $this->message = 'Satış › Adaylar\'da kart açıldı: '.$lead->title().'.';
    }

    public function syncInbox(): void
    {
        $this->authorizeAdmin();
        $stats = app(SocialInbox::class)->sync();
        $this->message = 'Okundu: '.$stats['messages'].' yeni mesaj'.($stats['errors'] !== [] ? ' · '.implode(' | ', $stats['errors']) : '.');
    }

    public function saveConfigId(): void
    {
        $this->authorizeAdmin();
        $value = preg_replace('/\D/', '', $this->configId) ?? '';
        $setting = AgencySetting::query()->first() ?? new AgencySetting;
        $setting->forceFill(['social_meta_config_id' => $value !== '' ? $value : null])->save();
        $this->configId = $value;
        $this->message = $value !== '' ? 'Yapılandırma kimliği kaydedildi.' : 'Yapılandırma kimliği temizlendi; giriş izin listesiyle açılır.';
    }

    public function addLinkedin(): void
    {
        $this->authorizeAdmin();
        $this->validate(['linkedinName' => ['required', 'string', 'max:120'], 'linkedinUrl' => ['required', 'url', 'starts_with:https://www.linkedin.com/']]);
        $slug = trim((string) parse_url($this->linkedinUrl, PHP_URL_PATH), '/');
        SocialAccount::query()->updateOrCreate(
            ['platform' => SocialAccount::LINKEDIN, 'external_id' => $slug],
            ['name' => $this->linkedinName, 'meta' => ['url' => $this->linkedinUrl], 'connected_at' => now(), 'enabled' => true,
                'brand_id' => SocialAccount::query()->whereNotNull('brand_id')->value('brand_id')],
        );
        app(SocialPlanner::class)->syncUpcoming();
        $this->message = 'LinkedIn sayfası eklendi. Gönderiler saatinde "kopyala ve aç" olarak burada belirir.';
    }

    public function toggleAccount(int $id): void
    {
        $this->authorizeAdmin();
        $account = SocialAccount::query()->findOrFail($id);
        $account->update(['enabled' => ! $account->enabled]);
        app(SocialPlanner::class)->syncUpcoming();
    }

    public function render(): View
    {
        $tz = SocialPlanner::TIMEZONE;
        $upcoming = SocialPost::query()
            ->whereIn('status', [SocialPost::DRAFT, SocialPost::APPROVED, SocialPost::PARTIAL, SocialPost::FAILED])
            ->with(['targets.account'])->orderBy('publish_at')->limit(120)->get();
        $weeks = $upcoming->groupBy(fn (SocialPost $p): string => $p->publish_at->timezone($tz)->startOfWeek()->format('Y-m-d'));
        $manual = SocialPostTarget::query()->where('status', SocialPostTarget::MANUAL)->with(['post', 'account'])->get();
        $history = $this->tab === 'gecmis'
            ? SocialPost::query()->whereIn('status', [SocialPost::PUBLISHED, SocialPost::SKIPPED, SocialPost::EXPIRED])
                ->with(['targets.account'])->orderByDesc('publish_at')->limit(60)->get()
            : collect();

        $threads = collect();
        if ($this->tab === 'mesajlar') {
            $threads = SocialThread::query()->with(['account', 'messages' => fn ($q) => $q->orderByDesc('sent_at')->limit(8)])
                ->when($this->inboxFilter === 'bekleyen', fn ($q) => $q->where('status', SocialThread::OPEN)->where('last_direction', 'in'))
                ->when($this->inboxFilter === 'cevaplanan', fn ($q) => $q->where('status', SocialThread::ANSWERED))
                ->when($this->inboxFilter === 'bos', fn ($q) => $q->where('status', SocialThread::IGNORED))
                ->orderByDesc('last_at')->limit(60)->get();
            foreach ($threads as $thread) {
                if (! array_key_exists($thread->id, $this->replies) && $thread->draft !== null && $thread->draft !== '') {
                    $this->replies[$thread->id] = (string) $thread->draft;
                }
            }
        }

        return view('livewire.operator.social.social-page', [
            'threads' => $threads,
            'waitingCount' => SocialThread::query()->where('status', SocialThread::OPEN)->where('last_direction', 'in')->count(),
            'readyCount' => SocialThread::query()->where('status', SocialThread::OPEN)->where('draft_status', 'ready')->whereNotNull('draft')->count(),
            'weeks' => $weeks,
            'manual' => $manual,
            'history' => $history,
            'accounts' => SocialAccount::query()->orderBy('platform')->get(),
            'drafts' => $upcoming->where('status', SocialPost::DRAFT)->count(),
            'failed' => $upcoming->whereIn('status', [SocialPost::FAILED, SocialPost::PARTIAL])->count(),
            'canWrite' => $this->isAdmin(),
            'tz' => $tz,
            'scopes' => SocialMetaConnector::SCOPES,
            'callbackUrl' => route('integrations.meta.callback'),
        ]);
    }

    private function post(int $id): SocialPost
    {
        return SocialPost::query()->with('targets.account')->findOrFail($id);
    }

    private function isAdmin(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->hasRole(Roles::ADMIN);
    }

    private function authorizeAdmin(): void
    {
        abort_unless($this->isAdmin(), 403);
    }
}
