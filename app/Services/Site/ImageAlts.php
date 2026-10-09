<?php

namespace App\Services\Site;

use App\Ai\Agents\Site\ImageAltsAgent;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\Compliance\ForbiddenTerms;
use App\Services\ExternalWrites\ExternalWriteService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Görsel alt metni: WordPress images without alt text that sit on a stored page (uploaded to it or its featured image)
 * get an AI-proposed alt text (`site.image_alts`, from the file name, image title and the page), one suggestion per
 * page. Onayla → the approved SEO-fix path writes the alt texts (ADR-070, undoable). Images whose name says nothing,
 * SVGs and decorative images (icons, backgrounds, shapes) get no proposal; nothing is invented. A row closes by itself
 * once its images have alt text on the site, and an image that got one meanwhile is never overwritten.
 */
final class ImageAlts
{
    public const string TYPE = 'image_alt';

    public const string DECISION = 'site.image_alt';

    public const int MAX_IMAGES = 120;

    public const int AI_BATCH = 30;

    /** File names of decorative images (icons, backgrounds, shapes…): they need an empty alt, not a description. */
    private const string DECORATIVE = '/(^|[-_ .])(icons?|ikon|bg|background|arka-?plan|shape|divider|separator|arrow|ok-?isareti|pattern|placeholder|spacer|bullet)([-_ .\d]|$)/';

    public function __construct(private readonly SiteAi $ai) {}

    /** @return array{status: string, images: int, proposed: int} */
    public function propose(DigitalAsset $site): array
    {
        $brand = SiteScope::brandOf($site);
        if ($brand === null) {
            return ['status' => 'no_brand', 'images' => 0, 'proposed' => 0];
        }
        $this->closeResolved($site);
        if (! SiteScope::aiAllowed($brand)) {
            return ['status' => 'not_operational', 'images' => 0, 'proposed' => 0];
        }
        $images = $this->missing($site);
        if ($images->isEmpty()) {
            return ['status' => 'ready', 'images' => 0, 'proposed' => 0];
        }
        $services = SiteScope::offerings($brand)->map(fn ($o): string => $o->displayName())->values()->all();
        $terms = ForbiddenTerms::forBrand($brand);
        $forbidden = $terms->phrases();
        $proposals = [];
        $waiting = false;
        foreach ($images->chunk(self::AI_BATCH) as $batch) {
            $result = $this->ai->run(new ImageAltsAgent, [
                'brand' => (string) $brand->name, 'services' => $services, 'forbidden' => $forbidden,
                'images' => $batch->map(fn (array $i): array => ['image_id' => $i['object_id'], 'file' => $i['file'], 'title' => $i['title'],
                    'page' => ['title' => $i['page']->title, 'h1' => $i['page']->h1, 'url' => (string) $i['page']->url, 'language' => $i['page']->language]])->values()->all(),
            ]);
            if ($result['status'] === 'queued') {
                $waiting = true; // Claude (MCP): every batch is asked at once

                continue;
            }
            if ($result['status'] !== 'ready') {
                return ['status' => 'ai_'.$result['status'], 'images' => $images->count(), 'proposed' => count($proposals)];
            }
            $known = $batch->keyBy('object_id');
            foreach ((array) ($result['data']['images'] ?? []) as $row) {
                $id = is_array($row) && is_int($row['image_id'] ?? null) ? $row['image_id'] : null;
                $alt = is_array($row) ? trim(preg_replace('/\s+/u', ' ', (string) ($row['alt'] ?? '')) ?? '') : '';
                if ($id === null || ! $known->has($id) || $alt === '' || mb_strlen($alt) > 125 || $terms->blocking($alt) !== []) {
                    continue;
                }
                $proposals[$id] = $known->get($id) + ['alt' => $alt];
            }
        }
        if ($waiting) {
            return ['status' => 'queued', 'images' => $images->count(), 'proposed' => 0];
        }
        $byPage = collect($proposals)->groupBy(fn (array $p): int => (int) $p['page']->id);
        foreach ($byPage as $rows) {
            $this->upsert($site, $rows);
        }

        return ['status' => 'ready', 'images' => $images->count(), 'proposed' => count($proposals)];
    }

