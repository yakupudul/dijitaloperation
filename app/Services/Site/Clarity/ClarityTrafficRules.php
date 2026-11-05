<?php

namespace App\Services\Site\Clarity;

use App\Models\ClarityProject;
use App\Models\DigitalAsset;
use App\Models\Suggestion;
use App\Services\Work\WorkVerifier;
use Illuminate\Support\Facades\DB;

/**
 * Clarity traffic behaviour → work (no AI; yakup, 2026-11-05: Clarity is there to improve the site and bring the
 * client more patients). Over the last 7 days, paid traffic (Google Ads or Meta, one row per channel × source ×
 * campaign) with enough sessions that leaves at once, does not read the landing page or is largely bots becomes a
 * `clarity` suggestion on the site in Genel işler › Teknik sağlık. The landing page is fixed on the site; the ad side
 * is a recommendation only (MoxDOP never writes to an ad account from here). Fail and pass lines differ so a campaign
 * at the line does not flip daily; a clear pass closes the work or confirms the operator's "Yaptım".
 */
final class ClarityTrafficRules
{
    public const int WINDOW_DAYS = 7;

    public const int MIN_SESSIONS = 30;

    /**
     * rule => column, fail line, pass line, priority, label, what to do. `scroll` is "lower is worse"; `bots` is the
     * bot share of sessions.
     *
     * @var array<string, array{column: string, fail: float, pass: float, priority: int, label: string, why: string}>
     */
    public const array RULES = [
        'bots' => ['column' => 'bot_pct', 'fail' => 30.0, 'pass' => 15.0, 'priority' => 1, 'label' => 'Reklam trafiğinde bot şüphesi',
            'why' => 'bütçe gerçek olmayan ziyaretlere gidiyor olabilir. Google Ads\'te yerleşim / ağ raporuna ve geçersiz tıklamalara, Meta\'da Audience Network yerleşimine bak.'],
        'quickback' => ['column' => 'quickback_pct', 'fail' => 25.0, 'pass' => 15.0, 'priority' => 2, 'label' => 'Reklamdan gelen hemen dönüyor',
            'why' => 'reklamın vaadi ile sayfanın ilk ekranı uyuşmuyor. Reklam metnindeki hizmet, şehir ve teklif ilk ekranda görünsün; arama ve WhatsApp adımı ilk ekranda olsun.'],
        'scroll' => ['column' => 'scroll_depth', 'fail' => 30.0, 'pass' => 40.0, 'priority' => 3, 'label' => 'Reklam ziyaretçisi sayfayı okumuyor',
            'why' => 'ziyaretçi ilk ekranda kalıyor. Açılış sayfasında girişi kısalt, iletişim adımını ve güven unsurlarını (yorumlar, hekim) yukarı al.'],
    ];

    public function __construct(private readonly WorkVerifier $verifier) {}

    /** Ads platform of a Clarity traffic row: google_ads, meta or null (not paid). */
    public static function platform(string $channel, string $source, string $campaign): ?string
    {
        $channel = mb_strtolower($channel);
        $source = mb_strtolower($source);
        $google = (bool) preg_match('/google|adwords|gclid|youtube/u', $source);
        $meta = (bool) preg_match('/facebook|instagram|^fb$|^ig$|meta|audience/u', $source);
        $paid = str_contains($channel, 'paid') || str_contains($channel, 'cpc') || str_contains($channel, 'ads')
            || ($meta && $campaign !== '(yok)');

        return match (true) {
            ! $paid => null,
            $google => 'google_ads',
            $meta => 'meta',
            default => null,
        };
    }

    /** @return array{failing: int, passed: int} */
    public function sync(DigitalAsset $site): array
    {
        if ($site->brand_id === null) {
            return ['failing' => 0, 'passed' => 0];
        }
        $dashboard = ClarityProject::query()->where('website_asset_id', $site->id)->first()?->dashboardUrl();
        $passed = [];
        $failing = [];
        foreach ($this->groups($site) as $key => $stats) {
            if ($stats['sessions'] < self::MIN_SESSIONS) {
                continue;
            }
            foreach (self::RULES as $rule => $line) {
                $value = $stats[$line['column']];
                if ($value === null) {
                    continue;
                }
                $check = 'traffic:'.$rule.':'.substr($key, 0, 16);
                $bad = $rule === 'scroll' ? $value < $line['fail'] : $value >= $line['fail'];
                $good = $rule === 'scroll' ? $value >= $line['pass'] : $value < $line['pass'];
                if ($good) {
                    $passed[] = $check;
                } elseif ($bad) {
                    $failing[] = $check;
                    $this->upsert($site, $check, $rule, (float) $value, $stats, $dashboard);
                }
            }
        }
        $this->verifier->checks($site, 'search', ClarityRules::TYPE, $passed, $failing);

        return ['failing' => count($failing), 'passed' => count($passed)];
    }

