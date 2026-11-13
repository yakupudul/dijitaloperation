<?php

namespace App\Services\Social;

use App\Models\Agreement;
use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\GbpReview;
use App\Models\SocialPost;
use App\Services\Assistant\PushNotifier;
use App\Services\Site\BrandCover;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Hekim ve klinik gönderileri (yakup 2026-11-13). Two posts are prepared by themselves, everything else about clients
 * is never posted:
 *
 *  - Hoş geldiniz: when a clinic becomes a client (its first agreement), a plain welcome card with its own name.
 *  - Hekim yorumu: when a hekim writes a Google review on Moximu's own Business Profile, the review as a card.
 *
 * "Hekimlerle kliniklerle alakalı ne paylaşacaksan sadece onlar benim onayımda olsun": both wait for the operator's
 * approval on /sosyal (a push tells him), dated the same day outside the daily line (pinned). No method, no numbers,
 * no invented words; former clients appear only on the website.
 */
final class ClientPosts
{
    /** Reviews older than this (by Google's date) are history, never posted. */
    public const int REVIEW_DAYS = 7;

    /** An agreement that started longer ago than this is an old client's record, not a welcome. */
    public const int NEW_DAYS = 30;

    /** Sectors that make a brand a clinic (the agency's clients); a brand outside them gets no welcome post. */
    private const string HEALTH = '/(diş|dis |sağlık|saglik|estetik|klinik|dermat|kadın|kadin|doğum|dogum|psiko|diyet|fizyo|hastane|tıp|tip |hekim|doktor|güzellik|plastik|göz|kulak|ortodon|implant|veteriner|poliklinik|muayene)/iu';

    /** A reviewer who writes as a hekim: title in the name, or words only a clinic owner would write. */
    private const string TITLE = '/^(prof|doç|doc|dr|dt|uzm|op|psk|dyt|fzt)\.?\s/iu';

    private const string HEKIM_WORDS = '/(kliniğim|kliniğimiz|kliniğimizin|kliniğimize|muayenehanem|hastalarım|hastalarımız|diş hekimi olarak|hekim olarak|doktor olarak|meslektaş|polikliniğimiz|merkezimiz(in)? (web|site|google))/iu';

