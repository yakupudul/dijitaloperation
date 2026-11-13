<?php

namespace App\Livewire\Operator\Social;

use App\Models\AgencySetting;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\SocialThread;
use App\Models\User;
use App\Services\Social\InstagramFeed;
use App\Services\Social\LinkedInApi;
use App\Services\Social\LinkedInConnector;
use App\Services\Social\SocialGraph;
use App\Services\Social\SocialMetaConnector;
use App\Services\Social\SocialPlanner;
use App\Services\Social\SocialSchedule;
use App\Services\Social\SocialTrendWatch;
use App\Support\Demo\DemoState;
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
 * one post. Approved posts go out at their time; LinkedIn too once "LinkedIn'i bağla" is done, before that as "copy and open".
 * "Hesaplar" connects Meta and adds the LinkedIn page; "Yeni gönderi" uploads a post by hand. Instagram / Facebook
 * messages and comments are answered in the Gelen kutusu (Aşama 2, 2026-11-07).
 */
#[Layout('operator.layouts.app')]
#[Title('Sosyal medya')]
final class SocialPage extends Component
{
    use WithFileUploads;

    /** Instagram görünümü (yakup 2026-11-08) opens first: the profile grid, ready posts on top. */
    #[Url(as: 'sekme')]
    public string $tab = 'instagram';

    /** The post opened over the grid (0 = none). */
    public int $openPost = 0;

    /** A live Instagram post shared outside MoxDOP, opened over the grid (its media id). */
    public string $openIg = '';

    public string $message = '';

    /** info | success | error: the Meta callback's result arrives as a flash and keeps its tone. */
    public string $messageTone = 'info';

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

    // Paylaşım düzeni: chosen weekdays (1 = Pazartesi … 7 = Pazar) and the hour of each.
    /** @var array<int, bool> */
    public array $planDays = [];

    /** @var array<int, string> */
    public array $planTimes = [];

    /** @var array<int, string> Story hours of every day (empty = unused). */
    public array $storyTimes = ['', '', ''];

    /** @var array<int, string> video hours of every day; empty = videos wait in the post line */
    public array $reelTimes = ['', ''];

    /** Trendler: watched Instagram accounts, one per line. */
    public string $watchlist = '';

    // Hesaplar
    public string $configId = '';

    public string $socialAppId = '';

    /** Write-only: the stored secret never enters Livewire state. */
    public string $socialAppSecret = '';

    public string $linkedinName = 'Moximu';

    public string $linkedinUrl = 'https://www.linkedin.com/company/moximu/';

    public string $linkedinClientId = '';

    /** Write-only: the stored LinkedIn Client Secret never enters Livewire state. */
    public string $linkedinClientSecret = '';

