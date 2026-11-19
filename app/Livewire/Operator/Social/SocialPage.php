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
use App\Services\Social\LinkedInPersonalConnector;
use App\Services\Social\SocialGraph;
use App\Services\Social\SocialMetaConnector;
use App\Services\Social\SocialPlanner;
use App\Services\Social\SocialSchedule;
use App\Services\Social\SocialScreen;
use App\Services\Social\SocialThumbs;
use App\Services\Social\SocialTrendWatch;
use App\Services\Social\ThreadsApi;
use App\Support\Demo\DemoState;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

/**
 * Sosyal medya (`/sosyal`, ADR-083; screen redesign yakup 2026-11-17): the agency's own Instagram, Facebook, LinkedIn and
 * Threads on one screen. Five tabs, each bounded so the page never grows with the number of posts: "Bugün" (today's
 * posts, what waits for the operator, how many days of ready content each channel has), "Takvim" (one week, or a month
 * of counts), "Gönderiler" (a list of 25 per page with filters, or the Instagram profile grid), "Konuşmalar" (public
 * comments that wait) and "Sonuçlar" (numbers and trends). The channel is a filter, not a tab. A post opens in a side
 * panel with its texts per channel and every action; lists show a small cover picture, the video loads only there.
 * "Ayarlar" holds the posting plan and the account connections, "Yeni gönderi" uploads a post by hand.
 */
#[Layout('operator.layouts.app')]
#[Title('Sosyal medya')]
final class SocialPage extends Component
{
    use WithFileUploads;

    public const array TABS = ['bugun' => 'Bugün', 'takvim' => 'Takvim', 'gonderiler' => 'Gönderiler', 'konusmalar' => 'Konuşmalar', 'sonuclar' => 'Sonuçlar'];

    /** Addresses of the old tabs (links in mails, bookmarks, the OAuth callbacks) keep working. */
    private const array OLD_TABS = ['instagram' => 'gonderiler', 'plan' => 'takvim', 'gecmis' => 'gonderiler', 'trendler' => 'sonuclar', 'hesaplar' => 'ayarlar'];

    #[Url(as: 'sekme', except: 'bugun')]
    public string $tab = 'bugun';

    /** The channel filter of every tab (instagram | facebook | linkedin | threads); empty = all channels. */
    #[Url(as: 'kanal', except: '')]
    public string $channel = '';

    /** Takvim: the Monday of the shown week (Y-m-d); empty = this week. */
    #[Url(as: 'hafta', except: '')]
    public string $week = '';

    /** Takvim on a phone: the day whose posts are listed. */
    #[Url(as: 'gun', except: '')]
    public string $day = '';

    /** Takvim: hafta | ay. */
    #[Url(as: 'takvim', except: 'hafta')]
    public string $calendar = 'hafta';

    public bool $stories = true;

    /** Gönderiler: liste | izgara (the Instagram profile). */
    #[Url(as: 'gorunum', except: 'liste')]
    public string $mode = 'liste';

    /** Gönderiler opens on what is in line; empty = every state. */
    #[Url(as: 'durum', except: 'sirada')]
    public string $status = 'sirada';

    #[Url(as: 'tur', except: '')]
    public string $kind = '';

    #[Url(as: 'ara', except: '')]
    public string $search = '';

    #[Url(as: 'sayfa', except: 1)]
    public int $pageNo = 1;

    public int $readyCount = 12;

    public int $gridCount = SocialScreen::GRID_STEP;

    /** Sonuçlar: 7 | 28 days. */
    #[Url(as: 'donem', except: 7)]
    public int $period = 7;

    /** Ayarlar: duzen | hesaplar. */
    #[Url(as: 'bolum', except: 'duzen')]
    public string $section = 'duzen';

    /** The post opened in the side panel (0 = none). */
    public int $openedId = 0;

    /** A live Instagram post shared outside MoxDOP, opened over the grid (its media id). */
    public string $openIg = '';

    public string $message = '';

    /** info | success | error: the Meta callback's result arrives as a flash and keeps its tone. */
    public string $messageTone = 'info';

    public ?int $editing = null;

    public string $editCaption = '';

    public string $editLinkedin = '';

    public string $editThreads = '';

    public string $editPersonal = '';

    public string $editTime = '';

    // Yeni gönderi
    public string $newKind = 'image';

    public string $newPillar = '';

    public string $newTitle = '';