    /** Onayla: the proposed alt texts go to WordPress through the approved SEO-fix path (undoable). */
    public function approve(Suggestion $suggestion, User $user): int
    {
        if ($suggestion->action_type !== self::TYPE || ! in_array($suggestion->status, [Suggestion::OPEN, Suggestion::RECHECK, Suggestion::APPROVED], true)) {
            throw ValidationException::withMessages(['write' => 'Bu öneri gönderilemez.']);
        }
        $site = DigitalAsset::query()->where('type', 'website')->find((int) data_get($suggestion->action, 'site_id'));
        $images = (array) data_get($suggestion->action, 'images', []);
        if ($site !== null) {
            // An image that got its alt text on the site meanwhile is never overwritten.
            $latest = $this->latest($site, array_map(fn (array $i): int => (int) $i['image_id'], $images));
            $images = array_values(array_filter($images, fn (array $i): bool => trim((string) data_get($latest[(int) $i['image_id']] ?? [], 'alt_text', '')) === ''));
        }
        if ($site === null || $images === []) {
            throw ValidationException::withMessages(['write' => 'Gönderilecek alt metin yok (görsellerin alt metni sitede zaten var).']);
        }
        $changes = array_map(fn (array $i): array => ['type' => 'alt_text', 'object_id' => (int) $i['image_id'],
            'reference' => 'suggestion-'.$suggestion->id.'-alt-'.$i['image_id'], 'value' => (string) $i['alt']], $images);
        $write = app(ExternalWriteService::class)->requestSiteFixes($user, $site, $changes, $suggestion);
        $suggestion->forceFill([
            'status' => Suggestion::APPLIED, 'applied_at' => now(), 'resolved_by' => $user->id, 'resolved_at' => now(),
            'action' => array_merge((array) $suggestion->action, ['writes' => [...(array) data_get($suggestion->action, 'writes', []), (int) $write->id]]),
        ])->save();
        app(BrandMemoryService::class)->recordDecision($suggestion, 'onaylandı', 'Görsel alt metinleri → WordPress');

        return (int) $write->id;
    }

    /**
     * Images (latest snapshot of each attachment) without alt text that belong to a stored page of the site.
     *
     * @return Collection<int, array{object_id: int, file: string, title: string, page: Page}>
     */
    public function missing(DigitalAsset $site): Collection
    {
        $pages = Page::query()->where('website_asset_id', $site->id)->whereNotNull('wp_post_id')->get(['id', 'url', 'title', 'h1', 'language', 'wp_post_id'])->keyBy('wp_post_id');
        if ($pages->isEmpty()) {
            return collect();
        }
        $latest = DB::table('website_cms_object_snapshot')->where('digital_asset_id', $site->id)->where('object_type', 'attachment')
            ->groupBy('object_id')->selectRaw('max(id) as id');
        $featured = DB::table('website_cms_object_snapshot')->where('digital_asset_id', $site->id)->whereIn('object_id', $pages->keys()->map(fn ($id): string => (string) $id))
            ->whereNotNull('featured_media_id')->orderBy('id')->pluck('object_id', 'featured_media_id');
        // Already proposed, sent or turned down: never asked again (a sent alt text shows up in the next snapshot).
        $done = Suggestion::query()->where('brand_id', $site->brand_id)->where('action_type', self::TYPE)
            ->get(['action'])->flatMap(fn (Suggestion $s): array => array_column((array) data_get($s->action, 'images', []), 'image_id'))->map(fn ($id): int => (int) $id)->flip();

        return DB::table('website_cms_object_snapshot')->whereIn('id', $latest)->orderBy('object_id')->get(['object_id', 'parent_id', 'title', 'metadata'])
            ->map(function (object $row) use ($pages, $featured): ?array {
                $meta = json_decode((string) $row->metadata, true) ?: [];
                $mime = mb_strtolower((string) ($meta['mime_type'] ?? ''));
                if (! str_starts_with($mime, 'image/') || $mime === 'image/svg+xml' || trim((string) ($meta['alt_text'] ?? '')) !== ''
                    || self::isDecorative((string) ($meta['file'] ?? ''), (string) $row->title)) {
                    return null;
                }
                $page = $pages->get((int) ($featured[(string) $row->object_id] ?? 0)) ?? $pages->get((int) $row->parent_id);

                return $page === null ? null : ['object_id' => (int) $row->object_id, 'file' => basename((string) ($meta['file'] ?? '')), 'title' => (string) $row->title, 'page' => $page];
            })->filter()->reject(fn (array $i): bool => $done->has($i['object_id']))->take(self::MAX_IMAGES)->values();
    }