    public function mount(): void
    {
        // Mesajlar moved to the Gelen kutusu (Aşama 2, 2026-11-07); the old address still works.
        if ($this->tab === 'mesajlar') {
            $this->redirectRoute('operator.inbox', navigate: true);

            return;
        }
        if (! in_array($this->tab, ['instagram', 'plan', 'trendler', 'gecmis', 'yeni', 'hesaplar'], true)) {
            $this->tab = 'instagram';
        }
        // The Meta login comes back here with its result as a flash; without this it was never shown (2026-11-08).
        $flash = DemoState::pullFlash();
        if (is_array($flash) && ($flash['message'] ?? '') !== '') {
            $this->message = (string) $flash['message'];
            $this->messageTone = in_array($flash['tone'] ?? '', ['success', 'error'], true) ? $flash['tone'] : 'info';
        }
        $chosen = app(SocialSchedule::class)->days();
        foreach (range(1, 7) as $day) {
            $this->planDays[$day] = isset($chosen[$day]);
            $this->planTimes[$day] = $chosen[$day] ?? SocialSchedule::DEFAULT_TIME;
        }
        $this->storyTimes = array_pad(app(SocialSchedule::class)->storyTimes(), 3, '');
        $this->reelTimes = array_pad(app(SocialSchedule::class)->reelTimes(), 2, '');
        $this->watchlist = implode("\n", app(SocialTrendWatch::class)->accounts());
        $this->configId = (string) (SocialMetaConnector::configId() ?? '');
        $this->socialAppId = (string) (AgencySetting::query()->value('social_meta_app_id') ?? '');
        $this->linkedinClientId = (string) (AgencySetting::query()->value('social_linkedin_client_id') ?? '');
        $this->newTime = now(SocialPlanner::TIMEZONE)->addDay()->setTime(11, 30)->format('Y-m-d\TH:i');
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['instagram', 'plan', 'trendler', 'gecmis', 'yeni', 'hesaplar'], true) ? $tab : 'instagram';
        $this->openPost = 0;
        $this->message = '';
        $this->messageTone = 'info';
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
        $this->message = $posts->count().' gönderi onaylandı; paylaşım düzenindeki günlere sırayla yerleşti.';
    }

    public function openPost(int $id): void
    {
        $this->openIg = '';
        $this->openPost = SocialPost::query()->whereKey($id)->exists() ? $id : 0;
    }

    public function closePost(): void
    {
        $this->openPost = 0;
        $this->openIg = '';
    }

    public function openIgPost(string $id): void
    {
        $this->openPost = 0;
        $this->openIg = preg_match('/^\d{1,30}$/', $id) === 1 ? $id : '';
    }

    /** Reads the live Instagram posts again (the grid keeps them 10 minutes). */
    /** Trendler: the watched accounts, one per line. */
    public function saveWatchlist(): void
    {
        $this->authorizeAdmin();
        $names = app(SocialTrendWatch::class)->save(preg_split('/[\s,;]+/', $this->watchlist) ?: []);
        $this->watchlist = implode("\n", $names);
        $this->message = count($names).' hesap izleniyor. Okumak için “Şimdi oku”ya basın.';
        $this->messageTone = 'success';
    }

    /** Trendler: reads the watched accounts now (one Graph call per account). */
    public function readTrends(): void
    {
        $this->authorizeAdmin();
        $reading = app(SocialTrendWatch::class)->reading(true);
        $this->message = count($reading['hits']).' öne çıkan gönderi bulundu'.($reading['errors'] !== [] ? '; '.count($reading['errors']).' hesap okunamadı.' : '.');
        $this->messageTone = 'success';
    }

    public function refreshFeed(): void
    {
        $account = SocialAccount::query()->where('platform', SocialAccount::INSTAGRAM)->where('enabled', true)->orderBy('id')->first();
        if ($account !== null) {
            app(InstagramFeed::class)->posts($account, true);
        }
    }

    public function publishNow(int $id): void
    {
        $this->authorizeAdmin();
        app(SocialPlanner::class)->publishNow($this->post($id), (int) auth()->id());
        $this->message = 'Onaylandı; birkaç dakika içinde Instagram ve Facebook\'ta yayınlanır.';
        $this->messageTone = 'success';
    }

    public function takeDown(int $id): void
    {
        $this->authorizeAdmin();
        $left = app(SocialPlanner::class)->takeDown($this->post($id));
        $this->openPost = 0;
        $this->message = $left === [] ? 'Gönderi silindi.' : 'Gönderi listeden kalktı. '.implode(', ', $left).' tarafında uygulamadan silmen gerekiyor (Meta API buna izin vermiyor).';
        $this->messageTone = $left === [] ? 'success' : 'info';
    }

    public function approve(int $id): void
    {
        $this->authorizeAdmin();
        app(SocialPlanner::class)->approve($this->post($id), (int) auth()->id());
        $at = $this->post($id)->publish_at->timezone(SocialPlanner::TIMEZONE)->translatedFormat('j F l, H:i');
        $this->message = 'Onaylandı; '.$at.' için sıraya girdi.';
    }

    /** Paylaşım düzeni: the chosen days and hours are kept and the waiting approved posts take their new days. */
    public function saveSchedule(): void
    {
        $this->authorizeAdmin();
        $days = [];
        foreach (range(1, 7) as $day) {
            if ($this->planDays[$day] ?? false) {
                $days[$day] = (string) ($this->planTimes[$day] ?? '');
            }
        }
        try {
            $changed = app(SocialSchedule::class)->save($days, $this->storyTimes, $this->reelTimes);
            $this->storyTimes = array_pad(app(SocialSchedule::class)->storyTimes(), 3, '');
            $this->reelTimes = array_pad(app(SocialSchedule::class)->reelTimes(), 2, '');
            $this->message = 'Paylaşım düzeni kaydedildi'.($changed > 0 ? '; sıradaki '.$changed.' gönderinin günü yeniden belirlendi.' : '.');
            $this->messageTone = 'success';
        } catch (Throwable $e) {
            $this->message = $e->getMessage();
            $this->messageTone = 'error';
        }
    }

    /** Sırada öne (-1) ya da arkaya (+1). */
    public function move(int $id, int $direction): void
    {
        $this->authorizeAdmin();
        app(SocialSchedule::class)->move($this->post($id), $direction);
        $this->message = '';
    }

    /** Tarihi sabitle (kendi gününde kalır) ya da sıraya al. */
    public function pin(int $id, bool $pinned): void
    {
        $this->authorizeAdmin();
        app(SocialPlanner::class)->pin($this->post($id), $pinned);
        $this->message = $pinned ? 'Tarih sabitlendi; bu gönderi sıradan bağımsız kendi gününde yayınlanır.' : 'Gönderi sıraya alındı.';
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

    public function saveConfigId(): void
    {
        $this->authorizeAdmin();
        $value = preg_replace('/\D/', '', $this->configId) ?? '';
        $setting = AgencySetting::query()->first() ?? new AgencySetting;
        $setting->forceFill(['social_meta_config_id' => $value !== '' ? $value : null])->save();
        $this->configId = $value;
        $this->message = $value !== '' ? 'Yapılandırma kimliği kaydedildi.' : 'Yapılandırma kimliği temizlendi; giriş izin listesiyle açılır.';
    }

    public function saveSocialApp(): void
    {
        $this->authorizeAdmin();
        $id = preg_replace('/\D/', '', $this->socialAppId) ?? '';
        $secret = trim($this->socialAppSecret);
        $setting = AgencySetting::query()->first() ?? new AgencySetting;
        if ($id === '') {
            $setting->forceFill(['social_meta_app_id' => null, 'social_meta_app_secret' => null])->save();
            $this->message = 'Ayrı uygulama kaldırıldı; giriş Entegrasyonlar › Meta uygulamasıyla açılır.';
        } else {
            $this->validate(['socialAppSecret' => [$setting->social_meta_app_secret ? 'nullable' : 'required', 'string', 'regex:/^[a-f0-9]{32}$/i']],
                ['socialAppSecret.required' => 'App Secret gerekli.', 'socialAppSecret.regex' => 'App Secret 32 karakterlik bir koddur (Uygulama ayarları › Temel).']);
            $setting->forceFill(['social_meta_app_id' => $id] + ($secret !== '' ? ['social_meta_app_secret' => $secret] : []))->save();
            $this->message = 'Sosyal medya artık App ID '.$id.' ile bağlanır.';
        }
        $this->socialAppId = $id;
        $this->socialAppSecret = '';
    }

    /** LinkedIn developer app (Auth tab): Client ID and Client Secret, needed before "LinkedIn'i bağla". */
    public function saveLinkedinApp(): void
    {
        $this->authorizeAdmin();
        $id = trim($this->linkedinClientId);
        $secret = trim($this->linkedinClientSecret);
        $setting = AgencySetting::query()->first() ?? new AgencySetting;
        if ($id === '') {
            $setting->forceFill(['social_linkedin_client_id' => null, 'social_linkedin_client_secret' => null])->save();
            $this->message = 'LinkedIn uygulaması kaldırıldı; LinkedIn gönderileri “kopyala ve aç” olarak bekler.';
        } else {
            $this->validate([
                'linkedinClientId' => ['required', 'string', 'regex:/^[A-Za-z0-9]{8,40}$/'],
                'linkedinClientSecret' => [$setting->social_linkedin_client_secret ? 'nullable' : 'required', 'string', 'min:8', 'max:200'],
            ], [
                'linkedinClientId.regex' => 'Client ID, LinkedIn uygulamasının Auth sekmesindeki harf ve rakamlardan oluşan koddur.',
                'linkedinClientSecret.required' => 'Primary Client Secret gerekli (Auth sekmesi).',
            ]);
            $setting->forceFill(['social_linkedin_client_id' => $id] + ($secret !== '' ? ['social_linkedin_client_secret' => $secret] : []))->save();
            $this->message = 'LinkedIn uygulaması kaydedildi. Şimdi “LinkedIn’i bağla” ile Moximu sayfasının yöneticisi olarak giriş yapın.';
        }
        $this->linkedinClientId = $id;
        $this->linkedinClientSecret = '';
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

        $profile = null;
        $opened = null;
        if ($this->tab === 'instagram') {
            $ready = SocialPost::query()->whereNull('removed_at')
                ->whereIn('status', [SocialPost::DRAFT, SocialPost::APPROVED, SocialPost::FAILED, SocialPost::PARTIAL])
                ->whereDoesntHave('targets', fn ($q) => $q->where('status', SocialPostTarget::PUBLISHED))
                ->with(['targets.account'])->orderBy('publish_at')->limit(60)->get();
            $published = SocialPost::query()->whereNull('removed_at')
                ->whereHas('targets', fn ($q) => $q->whereIn('status', [SocialPostTarget::PUBLISHED, SocialPostTarget::DONE]))
                ->with(['targets.account'])->orderByDesc('publish_at')->limit(90)->get();
            $account = SocialAccount::query()->where('platform', SocialAccount::INSTAGRAM)->where('enabled', true)->orderBy('id')->first();
            // The grid is what is live on Instagram (shared from here or from the phone), newest first; a post sent
            // from here shows its own record (approve, take down), anything else opens on Instagram.
            $feed = $account !== null ? app(InstagramFeed::class)->posts($account) : [];
            $byExternal = $published->filter(fn (SocialPost $p): bool => $p->targets->contains(fn ($t) => $t->account?->platform === SocialAccount::INSTAGRAM && $t->external_id))
                ->keyBy(fn (SocialPost $p): string => (string) $p->targets->first(fn ($t) => $t->account?->platform === SocialAccount::INSTAGRAM && $t->external_id)->external_id);
            $grid = collect($feed)->map(fn (array $m): array => ['post' => $byExternal->get($m['id']), 'ig' => $m, 'at' => $m['at']]);
            $seen = $grid->pluck('ig.id')->flip();
            foreach ($published as $post) {
                $ext = (string) ($post->targets->first(fn ($t) => $t->account?->platform === SocialAccount::INSTAGRAM)?->external_id ?? '');
                if ($ext === '' || ! isset($seen[$ext])) {
                    $grid->push(['post' => $post, 'ig' => null, 'at' => $post->publish_at->toIso8601String()]);
                }
            }
            $profile = [
                'account' => $account, 'ready' => $ready, 'grid' => $grid->sortByDesc('at')->values(),
                'waiting' => $ready->where('status', SocialPost::DRAFT)->count(),
            ];
            $opened = $this->openPost > 0 ? SocialPost::query()->with(['targets.account'])->find($this->openPost) : null;
            $openedIg = $this->openIg !== '' ? collect($feed)->firstWhere('id', $this->openIg) : null;
        }

        return view('livewire.operator.social.social-page', [
            'profile' => $profile,
            'opened' => $opened,
            'openedIg' => $openedIg ?? null,
            'waitingCount' => SocialThread::query()->onEnabledAccount()->where('status', SocialThread::OPEN)->where('last_direction', 'in')->count(),
            'weeks' => $weeks,
            'line' => app(SocialSchedule::class)->line()->modelKeys(),
            'storyLine' => app(SocialSchedule::class)->line(true)->modelKeys(),
            'reelLine' => app(SocialSchedule::class)->reelTimes() === [] ? null : app(SocialSchedule::class)->line('reels')->modelKeys(),
            'trends' => $this->tab === 'trendler' ? app(SocialTrendWatch::class)->reading() : null,
            'dayNames' => [1 => 'Pazartesi', 2 => 'Salı', 3 => 'Çarşamba', 4 => 'Perşembe', 5 => 'Cuma', 6 => 'Cumartesi', 7 => 'Pazar'],
            'manual' => $manual,
            'history' => $history,
            'accounts' => SocialAccount::query()->orderBy('platform')->get(),
            'drafts' => $upcoming->where('status', SocialPost::DRAFT)->count(),
            'failed' => $upcoming->whereIn('status', [SocialPost::FAILED, SocialPost::PARTIAL])->count(),
            'canWrite' => $this->isAdmin(),
            'tz' => $tz,
            'scopes' => SocialMetaConnector::SCOPES,
            'callbackUrl' => route('integrations.meta.callback'),
            'metaAppId' => app(SocialGraph::class)->appId(),
            'ownSocialApp' => SocialGraph::ownApp() !== null,
            'lastAttempt' => SocialMetaConnector::lastAttempt(),
            'linkedinApp' => LinkedInApi::app() !== null,
            'linkedinCallback' => LinkedInConnector::redirectUri(),
            'linkedinScopes' => LinkedInConnector::SCOPES,
            'linkedinAttempt' => LinkedInConnector::lastAttempt(),
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
