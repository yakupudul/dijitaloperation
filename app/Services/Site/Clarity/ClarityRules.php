<?php

namespace App\Services\Site\Clarity;

use App\Models\ClarityProject;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Models\Suggestion;
use App\Services\SeoTasks\SeoText;
use App\Services\Work\WorkVerifier;
use Illuminate\Support\Facades\DB;

/**
 * Clarity behaviour → work (no AI). Over the last 7 days, a page with enough sessions and a bad share of rage clicks,
 * dead clicks, quick backs, JavaScript errors or shallow scrolling becomes a `clarity` suggestion on the site (channel
 * search, the page in page_id), shown in Genel işler › Teknik sağlık and the site's Yapılacaklar. Each rule has a
 * fail and a (lower) pass line so a page hovering at the line does not flip daily; a clear pass closes the work by
 * itself or confirms the operator's "Yaptım" (WorkVerifier), a page without enough sessions changes nothing.
 */
final class ClarityRules
{
    public const string TYPE = 'clarity';

    public const string DECISION = 'site.clarity';

    public const int WINDOW_DAYS = 7;

    public const int MIN_SESSIONS = 30;

    /**
     * rule => column, fail line, pass line, priority, label, what to look at. `scroll` is "lower is worse".
     *
     * @var array<string, array{column: string, fail: float, pass: float, priority: int, label: string, why: string}>
     */
    public const array RULES = [
        'script' => ['column' => 'script_error_pct', 'fail' => 5.0, 'pass' => 2.0, 'priority' => 1, 'label' => 'JavaScript hatası',
            'why' => 'form, buton ya da menü çalışmıyor olabilir. Clarity kayıtlarında «JavaScript hatası» filtresiyle bak.'],
        'rage' => ['column' => 'rage_pct', 'fail' => 4.0, 'pass' => 2.0, 'priority' => 1, 'label' => 'Öfkeli tıklama',
            'why' => 'ziyaretçi aynı yere tekrar tekrar tıklıyor: çalışmayan ya da geç açılan buton / bağlantı. Isı haritasına bak.'],
        'dead' => ['column' => 'dead_pct', 'fail' => 12.0, 'pass' => 7.0, 'priority' => 2, 'label' => 'Tıklanan ama çalışmayan alan',
            'why' => 'bağlantı sanılan görsel ya da yazı tıklanıyor. O alanı bağlantı yap ya da görünümünü değiştir.'],
        'quickback' => ['column' => 'quickback_pct', 'fail' => 20.0, 'pass' => 12.0, 'priority' => 2, 'label' => 'Hızlı geri dönüş',
            'why' => 'sayfa beklenen bilgiyi hemen vermiyor. İlk ekranda hizmet, fiyat / süre ve iletişim adımı görünsün.'],
        'scroll' => ['column' => 'scroll_depth', 'fail' => 35.0, 'pass' => 45.0, 'priority' => 3, 'label' => 'Sayfa okunmuyor',
            'why' => 'çoğu ziyaretçi sayfanın üst kısmında kalıyor. İletişim / randevu adımını yukarı al, girişi kısalt.'],
    ];

    public function __construct(private readonly WorkVerifier $verifier) {}

    /** @return array{failing: int, passed: int} */
    public function sync(DigitalAsset $site): array
    {
        if ($site->brand_id === null) {
            return ['failing' => 0, 'passed' => 0];
        }
        $pages = $this->pages($site);
        $paths = Page::query()->whereIn('id', array_keys($pages) ?: [0])->get(['id', 'url', 'path'])
            ->mapWithKeys(fn (Page $p): array => [(int) $p->id => '/'.ltrim((string) ($p->path ?: SeoText::urlPath((string) $p->url)), '/')])->all();
        $dashboard = ClarityProject::query()->where('website_asset_id', $site->id)->first()?->dashboardUrl();
        $passed = [];
        $failing = [];
        foreach ($pages as $pageId => $stats) {
            if ($stats['all']['sessions'] < self::MIN_SESSIONS) {
                continue;
            }
            foreach (self::RULES as $rule => $line) {
                $value = $stats['all'][$line['column']];
                if ($value === null) {
                    continue;
                }
                $check = $rule.':'.$pageId;
                $bad = $rule === 'scroll' ? $value < $line['fail'] : $value >= $line['fail'];
                $good = $rule === 'scroll' ? $value >= $line['pass'] : $value < $line['pass'];
                if ($good) {
                    $passed[] = $check;
                } elseif ($bad) {
                    $failing[] = $check;
                    $this->upsert($site, (int) $pageId, $paths[$pageId] ?? '/', $rule, $value, $stats, $dashboard);
                }
            }
        }
        $this->verifier->checks($site, 'search', self::TYPE, $passed, $failing);

        return ['failing' => count($failing), 'passed' => count($passed)];
    }