    /** An icon, background, shape, divider… by its file name or title. */
    public static function isDecorative(string $file, string $title = ''): bool
    {
        $name = mb_strtolower(pathinfo(basename($file), PATHINFO_FILENAME));

        return preg_match(self::DECORATIVE, $name) === 1 || preg_match(self::DECORATIVE, mb_strtolower(trim($title))) === 1;
    }

    /**
     * Open alt-text suggestions whose images got their alt text on the site (or are gone) close by themselves; a row
     * with only some of them done keeps the rest.
     */
    private function closeResolved(DigitalAsset $site): void
    {
        $rows = Suggestion::query()->where('brand_id', $site->brand_id)->where('action_type', self::TYPE)
            ->whereIn('status', [Suggestion::OPEN, Suggestion::RECHECK])->get()
            ->filter(fn (Suggestion $s): bool => (int) data_get($s->action, 'site_id') === (int) $site->id);
        if ($rows->isEmpty()) {
            return;
        }
        $latest = $this->latest($site, $rows->flatMap(fn (Suggestion $s): array => array_column((array) data_get($s->action, 'images', []), 'image_id'))
            ->map(fn ($id): int => (int) $id)->unique()->values()->all());
        foreach ($rows as $row) {
            $images = (array) data_get($row->action, 'images', []);
            $left = array_values(array_filter($images, function (array $i) use ($latest): bool {
                $meta = $latest[(int) $i['image_id']] ?? null;

                return $meta !== null && trim((string) ($meta['alt_text'] ?? '')) === '';
            }));
            if ($left === []) {
                $row->forceFill(['status' => Suggestion::APPLIED, 'verification' => Suggestion::VERIFY_AUTO, 'verified_at' => now(), 'resolved_at' => now(),
                    'operator_note' => 'Görsellerin alt metni sitede var ya da görseller kaldırıldı (otomatik kapandı).'])->save();
            } elseif (count($left) < count($images)) {
                $row->forceFill(['title' => 'Görsel alt metni: '.count($left).' görsel', 'action' => array_merge((array) $row->action, ['images' => $left]),
                    'material_hash' => hash('sha256', (string) json_encode($left))])->save();
            }
        }
    }

    /**
     * The latest snapshot metadata of these attachments; an image deleted on the site (no longer listed, trashed or no
     * longer an image) is left out.
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, mixed>>
     */
    private function latest(DigitalAsset $site, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $latest = DB::table('website_cms_object_snapshot')->where('digital_asset_id', $site->id)->where('object_type', 'attachment')
            ->whereIn('object_id', array_map('strval', $ids))->groupBy('object_id')->selectRaw('max(id) as id');
        $out = [];
        foreach (DB::table('website_cms_object_snapshot')->whereIn('id', $latest)->get(['object_id', 'status', 'metadata']) as $row) {
            $meta = json_decode((string) $row->metadata, true) ?: [];
            if (in_array((string) $row->status, ['trash', 'deleted'], true) || ! str_starts_with((string) ($meta['mime_type'] ?? ''), 'image/')) {
                continue;
            }
            $out[(int) $row->object_id] = $meta;
        }

        return $out;
    }

    /** @param  Collection<int, array{object_id: int, file: string, title: string, page: Page, alt: string}>  $rows */
    private function upsert(DigitalAsset $site, Collection $rows): void
    {
        $page = $rows->first()['page'];
        $fingerprint = hash('sha256', implode('|', [$site->brand_id, self::TYPE, $page->id, $rows->pluck('object_id')->sort()->implode(',')]));
        $images = $rows->map(fn (array $r): array => ['image_id' => $r['object_id'], 'file' => $r['file'], 'alt' => $r['alt']])->values()->all();
        Suggestion::query()->updateOrCreate(['brand_id' => $site->brand_id, 'fingerprint' => $fingerprint], [
            'channel' => 'search', 'decision_key' => self::DECISION, 'material_hash' => hash('sha256', (string) json_encode($images)),
            'title' => 'Görsel alt metni: '.count($images).' görsel', 'reason' => 'Bu sayfadaki görsellerin alt metni yok; dosya adı ve sayfadan önerildi.',
            'priority' => 4, 'evidence' => array_map(fn (array $i): array => ['kind' => 'image', 'source' => $i['file'], 'value' => $i['alt']], $images),
            'action_type' => self::TYPE, 'target_type' => 'page', 'target_id' => (int) $page->id, 'page_id' => (int) $page->id,
            'action' => ['site_id' => (int) $site->id, 'images' => $images], 'status' => Suggestion::OPEN,
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
    }
}