    public string $newCaption = '';

    public string $newLinkedin = '';

    public string $newThreads = '';

    public string $newPersonal = '';

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

    /** @var array<int, string> the text post hours of every day (Threads, LinkedIn) */
    public array $textTimes = ['', ''];

    /** Günün son videosu deneme Reels'i olarak çıkar (önce takip etmeyenlere gösterilir). */
    public bool $trialReels = true;

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

    public string $linkedinPersonalClientId = '';

    /** Write-only, like the other secrets. */
    public string $linkedinPersonalClientSecret = '';

    public string $threadsAppId = '';

    /** Write-only: the stored Threads app secret never enters Livewire state. */
    public string $threadsAppSecret = '';

    /** Write-only: the stored LinkedIn Client Secret never enters Livewire state. */
    public string $linkedinClientSecret = '';

    public function mount(): void
    {
        // Mesajlar moved to the Gelen kutusu (Aşama 2, 2026-11-07); the old address still works.
        if ($this->tab === 'mesajlar') {
            $this->redirectRoute('operator.inbox', navigate: true);

            return;
        }
        $this->go($this->tab);
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
        $this->textTimes = array_pad(app(SocialSchedule::class)->textTimes(), 2, '');
        $this->trialReels = app(SocialSchedule::class)->trialReels();
        $this->watchlist = implode("\n", app(SocialTrendWatch::class)->accounts());
        $this->configId = (string) (SocialMetaConnector::configId() ?? '');
        $this->socialAppId = (string) (AgencySetting::query()->value('social_meta_app_id') ?? '');
        $this->linkedinClientId = (string) (AgencySetting::query()->value('social_linkedin_client_id') ?? '');
        $this->threadsAppId = (string) (AgencySetting::query()->value('social_threads_app_id') ?? '');
        $this->linkedinPersonalClientId = (string) (AgencySetting::query()->value('social_linkedin_personal_client_id') ?? '');
        $this->newTime = now(SocialPlanner::TIMEZONE)->addDay()->setTime(11, 30)->format('Y-m-d\TH:i');
    }

    public function setTab(string $tab): void
    {
        $this->go($tab);
        $this->openedId = 0;
        $this->openIg = '';
        $this->message = '';
        $this->messageTone = 'info';
    }

    /** "Sizi bekleyen" and the day cells lead into a tab with its filter already set. */
    public function jump(string $tab, string $status = '', int $open = 0, string $section = ''): void
    {
        $this->setTab($tab);
        if ($this->tab === 'gonderiler') {
            $this->mode = 'liste';
            $this->status = isset(SocialScreen::STATUS_FILTERS[$status]) ? $status : '';
            $this->kind = '';
            $this->search = '';
            $this->pageNo = 1;
        }
        if ($this->tab === 'ayarlar' && $section !== '') {
            $this->section = $section === 'hesaplar' ? 'hesaplar' : 'duzen';
        }
        if ($open > 0) {
            $this->openPost($open);
        }
    }

    public function setChannel(string $channel): void
    {
        $this->channel = isset(SocialScreen::CHANNELS[$channel]) ? $channel : '';
        $this->pageNo = 1;
    }

    /** Takvim: a week (or month) back or forward; 0 = this week. */
    public function shift(int $direction): void
    {
        if ($direction === 0) {
            $this->week = '';
            $this->day = '';

            return;
        }
        $start = $this->weekStart();
        $start = $this->calendar === 'ay'
            ? $start->copy()->addDays(3)->startOfMonth()->addMonthsNoOverflow($direction <=> 0)->startOfWeek()->addWeek()
            : $start->copy()->addWeeks($direction <=> 0);
        $this->week = $start->format('Y-m-d');
        $this->day = '';
    }

