<?php

namespace App\Services\Site;

use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Models\Suggestion;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Services\Assistant\PushNotifier;
use App\Services\Compliance\ComplianceAuditor;
use App\Services\SeoTasks\SeoText;
use App\Services\Social\SocialPlanner;
use App\Services\Work\ContentAutopilot;
use App\Services\Work\ContentScore;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Günlük blog (yakup 2026-11-12: "Moximu.com için her gün 2 içerik paylaşır mısın otomatik … benden onay almana gerek
 * yok", "Onaylıyorum yap", "fiyat verme"). Only for a brand with `auto_blog` (moximu.com, the agency's own site); every
 * other brand keeps the weekly cycle and the daily click.
 *
 * One line per site, no second producer: the site leaves the weekly cycle and the back-dated fill (ContentAutopilot), and
 * every waiting approved title joins this line first.
 *
 *  - Üretim: about two days of articles are kept being written (BUFFER): approved titles first, then the best scored
 *    open ideas. The morning slot takes a lasting guide, the afternoon one a branch / city article or a refresh of an
 *    existing page (lane). When the pool runs low the site's idea run is asked for more titles (once a day), fed by the
 *    Search Console gaps and the questions clinics ask on WhatsApp (readerQuestions).
 *  - Kalite kapısı: instead of the operator's approval every article passes problems() (title ≤ 60 characters without a
 *    colon, no MoxDOP, no guarantee, no price or currency, 450–2500 words, the brand named, no second article on the same
 *    subject) and the usual AI check. What fails is corrected by Claude (ContentAutopilot::heal); what still fails is never
 *    published and the next article takes its place. Nobody is asked.
 *  - Yayın: each free slot of the next day (09:00 and 15:00 Istanbul) gets a checked article. A new article goes to
 *    WordPress scheduled for its slot with its own drawn cover (BrandCover) and a closing paragraph that links the
 *    matching service page and the free assessment (decorate); a refresh replaces its page at the slot. The site's
 *    connector announces each published address to IndexNow by itself.
 *  - Then: an Instagram draft of the same article (portrait cover, waits for the usual approval on /sosyal); the
 *    Business Profile post plan takes the new page first by itself (GbpPostQueue, new pages first). Monday morning one
 *    line report (weeklyReport).
 */
final class DailyBlog
{
    /** The article's Instagram post goes out this long after the article's own slot. */
    public const int INSTAGRAM_AFTER_MINUTES = 90;

    /** Articles kept in work (written, being written or checked): about two days. */
    public const int BUFFER = 6;

    /** Slots filled this far ahead. */
    public const int AHEAD_HOURS = 30;

    /** A refresh replaces its page from this many minutes before its slot. */
    public const int REFRESH_EARLY_MINUTES = 25;

    public const int WORDS_MIN = 450;

    public const int WORDS_MAX = 2500;

    public const int TITLE_MAX = 60;

    /** Same-subject check: share of title words two titles have in common. */
    public const float SAME_SUBJECT = 0.7;

    public const array LANES = ['rehber' => 'Kalıcı rehber', 'yerel' => 'Branş ve şehir', 'yenileme' => 'Yenileme'];

    /** Turkish provinces and the big districts, folded: a title naming one is a city article. */
    private const array PLACES = ['adana', 'adiyaman', 'afyon', 'agri', 'amasya', 'ankara', 'antalya', 'artvin', 'aydin', 'balikesir', 'bilecik', 'bingol', 'bitlis',
        'bolu', 'burdur', 'bursa', 'canakkale', 'cankiri', 'corum', 'denizli', 'diyarbakir', 'edirne', 'elazig', 'erzincan', 'erzurum', 'eskisehir', 'gaziantep',
        'giresun', 'gumushane', 'hakkari', 'hatay', 'isparta', 'mersin', 'istanbul', 'izmir', 'kars', 'kastamonu', 'kayseri', 'kirklareli', 'kirsehir', 'kocaeli',
        'konya', 'kutahya', 'malatya', 'manisa', 'kahramanmaras', 'mardin', 'mugla', 'mus', 'nevsehir', 'nigde', 'ordu', 'rize', 'sakarya', 'samsun', 'siirt',
        'sinop', 'sivas', 'tekirdag', 'tokat', 'trabzon', 'tunceli', 'sanliurfa', 'usak', 'van', 'yozgat', 'zonguldak', 'aksaray', 'bayburt', 'karaman',
        'kirikkale', 'batman', 'sirnak', 'bartin', 'ardahan', 'igdir', 'yalova', 'karabuk', 'kilis', 'osmaniye', 'duzce', 'bornova', 'karsiyaka', 'buca',
        'cankaya', 'kecioren', 'kadikoy', 'besiktas', 'atasehir', 'nilufer', 'osmangazi', 'muratpasa', 'konyaalti', 'sehzadeler', 'yunusemre', 'turgutlu'];

