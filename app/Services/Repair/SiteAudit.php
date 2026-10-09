<?php

namespace App\Services\Repair;

use App\Models\DigitalAsset;
use App\Models\Page;
use App\Models\Suggestion;
use App\Services\SeoTasks\SeoText;
use App\Services\Site\PageTechnical;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Web onarımı (Onarım Faz 3): every indexable WordPress page of every site is checked, without AI, for a missing,
 * too short, too long or duplicate SEO title and meta description. Each affected page gets one "seo_fields" suggestion
 * (prepared overnight by "AI ile yap", approved on the Onarım masası); the suggestion closes by itself when the page
 * no longer has the problem. Pages that answer an error, are noindex or canonicalised elsewhere are left out.
 */
final class SiteAudit
{
    public const string TYPE = 'seo_fields';

    public const int TITLE_MIN = 25;

    public const int TITLE_MAX = 60;

    public const int DESCRIPTION_MIN = 70;

    public const int DESCRIPTION_MAX = 160;

    /** @return array{sites: int, opened: int, closed: int} */
    public function run(): array
    {
        $done = ['sites' => 0, 'opened' => 0, 'closed' => 0];
        DigitalAsset::query()->operational()->where('type', 'website')->orderBy('id')->each(function (DigitalAsset $site) use (&$done): void {
            try {
                $result = $this->audit($site);
            } catch (Throwable $e) {
                report($e);

                return;
            }
            $done['sites']++;
            $done['opened'] += $result['opened'];
            $done['closed'] += $result['closed'];
        });

        return $done;
    }

    /** @return array{opened: int, closed: int} */
    public function audit(DigitalAsset $site): array
    {
        $pages = Page::query()->where('website_asset_id', $site->id)->where('is_indexable', true)->whereNotNull('wp_post_id')
            ->get(['id', 'website_asset_id', 'url', 'path', 'language', 'title', 'title_source', 'meta_description', 'canonical', 'is_indexable', 'wp_post_id', 'content_hash']);
        $technical = PageTechnical::many($pages);
        $pages = $pages->filter(fn (Page $p): bool => ($technical[$p->id]['status'] ?? 200) < 400 && ($technical[$p->id]['canonical_ok'] ?? true))->values();
        $titleCounts = $this->counts($pages, 'title');
        $descriptionCounts = $this->counts($pages, 'meta_description');

        $existing = Suggestion::query()->where('brand_id', $site->brand_id)->where('action_type', self::TYPE)
            ->whereIn('page_id', Page::query()->where('website_asset_id', $site->id)->select('id'))->get()->keyBy('page_id');
        $opened = 0;
        $seen = [];
        foreach ($pages as $page) {
            $problems = $this->problems($page, $titleCounts, $descriptionCounts);
            if ($problems === []) {
                continue;
            }
            $seen[] = (int) $page->id;
            $opened += $this->upsert($site, $page, $problems, $existing->get($page->id)) ? 1 : 0;
        }
        $closed = Suggestion::query()->whereIn('id', $existing->filter(fn (Suggestion $s): bool => ! in_array((int) $s->page_id, $seen, true)
            && in_array($s->status, [Suggestion::OPEN, Suggestion::RECHECK, Suggestion::SNOOZED], true))->pluck('id'))
            ->update(['status' => Suggestion::APPLIED, 'verification' => Suggestion::VERIFY_AUTO, 'verified_at' => now(), 'resolved_at' => now(),
                'operator_note' => 'Sorun sayfada kalmadı (otomatik kapandı).']);

        return ['opened' => $opened, 'closed' => $closed];
    }