    /** Takvim: a day picked on the phone strip or in the month; the month opens that day's week. */
    public function pickDay(string $day): void
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
            return;
        }
        $this->day = $day;
        $this->week = Carbon::createFromFormat('Y-m-d', $day, SocialPlanner::TIMEZONE)->startOfWeek()->format('Y-m-d');
        $this->calendar = 'hafta';
    }

    public function more(string $what): void
    {
        if ($what === 'ready') {
            $this->readyCount += SocialScreen::GRID_STEP;
        } else {
            $this->gridCount += SocialScreen::GRID_STEP;
        }
    }

    public function updated(string $name): void
    {
        if (in_array($name, ['status', 'kind', 'search', 'mode'], true)) {
            $this->pageNo = 1;
        }
    }

    public function turn(int $page): void
    {
        $this->pageNo = max(1, $page);
    }

    private function go(string $tab): void
    {
        if ($tab === 'instagram') {
            $this->mode = 'izgara';
            $this->channel = '';
        } elseif ($tab === 'gecmis') {
            $this->mode = 'liste';
            $this->status = 'yayinda';
        } elseif ($tab === 'hesaplar') {
            $this->section = 'hesaplar';
        }
        $tab = self::OLD_TABS[$tab] ?? $tab;
        $this->tab = isset(self::TABS[$tab]) || in_array($tab, ['ayarlar', 'yeni'], true) ? $tab : 'bugun';
        if (! in_array($this->mode, ['liste', 'izgara'], true)) {
            $this->mode = 'liste';
        }
        if (! in_array($this->calendar, ['hafta', 'ay'], true)) {
            $this->calendar = 'hafta';
        }
        if (! in_array($this->period, [7, 28], true)) {
            $this->period = 7;
        }
        if (! in_array($this->section, ['duzen', 'hesaplar'], true)) {
            $this->section = 'duzen';
        }
        if (! isset(SocialScreen::CHANNELS[$this->channel])) {
            $this->channel = '';
        }
    }

    private function weekStart(): Carbon
    {
        $tz = SocialPlanner::TIMEZONE;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->week) === 1) {
            try {
                return Carbon::createFromFormat('Y-m-d', $this->week, $tz)->startOfWeek();
            } catch (Throwable) {
                // falls through to this week
            }
        }

        return now($tz)->startOfWeek();
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
        $this->openedId = SocialPost::query()->whereKey($id)->exists() ? $id : 0;
        $this->editing = null;
    }

    public function closePost(): void
    {
        $this->openedId = 0;
        $this->openIg = '';
        $this->editing = null;
    }

    public function openIgPost(string $id): void
    {
        $this->openedId = 0;
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
        $this->openedId = 0;
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
            $changed = app(SocialSchedule::class)->save($days, $this->storyTimes, $this->reelTimes, $this->trialReels, $this->textTimes);
            $this->storyTimes = array_pad(app(SocialSchedule::class)->storyTimes(), 3, '');
            $this->reelTimes = array_pad(app(SocialSchedule::class)->reelTimes(), 2, '');
            $this->textTimes = array_pad(app(SocialSchedule::class)->textTimes(), 2, '');
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
        $this->editThreads = (string) $post->threads_text;
        $this->editPersonal = (string) $post->personal_text;
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
                'caption' => $this->editCaption, 'linkedin_text' => $this->editLinkedin, 'threads_text' => $this->editThreads, 'personal_text' => $this->editPersonal, 'publish_at' => $this->editTime,
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
            'newFiles' => [Rule::requiredIf($this->newKind !== 'text'), 'array', 'max:10'],
            'newFiles.*' => ['file', 'mimes:jpg,jpeg,png,webp,mp4,mov', 'max:102400'],
            'newTime' => ['required', 'date'],
            'newCaption' => ['nullable', 'string', 'max:2200'],
            'newLinkedin' => ['nullable', 'string', 'max:3000'],
            'newThreads' => ['nullable', 'string', 'max:500'],
            'newPersonal' => ['nullable', 'string', 'max:3000'],
        ], [], ['newFiles' => 'dosyalar', 'newTime' => 'yayın zamanı']);
        try {
            $files = array_map(fn (TemporaryUploadedFile $f): array => ['bytes' => (string) $f->get(), 'name' => 'dosya.'.strtolower($f->getClientOriginalExtension() ?: $f->extension())], $this->newFiles);
            app(SocialPlanner::class)->create([
                'kind' => $this->newKind, 'pillar' => $this->newPillar ?: null, 'title' => $this->newTitle ?: null,
                'caption' => $this->newCaption, 'linkedin_text' => $this->newLinkedin, 'threads_text' => $this->newThreads, 'personal_text' => $this->newPersonal, 'publish_at' => $this->newTime,
            ], $files);
            $this->reset(['newFiles', 'newCaption', 'newLinkedin', 'newThreads', 'newPersonal', 'newTitle', 'newPillar']);
            $this->jump('gonderiler', 'onay');
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

    /** Threads app of the Meta app (Threads use case › Settings): its own app ID and secret, needed before "Threads'i bağla". */
    public function saveThreadsApp(): void
    {
        $this->authorizeAdmin();
        $id = trim($this->threadsAppId);
        $secret = trim($this->threadsAppSecret);
        $setting = AgencySetting::query()->first() ?? new AgencySetting;
        if ($id === '' && $secret === '') {
            $setting->forceFill(['social_threads_app_id' => null, 'social_threads_app_secret' => null])->save();
            $this->message = 'Threads uygulaması kaldırıldı.';
        } else {
            $this->validate([
                'threadsAppId' => ['required', 'string', 'regex:/^\d{8,24}$/'],
                'threadsAppSecret' => [$setting->social_threads_app_secret ? 'nullable' : 'required', 'string', 'min:8', 'max:200'],
            ], [
                'threadsAppId.regex' => 'Threads uygulama kimliği yalnız rakamlardan oluşur (Threads ayarlarındaki “Threads uygulama kimliği”).',
                'threadsAppSecret.required' => 'Threads uygulama gizli anahtarı gerekli.',
            ]);
            $setting->forceFill(['social_threads_app_id' => $id] + ($secret !== '' ? ['social_threads_app_secret' => $secret] : []))->save();
            $this->message = 'Threads uygulaması kaydedildi. Şimdi “Threads’i bağla” ile giriş yapın.';
        }
        $this->threadsAppId = $id;
        $this->threadsAppSecret = '';
        $this->messageTone = 'success';
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

    /** The second LinkedIn app (Share on LinkedIn + OpenID) of the owner's own profile. */
    public function saveLinkedinPersonalApp(): void
    {
        $this->authorizeAdmin();
        $id = trim($this->linkedinPersonalClientId);
        $secret = trim($this->linkedinPersonalClientSecret);
        $setting = AgencySetting::query()->first() ?? new AgencySetting;
        if ($id === '') {
            $setting->forceFill(['social_linkedin_personal_client_id' => null, 'social_linkedin_personal_client_secret' => null])->save();
            $this->message = 'Kişisel LinkedIn uygulaması kaldırıldı.';
        } else {
            $this->validate([
                'linkedinPersonalClientId' => ['required', 'string', 'regex:/^[A-Za-z0-9]{8,40}$/'],
                'linkedinPersonalClientSecret' => [$setting->social_linkedin_personal_client_secret ? 'nullable' : 'required', 'string', 'min:8', 'max:200'],
            ], [
                'linkedinPersonalClientId.regex' => 'Client ID, LinkedIn uygulamasının Auth sekmesindeki harf ve rakamlardan oluşan koddur.',
                'linkedinPersonalClientSecret.required' => 'Primary Client Secret gerekli (Auth sekmesi).',
            ]);
            $setting->forceFill(['social_linkedin_personal_client_id' => $id] + ($secret !== '' ? ['social_linkedin_personal_client_secret' => $secret] : []))->save();
            $this->message = 'Kişisel LinkedIn uygulaması kaydedildi. Şimdi “Kişisel LinkedIn’i bağla” ile kendi hesabınızla giriş yapın.';
        }
        $this->messageTone = 'success';
        $this->linkedinPersonalClientId = $id;
        $this->linkedinPersonalClientSecret = '';
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
        $screen = app(SocialScreen::class);
        $schedule = app(SocialSchedule::class);
        $accounts = SocialAccount::query()->orderBy('platform')->get();
        $waiting = $screen->waiting($accounts);
        $manual = SocialPostTarget::query()->where('status', SocialPostTarget::MANUAL)->with(['post', 'account'])->limit(10)->get();
        $data = [
            'tabs' => self::TABS, 'tz' => $tz, 'canWrite' => $this->isAdmin(), 'accounts' => $accounts, 'thumbs' => app(SocialThumbs::class),
            'channels' => $screen->channels($accounts), 'channelNames' => SocialScreen::CHANNELS,
            'waiting' => $waiting, 'manual' => $manual, 'waitingTotal' => count($waiting) + $manual->count(),
            'talkCount' => SocialThread::query()->onEnabledAccount()->where('status', SocialThread::OPEN)->where('last_direction', 'in')->count(),
            'dayNames' => [1 => 'Pazartesi', 2 => 'Salı', 3 => 'Çarşamba', 4 => 'Perşembe', 5 => 'Cuma', 6 => 'Cumartesi', 7 => 'Pazar'],
            'line' => [], 'storyLine' => [], 'reelLine' => null, 'textLine' => [], 'opened' => null, 'openedIg' => null,
        ];
        // The place in line is shown on list rows and in the side panel only.
        if ($this->openedId > 0 || ($this->tab === 'gonderiler' && $this->mode === 'liste') || $this->tab === 'ayarlar') {
            $data['line'] = $schedule->line()->modelKeys();
            $data['storyLine'] = $schedule->line(true)->modelKeys();
            $data['reelLine'] = $schedule->reelTimes() === [] ? null : $schedule->line('reels')->modelKeys();
            $data['textLine'] = $schedule->line('text')->modelKeys();
        }
        if ($this->openedId > 0) {
            $data['opened'] = SocialPost::query()->with(['targets.account'])->find($this->openedId);
        }

        if ($this->tab === 'bugun') {
            $today = now($tz);
            $data += ['today' => $screen->day($today, $this->channel), 'now' => $today, 'stock' => $screen->stock(), 'quick' => $screen->results(7)];
        } elseif ($this->tab === 'takvim') {
            $start = $this->weekStart();
            $today = now($tz)->format('Y-m-d');
            $picked = $this->day !== '' && $this->day >= $start->format('Y-m-d') && $this->day <= $start->copy()->addDays(6)->format('Y-m-d')
                ? $this->day
                : ($today >= $start->format('Y-m-d') && $today <= $start->copy()->addDays(6)->format('Y-m-d') ? $today : $start->format('Y-m-d'));
            $data += ['weekStart' => $start, 'todayKey' => $today, 'pickedDay' => $picked];
            $data += $this->calendar === 'ay'
                ? ['month' => $screen->month($start->copy()->addDays(3), $this->channel), 'monthOf' => $start->copy()->addDays(3)->startOfMonth()]
                : ['days' => $screen->week($start, $this->channel, $this->stories)];
        } elseif ($this->tab === 'gonderiler' && $this->mode === 'izgara') {
            $account = $accounts->where('platform', SocialAccount::INSTAGRAM)->where('enabled', true)->first();
            // The grid is what is live on Instagram (shared from here or from the phone), newest first; a post sent
            // from here shows its own record (approve, take down), anything else opens on Instagram.
            $feed = $account !== null ? app(InstagramFeed::class)->posts($account) : [];
            $data += ['profile' => ['account' => $account] + $screen->profile($feed, $this->readyCount, $this->gridCount)];
            $data['openedIg'] = $this->openIg !== '' ? collect($feed)->firstWhere('id', $this->openIg) : null;
        } elseif ($this->tab === 'gonderiler') {
            $list = $screen->page($this->status, $this->kind, $this->channel, $this->search, $this->pageNo);
            $this->pageNo = $list['page'];
            $data += ['list' => $list, 'statusFilters' => SocialScreen::STATUS_FILTERS, 'kindFilters' => SocialScreen::KIND_FILTERS];
        } elseif ($this->tab === 'konusmalar') {
            $data += ['talks' => $screen->talks($this->channel)];
        } elseif ($this->tab === 'sonuclar') {
            $data += ['results' => $screen->results($this->period), 'trends' => app(SocialTrendWatch::class)->reading()];
        } elseif ($this->tab === 'ayarlar') {
            $data += [
                'scopes' => SocialMetaConnector::SCOPES,
                'callbackUrl' => route('integrations.meta.callback'),
                'metaAppId' => app(SocialGraph::class)->appId(),
                'ownSocialApp' => SocialGraph::ownApp() !== null,
                'lastAttempt' => SocialMetaConnector::lastAttempt(),
                'linkedinApp' => LinkedInApi::app() !== null,
                'linkedinPersonalApp' => LinkedInApi::app(true) !== null,
                'linkedinPersonalAttempt' => LinkedInPersonalConnector::lastAttempt(),
                'linkedinCallback' => LinkedInConnector::redirectUri(),
                'linkedinScopes' => LinkedInConnector::SCOPES,
                'linkedinAttempt' => LinkedInConnector::lastAttempt(),
                'threadsApp' => ThreadsApi::app() !== null,
                'threadsCallback' => ThreadsApi::redirectUri(),
                'threadsAttempt' => ThreadsApi::lastAttempt(),
            ];
        }

        return view('livewire.operator.social.social-page', $data);
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