    public function __construct(private readonly SiteSuggestions $suggestions, private readonly ContentScore $scorer) {}

    /** @return array{per_day: int, times: list<string>}|null */
    public static function settings(?Brand $brand): ?array
    {
        $set = is_array($brand?->auto_blog) ? $brand->auto_blog : null;
        $times = array_values(array_filter((array) ($set['times'] ?? []), fn (mixed $t): bool => is_string($t) && preg_match('/^\d{2}:\d{2}$/', $t) === 1));
        if ($set === null || $times === []) {
            return null;
        }
        sort($times);

        return ['per_day' => count($times), 'times' => $times];
    }

    public static function on(?Brand $brand): bool
    {
        return self::settings($brand) !== null;
    }

    public static function siteOn(DigitalAsset $site): bool
    {
        return $site->brand_id !== null && self::on(Brand::query()->find($site->brand_id));
    }

    /** An idea of this line (taken by DailyBlog). */
    public static function taken(Suggestion $suggestion): bool
    {
        return is_array(data_get($suggestion->action, 'daily'));
    }

    /** @return Collection<int, DigitalAsset> the websites of brands with a daily blog */
    public static function sites(): Collection
    {
        $brands = Brand::query()->whereNotNull('auto_blog')->get()->filter(fn (Brand $b): bool => self::on($b))->pluck('id');

        return $brands->isEmpty() ? collect() : ContentAutopilot::sites()->whereIn('brand_id', $brands->all())->values();
    }

    /**
     * One round (called by the content otopilot every 20 minutes): keep the line fed, then fill the free slots.
     *
     * @return array{started: int, sent: int}
     */
    public function run(User $actor, ?CarbonImmutable $now = null): array
    {
        $out = ['started' => 0, 'sent' => 0];
        foreach (self::sites() as $site) {
            try {
                $out['started'] += $this->produce($site, $actor);
                $out['sent'] += $this->publish($site, $actor, $now);
            } catch (Throwable $e) {
                Log::warning('Daily blog round failed.', ['site' => $site->id, 'error' => $e->getMessage()]);
            }
        }

        return $out;
    }

    /** @return Builder<Suggestion> */
    private static function ideas(DigitalAsset $site): Builder
    {
        return Suggestion::query()->where('channel', 'search')->where('action_type', SiteSuggestionTypes::CONTENT)
            ->where('brand_id', $site->brand_id)->where('action->site_id', (int) $site->id);
    }

    /** @return Collection<int, Suggestion> this line's articles not sent yet (being written, checked or waiting for a slot) */
    public function inWork(DigitalAsset $site): Collection
    {
        return self::ideas($site)->where('status', Suggestion::APPROVED)->whereNull('action->article_write_id')->get()
            ->filter(fn (Suggestion $s): bool => self::taken($s) && ! self::givenUp($s))->values();
    }

    /** Held by the check after every correction round, or blocked by a rule for good: it never goes out. */
    public static function givenUp(Suggestion $suggestion): bool
    {
        $action = (array) $suggestion->action;
        if (is_string($action['article_blocked'] ?? null) && (int) ($action['block_fixes'] ?? 0) >= ContentAutopilot::BLOCK_FIXES) {
            return true;
        }
        $verdict = data_get($action, 'autopilot.review.verdict');

        return is_array($action['article'] ?? null) && in_array($verdict, ['wrong', 'unsure'], true) && ! ContentAutopilot::fixing($suggestion);
    }

    public static function lane(Suggestion $suggestion): string
    {
        if ((data_get($suggestion->action, 'kind') ?? 'new') === 'update') {
            return 'yenileme';
        }
        $folded = ' '.preg_replace('/[^a-z0-9]+/', ' ', SeoText::fold((string) $suggestion->title)).' ';
        foreach (self::PLACES as $place) {
            if (str_contains($folded, ' '.$place.' ') || preg_match('/ '.$place.'(da|de|ta|te|daki|deki|nin|nın|in|ın|li|lı)? /', $folded) === 1) {
                return 'yerel';
            }
        }

        return 'rehber';
    }