    /**
     * @param  array<string, int>  $titleCounts
     * @param  array<string, int>  $descriptionCounts
     * @return list<array{field: string, code: string, text: string}>
     */
    private function problems(Page $page, array $titleCounts, array $descriptionCounts): array
    {
        $problems = [];
        $title = trim((string) $page->title);
        $description = trim((string) $page->meta_description);
        $titleLength = mb_strlen($title);
        $descriptionLength = mb_strlen($description);
        $titleKey = self::key($page, $title);
        $descriptionKey = self::key($page, $description);
        // A post title is what the SEO plugin's template ("%title% | Site name") wraps: Google shows it longer, so its
        // length says nothing about the title in search results.
        $lengthKnown = $page->title_source !== 'post';
        $problems[] = match (true) {
            $title === '' => ['field' => 'seo_title', 'code' => 'missing', 'text' => 'SEO başlığı yok'],
            ($titleCounts[$titleKey] ?? 0) > 1 => ['field' => 'seo_title', 'code' => 'duplicate', 'text' => 'Başlık '.$titleCounts[$titleKey].' sayfada aynı'],
            $lengthKnown && $titleLength > self::TITLE_MAX => ['field' => 'seo_title', 'code' => 'long', 'text' => 'Başlık '.$titleLength.' karakter (en çok '.self::TITLE_MAX.')'],
            $lengthKnown && $titleLength < self::TITLE_MIN => ['field' => 'seo_title', 'code' => 'short', 'text' => 'Başlık '.$titleLength.' karakter (en az '.self::TITLE_MIN.')'],
            default => null,
        };
        $problems[] = match (true) {
            $description === '' => ['field' => 'meta_description', 'code' => 'missing', 'text' => 'Meta açıklama yok'],
            ($descriptionCounts[$descriptionKey] ?? 0) > 1 => ['field' => 'meta_description', 'code' => 'duplicate', 'text' => 'Açıklama '.$descriptionCounts[$descriptionKey].' sayfada aynı'],
            $descriptionLength > self::DESCRIPTION_MAX => ['field' => 'meta_description', 'code' => 'long', 'text' => 'Açıklama '.$descriptionLength.' karakter (en çok '.self::DESCRIPTION_MAX.')'],
            $descriptionLength < self::DESCRIPTION_MIN => ['field' => 'meta_description', 'code' => 'short', 'text' => 'Açıklama '.$descriptionLength.' karakter (en az '.self::DESCRIPTION_MIN.')'],
            default => null,
        };

        return array_values(array_filter($problems));
    }

    /**
     * @param  list<array{field: string, code: string, text: string}>  $problems
     */
    private function upsert(DigitalAsset $site, Page $page, array $problems, ?Suggestion $existing): bool
    {
        $codes = array_map(fn (array $p): string => $p['field'].':'.$p['code'], $problems);
        $material = hash('sha256', implode('|', $codes).'|'.SeoText::fold((string) $page->title).'|'.SeoText::fold((string) $page->meta_description));
        $reason = implode('; ', array_column($problems, 'text')).'.';
        $priority = in_array('missing', array_column($problems, 'code'), true) ? 1 : (in_array('duplicate', array_column($problems, 'code'), true) ? 2 : 3);
        $values = [
            'channel' => 'search', 'decision_key' => 'repair.'.self::TYPE, 'title' => mb_substr('Başlık ve açıklamayı düzelt: '.($page->path ?: $page->url), 0, 160),
            'reason' => mb_substr($reason, 0, 240), 'priority' => $priority, 'action_type' => self::TYPE, 'target_type' => 'page', 'target_id' => $page->id,
            'page_id' => $page->id, 'material_hash' => $material, 'last_seen_at' => now(),
            'evidence' => [['kind' => 'quote', 'value' => mb_substr((string) $page->title, 0, 200) ?: 'başlık yok', 'source' => (string) $page->url]],
        ];
        if ($existing === null) {
            Suggestion::query()->create($values + [
                'brand_id' => $site->brand_id, 'fingerprint' => hash('sha256', $site->brand_id.'|repair|'.$page->id.'|'.self::TYPE),
                'status' => Suggestion::OPEN, 'first_seen_at' => now(), 'action' => ['site_id' => (int) $site->id, 'problems' => $codes],
            ]);

            return true;
        }
        if ($existing->status === Suggestion::DISMISSED || ($existing->status === Suggestion::APPLIED && $existing->material_hash === $material)) {
            return false;
        }
        $action = (array) $existing->action;
        $changed = $existing->material_hash !== $material;
        if ($changed) {
            // The page changed: an old proposal no longer fits.
            unset($action['proposal'], $action['proposal_blocked'], $action['writes']);
        }
        $reopen = $existing->status === Suggestion::APPLIED;
        $existing->forceFill($values + ['action' => array_merge($action, ['problems' => $codes])]
            + ($reopen ? ['status' => Suggestion::OPEN, 'applied_at' => null, 'verification' => null, 'verified_at' => null] : []))->save();

        return $reopen;
    }

    /**
     * @param  Collection<int, Page>  $pages
     * @return array<string, int> language + folded value => pages carrying it
     */
    private function counts(Collection $pages, string $field): array
    {
        return $pages->map(fn (Page $p): string => self::key($p, trim((string) $p->{$field})))->filter(fn (string $v): bool => $v !== '')->countBy()->all();
    }

    /** A title / description compared within its language: a TR page and its EN translation are not duplicates. */
    private static function key(Page $page, string $value): string
    {
        $folded = SeoText::fold($value);

        return $folded === '' ? '' : mb_strtolower((string) $page->language).'|'.$folded;
    }
}