    /** A new agreement (Agreement::created): the brand's first one makes a welcome post. */
    public function welcome(Agreement $agreement): ?SocialPost
    {
        try {
            $brand = $agreement->brand_id !== null ? Brand::query()->find($agreement->brand_id) : null;
            if ($brand === null || BrandCover::styleOf($brand) !== null || ! in_array($agreement->kind, ['monthly', 'term', 'one_time', 'partnership'], true)) {
                return null;
            }
            // An old client written into Finans for the first time is not a new client.
            if ($agreement->starts_on !== null && CarbonImmutable::parse((string) $agreement->starts_on)->lt(CarbonImmutable::now()->subDays(self::NEW_DAYS))) {
                return null;
            }
            if (Agreement::query()->where('brand_id', $brand->id)->whereKeyNot($agreement->id)->exists() || $this->exists('welcome:'.$brand->id)) {
                return null;
            }
            $field = (string) ($brand->sectorCategory?->name ?? '');
            if (preg_match(self::HEALTH, self::lower($field.' '.$brand->name)) !== 1) {
                return null;
            }
            $name = self::displayName((string) $brand->name);
            $caption = 'Hoş geldiniz, '.$name.".\n\nBugün birlikte çalışmaya başladık. Güveniniz için teşekkür ederiz; hastalarınızın sizi aradığı her yerde doğru ve güçlü görünmeniz için buradayız.\n\nİyi ki birlikteyiz.\n\n#moximu #klinikpazarlama";
            $bytes = app(BrandCover::class)->welcomeCard($name, $field !== '' ? $field : 'Klinik');

            return $this->draft('welcome:'.$brand->id, 'Hoş geldiniz: '.$name, $caption, $bytes, 'hos-geldiniz-'.$brand->id.'.jpg',
                'Hoş geldiniz gönderisi hazır', $name.' için hoş geldiniz gönderisi onayını bekliyor.');
        } catch (Throwable $e) {
            Log::info('Welcome post skipped.', ['agreement' => $agreement->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** Every content-autopilot round: new hekim reviews on Moximu's own profile become review posts. */
    public function reviews(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $own = Brand::query()->whereNotNull('auto_blog')->get()->filter(fn (Brand $b): bool => BrandCover::styleOf($b) !== null)->pluck('id');
        if ($own->isEmpty()) {
            return 0;
        }
        $assets = DigitalAsset::query()->whereIn('brand_id', $own->all())->pluck('id');
        $made = 0;
        GbpReview::query()->whereIn('digital_asset_id', $assets->all())
            ->where('create_time', '>=', $now->subDays(self::REVIEW_DAYS))
            ->whereNotNull('comment')->orderBy('create_time')->limit(50)->get()
            ->each(function (GbpReview $review) use (&$made): void {
                $name = trim((string) data_get($review->reviewer, 'displayName', ''));
                $comment = self::reviewText((string) $review->comment);
                $stars = self::stars((string) $review->star_rating);
                if ($name === '' || $comment === '' || $stars < 4 || ! self::isHekim($name, $comment) || $this->exists('review:'.$review->review_id)) {
                    return;
                }
                try {
                    $caption = "Google'da bizim için yazılan bir yorum.\n\n“".$comment."”\n\n".$name."\n\nYorumunuz için teşekkür ederiz.\n\n#moximu #klinikpazarlama";
                    $bytes = app(BrandCover::class)->reviewCard($comment, $name, $stars);
                    $this->draft('review:'.$review->review_id, 'Hekim yorumu: '.$name, $caption, $bytes, 'yorum-'.$review->id.'.jpg',
                        'Hekim yorumu gönderisi hazır', $name.' Google yorumu gönderi olarak onayını bekliyor.');
                    $made++;
                } catch (Throwable $e) {
                    Log::info('Review post skipped.', ['review' => $review->id, 'error' => $e->getMessage()]);
                }
            });

        return $made;
    }

    public static function isHekim(string $name, string $comment): bool
    {
        return preg_match(self::TITLE, self::lower(trim($name))) === 1 || preg_match(self::HEKIM_WORDS, self::lower($comment)) === 1;
    }

    /** Turkish lower case ("DİŞ KLİNİĞİ" → "diş kliniği"), so the patterns match names written in capitals. */
    private static function lower(string $text): string
    {
        return mb_strtolower(strtr($text, ['I' => 'ı', 'İ' => 'i']), 'UTF-8');
    }

    /** "Dr. Dt. Burçin ÖNCÜL" → "Dr. Dt. Burçin Öncül"; a long brand name keeps its part before " - " or " | ". */
    public static function displayName(string $name): string
    {
        $name = trim((string) preg_split('/\s+[-|–]\s+/u', $name)[0]);
        $words = array_map(function (string $w): string {
            if (mb_strlen($w) < 3 || $w !== mb_strtoupper($w, 'UTF-8') || preg_match('/\p{L}/u', $w) !== 1) {
                return $w;
            }
            $lower = mb_strtolower(strtr($w, ['I' => 'ı', 'İ' => 'i']), 'UTF-8');
            $first = mb_substr($lower, 0, 1);
            $upper = ['i' => 'İ', 'ı' => 'I'][$first] ?? mb_strtoupper($first, 'UTF-8');

            return $upper.mb_substr($lower, 1);
        }, preg_split('/\s+/u', $name) ?: []);

        return implode(' ', $words);
    }

    /** Google's text without its "(Translated by Google)" part. */
    private static function reviewText(string $comment): string
    {
        $comment = (string) preg_replace('/\(Translated by Google\).*$/su', '', $comment);
        $comment = (string) preg_replace('/\(Original\)\s*/u', '', $comment);

        return Str::limit(trim((string) preg_replace('/\s+/u', ' ', $comment)), 600, '…');
    }

    private static function stars(string $rating): int
    {
        return ['ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5][strtoupper($rating)] ?? 0;
    }

    private function exists(string $key): bool
    {
        return SocialPost::withoutGlobalScopes()->where('note', 'client:'.$key)->exists();
    }

    /**
     * A draft dated the same day outside the line: 90 minutes from now (never before 10:00, never after 22:00 Istanbul;
     * late ones take the next day's 12:30). It waits for his click; approved after its time it goes out at once.
     */
    private function draft(string $key, string $title, string $caption, string $bytes, string $file, string $pushTitle, string $pushBody): SocialPost
    {
        $tz = SocialPlanner::TIMEZONE;
        $at = CarbonImmutable::now($tz)->addMinutes(90);
        if ((int) $at->format('H') < 10) {
            $at = $at->setTime(10, 0);
        } elseif ((int) $at->format('H') >= 22) {
            $at = $at->addDay()->setTime(12, 30);
        }
        $post = app(SocialPlanner::class)->create([
            'kind' => 'image', 'pillar' => 'guven', 'title' => $title, 'caption' => $caption, 'publish_at' => $at, 'created_by' => 'claude',
            'platforms' => ['instagram', 'facebook'],
        ], [['bytes' => $bytes, 'name' => $file]]);
        $post->update(['pinned' => true, 'note' => 'client:'.$key]);
        app(PushNotifier::class)->send('client-post:'.$key, $pushTitle, $pushBody, 'medium', url('/sosyal'), 24, true);

        return $post;
    }
}