    /**
     * Keeps BUFFER articles in work: approved titles first, then the best open ideas (at most one refresh in work).
     *
     * @return int articles started
     */
    public function produce(DigitalAsset $site, User $actor): int
    {
        $work = $this->inWork($site);
        $need = self::BUFFER - $work->count();
        $started = 0;
        foreach ($work as $idea) {
            // A taken title whose writing never started (or was lost) is started again.
            if (! is_array(data_get($idea->action, 'article')) && ! is_array(data_get($idea->action, 'revise_from')) && ! $this->writing($site, $idea)) {
                SiteOperations::dispatch((int) $site->id, SiteOperations::WRITE_ARTICLE, ['suggestion_id' => (int) $idea->id]);
            }
        }
        if ($need <= 0) {
            return 0;
        }
        $refreshes = $work->filter(fn (Suggestion $s): bool => self::lane($s) === 'yenileme')->count();
        $candidates = self::ideas($site)->where(fn (Builder $q): Builder => $q->where('status', Suggestion::APPROVED)->orWhere(fn (Builder $open): Builder => $open->actionable()))
            ->whereNull('action->article_write_id')->get()
            ->filter(fn (Suggestion $s): bool => ! self::taken($s) && ! isset($s->action['article_blocked']) && empty($s->action['page_update']));
        $scores = $this->scorer->forSuggestions($candidates);
        $ordered = $candidates->sortByDesc(fn (Suggestion $s): int => ($s->status === Suggestion::APPROVED ? 10000 : 0) + (is_array($s->action['article'] ?? null) ? 1000 : 0)
            + (int) ($scores[(int) $s->id]['score'] ?? 0))->values();
        $map = app(ContentMap::class);
        $lanes = $work->countBy(fn (Suggestion $s): string => self::lane($s) === 'rehber' ? 'rehber' : 'oglen');
        // Two passes: the first keeps both slots of the day fed, the second fills what is left with whatever is best.
        foreach ([true, false] as $balance) {
            foreach ($ordered as $idea) {
                if ($started >= $need) {
                    break 2;
                }
                if (self::taken($idea)) {
                    continue;
                }
                $lane = self::lane($idea);
                if ($lane === 'yenileme' && $refreshes >= 1) {
                    continue;
                }
                // Keep both slots of the day fed: no more than two thirds of the line in one of them while the other has ideas.
                $side = $lane === 'rehber' ? 'rehber' : 'oglen';
                if ($balance && ($lanes[$side] ?? 0) >= (int) ceil(self::BUFFER * 2 / 3) && $ordered->contains(fn (Suggestion $o): bool => ! self::taken($o) && (self::lane($o) === 'rehber' ? 'rehber' : 'oglen') !== $side)) {
                    continue;
                }
                if ($lane !== 'yenileme' && $map->resolve($idea) !== null) {
                    continue; // the cluster map made it an update of a page or closed it as a repeat
                }
                $idea->refresh();
                if (in_array($idea->status, [Suggestion::DISMISSED, Suggestion::APPLIED], true)) {
                    continue;
                }
                if ($idea->status !== Suggestion::APPROVED) {
                    $this->suggestions->approve($idea, $actor);
                    $idea->refresh();
                }
                $action = (array) $idea->action;
                $action['daily'] = ['lane' => $lane, 'taken_at' => now()->toIso8601String()];
                $idea->forceFill(['action' => $action])->save();
                if (! is_array($action['article'] ?? null) && ! $this->writing($site, $idea)) {
                    SiteOperations::dispatch((int) $site->id, SiteOperations::WRITE_ARTICLE, ['suggestion_id' => (int) $idea->id]);
                }
                $lanes[$side] = ($lanes[$side] ?? 0) + 1;
                $refreshes += $lane === 'yenileme' ? 1 : 0;
                $started++;
            }
        }
        if ($started < $need) {
            $this->askForTitles($site);
        }

        return $started;
    }

    private function writing(DigitalAsset $site, Suggestion $idea): bool
    {
        $status = SiteOperations::status((int) $site->id, SiteOperations::WRITE_ARTICLE, ['suggestion_id' => (int) $idea->id]);

        return in_array($status['status'] ?? null, ['running', 'queued'], true)
            && CarbonImmutable::parse((string) ($status['at'] ?? now()->toIso8601String()))->gt(now()->subHours(ContentAutopilot::BUSY_HOURS));
    }

    /** The pool ran low: the site's idea run is asked for fresh titles, at most once a day. */
    private function askForTitles(DigitalAsset $site): void
    {
        if (! Cache::add('daily-blog:titles:'.$site->id, now()->toIso8601String(), now()->addHours(20))) {
            return;
        }
        $main = ContentPlanner::siteLanguages($site)[0];
        SiteOperations::dispatch((int) $site->id, SiteOperations::WEEKLY_CONTENT, ['wants' => [$main => 10]]);
    }