    /**
     * Session-weighted 7-day figures per page: all devices and mobile.
     *
     * @return array<int, array{all: array<string, mixed>, mobile: array<string, mixed>}>
     */
    public function pages(DigitalAsset $site): array
    {
        $columns = array_unique(array_column(self::RULES, 'column'));
        $rows = DB::table('clarity_page_days')->where('website_asset_id', $site->id)->whereNotNull('page_id')
            ->where('day', '>=', now('Europe/Istanbul')->subDays(self::WINDOW_DAYS)->toDateString())
            ->get(['page_id', 'device', 'sessions', ...$columns]);
        $out = [];
        foreach ($rows->groupBy('page_id') as $pageId => $pageRows) {
            $out[(int) $pageId] = ['all' => $this->mean($pageRows->all(), $columns), 'mobile' => $this->mean($pageRows->where('device', 'mobile')->all(), $columns)];
        }

        return $out;
    }

    /**
     * @param  array<int, object>  $rows
     * @param  list<string>  $columns
     * @return array<string, mixed>
     */
    private function mean(array $rows, array $columns): array
    {
        $out = ['sessions' => (int) array_sum(array_map(fn (object $r): int => (int) $r->sessions, $rows))];
        foreach ($columns as $column) {
            $weighted = array_filter($rows, fn (object $r): bool => $r->{$column} !== null);
            $weight = array_sum(array_map(fn (object $r): int => max(1, (int) $r->sessions), $weighted));
            $out[$column] = $weighted === [] ? null
                : round(array_sum(array_map(fn (object $r): float => (float) $r->{$column} * max(1, (int) $r->sessions), $weighted)) / $weight, 1);
        }

        return $out;
    }

    /** @param  array{all: array<string, mixed>, mobile: array<string, mixed>}  $stats */
    private function upsert(DigitalAsset $site, int $pageId, string $path, string $rule, float $value, array $stats, ?string $dashboard): void
    {
        $line = self::RULES[$rule];
        $mobile = $stats['mobile'][$line['column']] ?? null;
        $figure = $rule === 'scroll' ? 'ortalama %'.self::number($value).' aşağı iniliyor' : 'oturumların %'.self::number($value).'\'inde '.mb_strtolower($line['label']);
        $mobileNote = $mobile !== null && $stats['mobile']['sessions'] >= 10 ? ' (mobil %'.self::number((float) $mobile).')' : '';
        $reason = 'Son '.self::WINDOW_DAYS.' günde '.$stats['all']['sessions'].' oturum, '.$figure.$mobileNote.'; '.$line['why'];
        $fingerprint = hash('sha256', implode('|', [$site->brand_id, self::DECISION, $site->id, $rule, $pageId]));
        $suggestion = Suggestion::query()->where('brand_id', $site->brand_id)->where('fingerprint', $fingerprint)->first() ?? new Suggestion;
        $reopen = $suggestion->status === Suggestion::RECHECK
            || ($suggestion->status === Suggestion::APPLIED && $suggestion->verification === Suggestion::VERIFY_AUTO);
        $fields = [
            'brand_id' => $site->brand_id, 'channel' => 'search', 'decision_key' => self::DECISION, 'fingerprint' => $fingerprint,
            'material_hash' => hash('sha256', $rule.'|'.$pageId), 'title' => mb_substr($line['label'].': '.$path, 0, 160), 'reason' => mb_substr($reason, 0, 240),
            'priority' => $line['priority'], 'evidence' => [['kind' => 'clarity', 'value' => $figure.$mobileNote, 'source' => 'Microsoft Clarity']],
            'action_type' => self::TYPE, 'target_type' => 'site', 'target_id' => (int) $site->id, 'page_id' => $pageId,
            'action' => ['site_id' => (int) $site->id, 'check' => $rule.':'.$pageId, 'rule' => $rule, 'value' => $value, 'mobile' => $mobile,
                'sessions' => $stats['all']['sessions'], 'clarity_url' => $dashboard],
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