    /**
     * Session-weighted 7-day figures of each paid channel × source × campaign.
     *
     * @return array<string, array{platform: string, channel: string, source: string, campaign: string, sessions: int, bot_pct: ?float, quickback_pct: ?float, scroll_depth: ?float}>
     */
    public function groups(DigitalAsset $site): array
    {
        $rows = DB::table('clarity_traffic_days')->where('website_asset_id', $site->id)
            ->where('day', '>=', now('Europe/Istanbul')->subDays(self::WINDOW_DAYS)->toDateString())
            ->get(['key_hash', 'channel', 'source', 'campaign', 'sessions', 'bot_sessions', 'quickback_pct', 'scroll_depth']);
        $out = [];
        foreach ($rows->groupBy('key_hash') as $key => $group) {
            $first = $group->first();
            $platform = self::platform((string) $first->channel, (string) $first->source, (string) $first->campaign);
            if ($platform === null) {
                continue;
            }
            $sessions = (int) $group->sum('sessions');
            $mean = function (string $column) use ($group): ?float {
                $weighted = $group->filter(fn (object $r): bool => $r->{$column} !== null);
                $weight = $weighted->sum(fn (object $r): int => max(1, (int) $r->sessions));

                return $weighted->isEmpty() ? null : round($weighted->sum(fn (object $r): float => (float) $r->{$column} * max(1, (int) $r->sessions)) / $weight, 1);
            };
            $out[(string) $key] = ['platform' => $platform, 'channel' => (string) $first->channel, 'source' => (string) $first->source, 'campaign' => (string) $first->campaign,
                'sessions' => $sessions, 'bot_pct' => $sessions > 0 ? round(100 * (int) $group->sum('bot_sessions') / $sessions, 1) : null,
                'quickback_pct' => $mean('quickback_pct'), 'scroll_depth' => $mean('scroll_depth')];
        }

        return $out;
    }

    /** @param  array{platform: string, channel: string, source: string, campaign: string, sessions: int}  $stats */
    private function upsert(DigitalAsset $site, string $check, string $rule, float $value, array $stats, ?string $dashboard): void
    {
        $line = self::RULES[$rule];
        $platform = $stats['platform'] === 'meta' ? 'Meta' : 'Google Ads';
        $what = $stats['campaign'] !== '(yok)' ? '«'.$stats['campaign'].'» kampanyası' : $stats['source'].' reklam trafiği';
        $figure = match ($rule) {
            'bots' => 'oturumların %'.self::number($value).'\'i bot',
            'scroll' => 'ortalama %'.self::number($value).' aşağı iniliyor',
            default => 'oturumların %'.self::number($value).'\'inde hızlı geri dönüş',
        };
        $reason = $platform.' · '.$what.': son '.self::WINDOW_DAYS.' günde '.$stats['sessions'].' oturum, '.$figure.'; '.$line['why'];
        $fingerprint = hash('sha256', implode('|', [$site->brand_id, ClarityRules::DECISION, $site->id, $check]));
        $suggestion = Suggestion::query()->where('brand_id', $site->brand_id)->where('fingerprint', $fingerprint)->first() ?? new Suggestion;
        $reopen = $suggestion->status === Suggestion::RECHECK
            || ($suggestion->status === Suggestion::APPLIED && $suggestion->verification === Suggestion::VERIFY_AUTO);
        $fields = [
            'brand_id' => $site->brand_id, 'channel' => 'search', 'decision_key' => ClarityRules::DECISION, 'fingerprint' => $fingerprint,
            'material_hash' => hash('sha256', $check), 'title' => mb_substr($line['label'].': '.$what, 0, 160), 'reason' => mb_substr($reason, 0, 240),
            'priority' => $line['priority'], 'evidence' => [['kind' => 'clarity', 'value' => $figure, 'source' => 'Microsoft Clarity · '.$platform]],
            'action_type' => ClarityRules::TYPE, 'target_type' => 'site', 'target_id' => (int) $site->id, 'page_id' => null,
            'action' => ['site_id' => (int) $site->id, 'check' => $check, 'rule' => 'traffic_'.$rule, 'value' => $value, 'platform' => $stats['platform'],
                'traffic' => ['channel' => $stats['channel'], 'source' => $stats['source'], 'campaign' => $stats['campaign']],
                'sessions' => $stats['sessions'], 'clarity_url' => $dashboard],
            'first_seen_at' => $suggestion->first_seen_at ?? now(), 'last_seen_at' => now(),
        ];
        if (! $suggestion->exists || $reopen) {
            $fields += ['status' => Suggestion::OPEN, 'applied_at' => null, 'resolved_at' => null, 'resolved_by' => null, 'verification' => null, 'verified_at' => null];
        }
        $suggestion->forceFill($fields)->save();
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, ',', ''), '0'), ',');
    }
}