    /**
     * The slots of the next AHEAD_HOURS (Istanbul time) that no sent article holds yet.
     *
     * @return list<CarbonImmutable>
     */
    public function freeSlots(DigitalAsset $site, ?CarbonImmutable $now = null): array
    {
        $settings = self::settings(Brand::query()->find($site->brand_id));
        if ($settings === null) {
            return [];
        }
        $now ??= CarbonImmutable::now('Europe/Istanbul');
        $now = $now->setTimezone('Europe/Istanbul');
        $held = self::ideas($site)->whereNotNull('action->daily->slot')->get()->map(fn (Suggestion $s): string => (string) data_get($s->action, 'daily.slot'))->flip();
        $out = [];
        for ($day = 0; $day <= 2; $day++) {
            foreach ($settings['times'] as $time) {
                [$h, $m] = array_map('intval', explode(':', $time));
                $slot = $now->startOfDay()->addDays($day)->setTime($h, $m);
                if ($slot->lte($now) || $slot->gt($now->addHours(self::AHEAD_HOURS)) || $held->has($slot->format('Y-m-d H:i'))) {
                    continue;
                }
                $out[] = $slot;
            }
        }

        return $out;
    }

    /** First slot of the day (morning) or a later one. */
    private static function morning(CarbonImmutable $slot, array $times): bool
    {
        return $slot->format('H:i') === ($times[0] ?? '');
    }

    /**
     * Fills the free slots with checked articles: the morning slot prefers a guide, the later ones a city / branch
     * article or a refresh; when the preferred kind is not ready, any checked article goes.
     *
     * @return int articles sent
     */
    public function publish(DigitalAsset $site, User $actor, ?CarbonImmutable $now = null): int
    {
        $settings = self::settings(Brand::query()->find($site->brand_id));
        if ($settings === null || ! in_array((int) $site->id, ContentAutopilot::pairedSiteIds(), true)) {
            return 0;
        }
        $now ??= CarbonImmutable::now('Europe/Istanbul');
        $ready = $this->inWork($site)->filter(fn (Suggestion $s): bool => $this->checked($s, $site))->sortBy('id')->values();
        $planner = app(ContentPlanner::class);
        $sent = 0;
        foreach ($this->freeSlots($site, $now) as $slot) {
            $wants = self::morning($slot, $settings['times']) ? ['rehber'] : ['yerel', 'yenileme'];
            $pick = $ready->first(fn (Suggestion $s): bool => in_array(self::lane($s), $wants, true)) ?? $ready->first(fn (Suggestion $s): bool => self::lane($s) !== 'yenileme');
            if ($pick === null && in_array('yenileme', $wants, true)) {
                $pick = $ready->first();
            }
            if ($pick === null) {
                continue;
            }
            $refresh = self::lane($pick) === 'yenileme';
            if ($refresh && $now->lt($slot->subMinutes(self::REFRESH_EARLY_MINUTES))) {
                continue; // a refresh replaces its page at its slot, not a day before
            }
            try {
                if (! $refresh) {
                    $this->decorate($pick, $site);
                    // Its own drawn cover, never a photo from the site (yakup): no cover yet, the article waits for the next round.
                    if (! is_array(data_get($pick->fresh()->action, 'image')) && BrandCover::styleOf(Brand::query()->find($site->brand_id)) !== null) {
                        $cover = app(BrandCover::class)->attach($pick->fresh(), $site);
                        if ($cover === null) {
                            $ready = $ready->reject(fn (Suggestion $s): bool => (int) $s->id === (int) $pick->id)->values();

                            continue;
                        }
                        $pick->forceFill(['action' => array_merge((array) $pick->fresh()->action, ['image' => $cover])])->save();
                    }
                }
                $action = (array) $pick->fresh()->action;
                $action['daily'] = array_merge((array) ($action['daily'] ?? []), ['slot' => $slot->format('Y-m-d H:i'), 'sent_at' => now()->toIso8601String()]);
                $pick->forceFill(['action' => $action])->save();
                $planner->sendDraft($pick->fresh(), $actor, $refresh ? null : $slot);
                $sent++;
                if (! $refresh) {
                    $this->instagramDraft($pick->fresh(), $slot, (int) $actor->id);
                }
            } catch (Throwable $e) {
                $action = (array) $pick->fresh()->action;
                unset($action['daily']['slot'], $action['daily']['sent_at']);
                $action['daily']['send_error'] = mb_substr($e->getMessage(), 0, 200);
                $pick->forceFill(['action' => $action])->save();
                Log::warning('Daily blog send failed.', ['suggestion' => $pick->id, 'error' => $e->getMessage()]);
            }
            $ready = $ready->reject(fn (Suggestion $s): bool => (int) $s->id === (int) $pick->id)->values();
        }

        return $sent;
    }

    /** Written, AI-checked "ok", and through the quality gate (checked again right before the send). */
    private function checked(Suggestion $suggestion, DigitalAsset $site): bool
    {
        $action = (array) $suggestion->action;
        if (! is_array($action['article'] ?? null) || isset($action['article_blocked']) || data_get($action, 'autopilot.review.verdict') !== 'ok') {
            return false;
        }
        $problems = self::problems((array) $action['article'], $suggestion, $site);
        if ($problems === []) {
            return true;
        }
        // Not through the gate any more (an edit, a rule added since): Claude corrects it like any held article.
        $action['autopilot'] = array_merge((array) ($action['autopilot'] ?? []), ['review' => ['verdict' => 'wrong', 'reason' => self::reason($problems), 'at' => now()->toIso8601String()]]);
        $suggestion->forceFill(['action' => $action])->save();

        return false;
    }

    /**
     * Kalite kapısı: what an article of this line must meet, in plain Turkish; empty when it passes.
     *
     * @param  array<string, mixed>  $article  title, meta_title, meta_description, excerpt, html
     * @return list<string>
     */
    public static function problems(array $article, ?Suggestion $suggestion = null, ?DigitalAsset $site = null): array
    {
        $out = [];
        $update = $suggestion !== null && (data_get($suggestion->action, 'kind') ?? 'new') === 'update';
        $title = trim(html_entity_decode(strip_tags((string) ($article['title'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (! $update) {
            if (mb_strlen($title) > self::TITLE_MAX) {
                $out[] = 'başlık '.mb_strlen($title).' karakter, en çok '.self::TITLE_MAX;
            }
            if (str_contains($title, ':')) {
                $out[] = 'başlıkta iki nokta var';
            }
        }
        $body = ComplianceAuditor::visibleText('<html><body>'.(string) ($article['html'] ?? '').'</body></html>');
        $text = implode(' ', [$title, (string) ($article['meta_title'] ?? ''), (string) ($article['meta_description'] ?? ''), (string) ($article['excerpt'] ?? ''), $body]);
        if (preg_match('/moxdop/iu', $text) === 1) {
            $out[] = 'MoxDOP adı geçiyor';
        }
        if (preg_match('/garanti|kesin sonuç|kesinlikle (?:birinci|ilk) sıra|yüzde ?yüz|%\s?100|100\s?%|sonuç sözü/iu', $text, $m) === 1) {
            $out[] = 'garanti ifadesi var («'.$m[0].'»)';
        }
        if (preg_match('/₺|(?<!\p{L})(?:TL|TRY|KDV)(?!\p{L})|(?<!\p{L})(?:lira|euro|dolar)(?:\p{L}{0,4})(?!\p{L})|[€$£]|\d\s?(?:bin|milyon)\s?(?:tl|lira)/u', $text, $m) === 1
            || preg_match('/(?<!\p{L})(?:lira|euro|dolar)/iu', $text, $m) === 1) {
            $out[] = 'fiyat ya da para birimi var («'.trim($m[0]).'»); bu blogda ücret rakamı verilmez';
        }
        $words = ContentFormat::words((string) ($article['html'] ?? ''));
        if (! $update && $words < self::WORDS_MIN) {
            $out[] = $words.' kelime, en az '.self::WORDS_MIN;
        }
        if ($words > self::WORDS_MAX) {
            $out[] = $words.' kelime, en çok '.self::WORDS_MAX;
        }
        $brand = $suggestion?->brand_id !== null ? Brand::query()->find($suggestion->brand_id) : null;
        if (! $update && $brand !== null && ! str_contains(SeoText::fold($body), SeoText::fold((string) $brand->name))) {
            $out[] = $brand->name.' adı ve yaklaşımı yazıda hiç geçmiyor (marka bilgisi eksik)';
        }
        if (! $update && $site !== null && ($same = self::sameSubject($title, $site, $suggestion)) !== null) {
            $out[] = 'aynı konuda yazı var: '.$same;
        }

        return $out;
    }

    /** @param  list<string>  $problems */
    public static function reason(array $problems): string
    {
        return mb_substr('Kalite kapısı: '.implode('; ', $problems).'.', 0, 380);
    }

    /** The title of a page of the site, or an article of this line, on the same subject; null when none. */
    public static function sameSubject(string $title, DigitalAsset $site, ?Suggestion $self = null): ?string
    {
        $mine = self::tokens($title);
        if (count($mine) < 2) {
            return null;
        }
        // Articles and question pages of the site (a service page is the article's link target, not a rival).
        $titles = Page::query()->where('website_asset_id', $site->id)->whereNotNull('title')
            ->where(fn (Builder $q): Builder => $q->whereNull('category')->orWhereIn('category', ['blog', 'sss', 'rehber']))
            ->where(fn (Builder $q): Builder => $q->whereNull('path')->orWhere(fn (Builder $p): Builder => $p->where('path', 'not like', '/hizmetlerimiz/%')->where('path', 'not like', '/klinik/%')
                ->where('path', 'not like', '/sehirler/%')->where('path', 'not like', '/kurumlar/%')->where('path', 'not like', '/fiyatlar/%')))
            ->pluck('title')->map(fn ($t): string => (string) $t)
            ->merge(self::ideas($site)->whereNotNull('action->article_write_id')->when($self !== null, fn (Builder $q): Builder => $q->whereKeyNot($self->id))
                ->get(['id', 'action'])->map(fn (Suggestion $s): string => (string) data_get($s->action, 'article.title', '')));
        foreach ($titles as $other) {
            $theirs = self::tokens(preg_replace('/\s*[·|]\s*.*$/u', '', $other) ?? $other);
            if (count($theirs) < 2) {
                continue;
            }
            $common = count(array_intersect($mine, $theirs));
            if ($common / max(1, min(count($mine), count($theirs))) >= self::SAME_SUBJECT && $common / max(count($mine), count($theirs)) >= 0.5) {
                return $other;
            }
        }

        return null;
    }

    /** @return list<string> the meaningful folded words of a title */
    private static function tokens(string $title): array
    {
        $stop = ['ve', 'ile', 'icin', 'nasil', 'neden', 'nedir', 'mi', 'mu', 'mı', 'ne', 'bir', 'en', 'da', 'de', 'ki', 'ya', 'hangi', 'kac', 'zaman', 'moximu', 'olur', 'yapilir'];
        $words = preg_split('/[^a-z0-9]+/', SeoText::fold($title), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter($words, fn (string $w): bool => mb_strlen($w) >= 3 && ! in_array($w, $stop, true))));
    }

    /**
     * Satışa bağlama: the article closes with one paragraph that links the site's matching service page and its free
     * assessment page (both found among the site's own pages; nothing invented). Once per article.
     */
    public function decorate(Suggestion $suggestion, DigitalAsset $site): void
    {
        $action = (array) $suggestion->action;
        $html = (string) data_get($action, 'article.html', '');
        if ($html === '' || str_contains($html, 'data-moxdop="sonraki-adim"')) {
            return;
        }
        $pages = Page::query()->where('website_asset_id', $site->id)->where('is_indexable', true)->get(['id', 'url', 'path', 'title']);
        $topic = SeoText::fold(implode(' ', [(string) data_get($action, 'article.title', $suggestion->title), (string) ($action['query'] ?? ''), (string) ($action['service'] ?? '')]));
        $topicWords = preg_split('/[^a-z0-9]+/', $topic, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        // Words every service page shares ("google", "klinik", "yönetimi") say nothing about the subject: an article on
        // Google Ads once got the Business Profile page because "google" was the only match.
        $generic = ['google', 'klinik', 'klinikler', 'kliniginiz', 'icin', 'hizmet', 'hizmetlerimiz', 'hizmeti', 'yonetimi', 'yonetim', 'moximu', 'nasil', 'neden', 'nedir'];
        $best = $pages->filter(fn (Page $p): bool => preg_match('#^/(hizmetlerimiz|klinik|kurumlar|sehirler)/[^/]+/(seo/)?$#', (string) $p->path) === 1)
            ->map(function (Page $p) use ($topicWords, $generic): array {
                $words = preg_split('/[^a-z0-9]+/', SeoText::fold($p->title.' '.$p->path), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $score = 0;
                foreach (array_unique($words) as $w) {
                    $score += mb_strlen($w) >= 3 && ! in_array($w, $generic, true) && in_array($w, $topicWords, true) ? (str_starts_with((string) $p->path, '/hizmetlerimiz/') ? 3 : 2) : 0;
                }

                return ['page' => $p, 'score' => $score];
            })->filter(fn (array $c): bool => $c['score'] > 0)->sortByDesc('score')->first()['page'] ?? null;
        // The article already links its own service page: no second, weaker one.
        $service = $best !== null && ! str_contains($html, rtrim((string) $best->path, '/')) ? $best : null;
        $free = $pages->first(fn (Page $p): bool => str_contains(SeoText::fold((string) $p->title), 'on degerlendirme') || str_contains((string) $p->path, 'ucretsiz'));
        if ($service === null && $free === null) {
            return;
        }
        $parts = [];
        if ($service !== null) {
            $name = trim((string) preg_replace('/\s*[·|:].*$/u', '', (string) $service->title));
            $parts[] = 'Bu işi kliniğiniz için nasıl yaptığımızı <a href="'.e((string) $service->url).'">'.e($name).'</a> sayfasında anlattık.';
        }
        if ($free !== null) {
            $parts[] = 'Önce kliniğinizin bugünkü durumunu görmek isterseniz <a href="'.e((string) $free->url).'">ücretsiz ön değerlendirme</a> isteyebilirsiniz; klinik adınız yeterlidir.';
        }
        $action['article']['html'] = rtrim($html)."\n".'<h2 data-moxdop="sonraki-adim">Kliniğiniz için sıradaki adım</h2>'."\n".'<p>'.implode(' ', $parts).'</p>';
        $suggestion->forceFill(['action' => $action])->save();
    }

    /**
     * The same article as an Instagram post (portrait cover, caption from the article). yakup 2026-11-13: Moximu's
     * social posts need no approval, so it is approved here with a fixed time 90 minutes after the article's slot
     * (the article is live by then) and stays outside the daily post line.
     */
    public function instagramDraft(Suggestion $suggestion, CarbonImmutable $slot, ?int $approver = null): void
    {
        if (data_get($suggestion->action, 'daily.instagram_id') !== null) {
            return;
        }
        try {
            $brand = Brand::query()->find($suggestion->brand_id);
            $style = BrandCover::styleOf($brand);
            if ($style === null) {
                return;
            }
            $words = BrandCover::words($suggestion, (string) data_get($suggestion->action, 'image.motif') ?: null);
            if (is_string(data_get($suggestion->action, 'image.motif'))) {
                $words['motif'] = (string) data_get($suggestion->action, 'image.motif');
            }
            $article = (array) data_get($suggestion->action, 'article', []);
            $title = trim((string) ($article['title'] ?? $suggestion->title));
            $excerpt = trim(html_entity_decode(strip_tags((string) ($article['excerpt'] ?? $article['meta_description'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $caption = $title."\n\n".($excerpt !== '' ? $excerpt."\n\n" : '').'Rehberin tamamı moximu.com/rehber sayfasında.'."\n\n".'#klinikpazarlama #sağlıkpazarlaması #moximu';
            // LinkedIn gets its own corporate text with the article's address, so the page's link card (cover, title)
            // shows under the post; until the LinkedIn connection is approved it waits on /sosyal as "Metni kopyala".
            $link = self::articleUrl($suggestion);
            $linkedin = $title."\n\n".($excerpt !== '' ? $excerpt."\n\n" : '').($link !== null ? 'Yazının tamamı: '.$link."\n\n" : '').'#klinikpazarlama #sağlıkpazarlaması #dijitalpazarlama';
            $post = app(SocialPlanner::class)->create([
                'kind' => 'image', 'pillar' => 'rehber', 'title' => $title, 'caption' => $caption, 'linkedin_text' => $linkedin,
                'publish_at' => $slot->addMinutes(self::INSTAGRAM_AFTER_MINUTES), 'created_by' => 'blog',
            ], [['bytes' => app(BrandCover::class)->render($style, $words, true), 'name' => 'rehber-'.$suggestion->id.'.jpg']]);
            if ($approver !== null) {
                $post->update(['pinned' => true]);
                app(SocialPlanner::class)->approve($post->fresh(), $approver);
            }
            $action = (array) $suggestion->fresh()->action;
            $action['daily']['instagram_id'] = (int) $post->id;
            $suggestion->forceFill(['action' => $action])->save();
        } catch (Throwable $e) {
            Log::info('Daily blog Instagram draft skipped.', ['suggestion' => $suggestion->id, 'error' => $e->getMessage()]);
        }
    }

    /** The article's public address once it is out: an updated page keeps its own, a new post lives under /rehber/. */
    public static function articleUrl(Suggestion $suggestion): ?string
    {
        $target = (string) data_get($suggestion->action, 'target_url', '');
        if (data_get($suggestion->action, 'kind') === 'update' && preg_match('~^https?://~', $target) === 1) {
            return $target;
        }
        $slug = trim((string) data_get($suggestion->action, 'article.slug', ''), '/');
        $site = DigitalAsset::query()->find((int) data_get($suggestion->action, 'site_id', $suggestion->target_id));
        $base = rtrim((string) ($site?->primary_url ?? ''), '/');

        return $slug !== '' && $base !== '' ? $base.'/rehber/'.$slug.'/' : null;
    }

    /**
     * Questions clinics asked on the agency's WhatsApp in the last 60 days (topic source for its own blog): incoming
     * messages with a question mark, numbers and addresses masked, at most 25.
     *
     * @return list<string>
     */
    public static function readerQuestions(?Brand $brand): array
    {
        if (BrandCover::styleOf($brand) !== BrandCover::MOXIMU_REHBER) {
            return []; // the WhatsApp inbox is Moximu's own
        }
        try {
            $out = [];
            WhatsAppMessage::query()->where('direction', 'incoming')->where('is_history', false)->where('sent_at', '>=', now()->subDays(60))
                ->orderByDesc('id')->limit(400)->get(['id', 'body'])
                ->each(function (WhatsAppMessage $m) use (&$out): bool {
                    $text = trim((string) preg_replace('/\s+/u', ' ', (string) $m->body));
                    if (! str_contains($text, '?') || mb_strlen($text) < 20 || mb_strlen($text) > 240) {
                        return true;
                    }
                    $text = (string) preg_replace(['/\+?\d[\d\s().-]{6,}\d/u', '/[\w.+-]+@[\w-]+\.[\w.]+/u', '~https?://\S+~u'], ['[numara]', '[e-posta]', '[bağlantı]'], $text);
                    $out[SeoText::fold($text)] = $text;

                    return count($out) < 25;
                });

            return array_values($out);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * The rules the writer and the idea run get for this line (the gate checks the same things after writing).
     *
     * @return list<string>
     */
    public static function houseRules(?Brand $brand): array
    {
        if (! self::on($brand)) {
            return [];
        }

        return [
            'This blog publishes two articles a day without a person reading them first; the quality gate below decides.',
            'Never give a price, fee, budget figure or currency (no ₺, TL, lira, KDV, euro, dolar): prices change and the blog must stay true. Explain what a cost depends on instead.',
            'Title at most 60 characters, no colon, a plain question or statement in corporate Turkish.',
            'Never name MoxDOP. No guarantee or sure-result wording (garanti, kesin sonuç, 1. sıra sözü).',
            'Name '.$brand->name.' at least once where its own way of working fits naturally (one or two sentences, no sales pitch).',
            'Say "Google İşletme Profili", never "Google kartı". Follow the health advertising rules (2025 yönetmelik).',
            'Topics: the morning article is a lasting guide; the afternoon one a branch and/or city article (e.g. a dental clinic in İzmir) or a refresh of a page that ranks 8-20.',
            'Prefer subjects from reader_questions (what clinics ask on WhatsApp) and Search Console searches the site has no page for; never a subject the site already covers.',
        ];
    }

    /**
     * Pazartesi raporu: last week's articles of the line, which got clicks, which did not, as one push to the owner.
     *
     * @return string|null the message (null when the line had nothing)
     */
    public function weeklyReport(?CarbonImmutable $today = null): ?string
    {
        $today ??= CarbonImmutable::now('Europe/Istanbul')->startOfDay();
        $from = $today->startOfWeek(CarbonImmutable::MONDAY)->subWeek();
        $to = $from->addDays(7);
        $lines = [];
        foreach (self::sites() as $site) {
            $settings = self::settings(Brand::query()->find($site->brand_id));
            $sent = self::ideas($site)->whereNotNull('action->daily->slot')->get()->filter(function (Suggestion $s) use ($from, $to): bool {
                $slot = (string) data_get($s->action, 'daily.slot');

                return $slot >= $from->format('Y-m-d H:i') && $slot < $to->format('Y-m-d H:i');
            });
            $recent = self::ideas($site)->whereNotNull('action->daily->slot')->get()
                ->filter(fn (Suggestion $s): bool => (string) data_get($s->action, 'daily.slot') >= $today->subDays(42)->format('Y-m-d H:i'));
            $posts = app(ContentPosts::class);
            $ids = $posts->postIds($recent);
            $states = $posts->states((int) $site->id, array_values(array_filter(array_map(fn (array $l): int => (int) (reset($l) ?: 0), $ids))));
            $links = [];
            foreach ($recent as $idea) {
                $postId = (int) (reset($ids[(int) $idea->id]) ?: 0);
                if ($postId > 0 && filled($states[$postId]['link'] ?? null)) {
                    $links[(int) $idea->id] = (string) $states[$postId]['link'];
                }
            }
            $metrics = $posts->metrics(array_values($links), $site);
            $clicked = [];
            $silent = [];
            foreach ($recent as $idea) {
                $url = $links[(int) $idea->id] ?? null;
                $m = $url !== null ? ($metrics[$url] ?? null) : null;
                $title = (string) data_get($idea->action, 'article.title', $idea->title);
                if ($m !== null && $m['clicks30'] > 0) {
                    $clicked[$title] = $m['clicks30'];
                } elseif ((string) data_get($idea->action, 'daily.slot') < $today->subDays(21)->format('Y-m-d H:i') && ($m['impressions30'] ?? 0) === 0) {
                    $silent[] = $title;
                }
            }
            arsort($clicked);
            $room = 7 * (int) ($settings['per_day'] ?? 2);
            $line = $site->name.': geçen hafta '.$sent->count().'/'.$room.' yazı yayına çıktı.';
            if ($clicked !== []) {
                $line .= ' Tıklama alan: '.implode(', ', array_map(fn (string $t, int $c): string => '«'.$t.'» '.$c, array_slice(array_keys($clicked), 0, 3), array_slice(array_values($clicked), 0, 3))).'.';
            }
            if ($silent !== []) {
                $line .= ' 3 haftada hiç gösterim almayan: '.count($silent).' yazı (yenileme sırasına girer).';
            }
            $lines[] = $line;
        }
        if ($lines === []) {
            return null;
        }
        $message = implode("\n", $lines);
        app(PushNotifier::class)->send('daily-blog-week:'.$from->toDateString(), 'Blog haftası', $message, 'medium', url('/icerik'), 24 * 6, true);

        return $message;
    }
}
