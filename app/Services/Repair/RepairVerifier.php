<?php

namespace App\Services\Repair;

use App\Models\ExternalWriteAction;
use App\Models\Page;
use App\Models\Suggestion;
use App\Services\Gbp\Desk\GbpDesk;
use App\Services\Site\ImageAlts;
use App\Services\Site\TemplateLinks;
use Illuminate\Support\Facades\DB;

/**
 * Doğrula (Onarım masası): a written fix is checked against what the site or profile reports afterwards.
 *
 * Audit N6/7 (2026-10-22): every write type is checked, not only titles and descriptions — a page's title,
 * description, canonical and the internal links that were added (the next crawl's links of the page), a published
 * rewrite (the page changed after it), image alt texts (the next media sync) and a Business Profile description (the
 * next profile snapshot). "Still seen" no longer stays a note in Geçmiş: the row returns to the desk saying the change
 * did not show, at most MAX_RETURNS times, so a fix that never takes is not sent forever. A fix whose writes all
 * failed goes back to the desk with the error, as before.
 */
final class RepairVerifier
{
    /** Writes are left alone this long (the plugin and the crawl report the change). */
    public const int SETTLE_HOURS = 24;

    /** Applied fixes older than this are not checked any more. */
    public const int MAX_DAYS = 14;

    /** A fix that did not show returns to the desk at most this many times; afterwards it stays "still seen" in Geçmiş. */
    public const int MAX_RETURNS = 2;

    public const string NOTE = 'verify_note';

    public const string RETURNS = 'verify_returns';

    /** @return array{confirmed: int, still_seen: int, reopened: int, returned: int} */
    public function run(): array
    {
        $done = ['confirmed' => 0, 'still_seen' => 0, 'reopened' => 0, 'returned' => 0];
        Suggestion::query()->with('page')->where('status', Suggestion::APPLIED)
            ->where(fn ($q) => $q->where('channel', 'search')->orWhereNotNull('action->writes'))
            ->whereNotNull('applied_at')->where('applied_at', '<=', now()->subHours(self::SETTLE_HOURS))->where('applied_at', '>=', now()->subDays(self::MAX_DAYS))
            ->where(fn ($q) => $q->whereNull('verification')->orWhere('verification', Suggestion::VERIFY_PENDING))
            ->orderBy('id')->chunkById(200, function ($chunk) use (&$done): void {
                foreach ($chunk as $suggestion) {
                    $outcome = $this->check($suggestion);
                    if ($outcome !== null) {
                        $done[$outcome]++;
                    }
                }
            });
        $done['returned'] = $this->returnStillSeen();

        return $done;
    }

    /** @return 'confirmed'|'still_seen'|'reopened'|null */
    private function check(Suggestion $suggestion): ?string
    {
        $action = (array) $suggestion->action;
        $writeIds = array_map('intval', (array) ($action['writes'] ?? []));
        if ($writeIds !== []) {
            $writes = ExternalWriteAction::query()->whereIn('id', $writeIds)->get(['id', 'status', 'error']);
            if ($writes->isNotEmpty() && $writes->every(fn (ExternalWriteAction $w): bool => in_array($w->status, ['failed', 'rejected', 'cancelled', 'undone'], true))) {
                $failed = $writes->first(fn (ExternalWriteAction $w): bool => $w->status === 'failed');
                unset($action['writes']);
                $suggestion->forceFill(['status' => Suggestion::OPEN, 'applied_at' => null, 'verification' => null, 'verified_at' => null,
                    'action' => $action + ['last_write_error' => $failed !== null ? mb_substr((string) $failed->error, 0, 300) : 'Geri alındı.']])->save();

                return 'reopened';
            }
        }
        $seen = match (true) {
            $suggestion->action_type === ImageAlts::TYPE => app(ImageAlts::class)->written($suggestion),
            $suggestion->action_type === 'gbp_description' => $this->description($suggestion),
            default => $this->page($suggestion),
        };
        if ($seen === null) {
            if ($suggestion->verification === null) {
                $suggestion->forceFill(['verification' => Suggestion::VERIFY_PENDING])->save();
            }

            return null;
        }
        $suggestion->forceFill(['verification' => $seen ? Suggestion::VERIFY_CONFIRMED : Suggestion::VERIFY_STILL_SEEN, 'verified_at' => now()])->save();

        return $seen ? 'confirmed' : 'still_seen';
    }

    /**
     * Whether the page carries what was written: true / false once the page was read again after the write, null
     * while it was not (or nothing on it can be checked, such as a schema block).
     */
    private function page(Suggestion $suggestion): ?bool
    {
        $page = $suggestion->page;
        $new = (array) data_get($suggestion->action, 'proposal.new', []);
        if (! $page instanceof Page || $new === []) {
            return null;
        }
        $results = [];
        $fields = array_filter(['title' => $new['seo_title'] ?? null, 'meta_description' => $new['meta_description'] ?? null, 'canonical' => $new['canonical'] ?? null],
            fn (mixed $v): bool => is_string($v) && filled($v));
        if ($fields !== [] && $page->updated_at !== null && $page->updated_at->gt($suggestion->applied_at)) {
            $results[] = collect($fields)->every(fn (string $value, string $column): bool => self::normalize($page->{$column}) === self::normalize($value));
        }
        if (filled($new['html'] ?? null)) {
            // A published rewrite: the page's text changed after "Canlıya al".
            $changed = $page->changed_at ?? null;
            $results[] = $changed !== null && $changed->gt($suggestion->applied_at) ? true : null;
        } elseif (($links = array_values(array_filter(array_map(fn ($l): string => (string) data_get($l, 'url'), (array) ($new['internal_links'] ?? []))))) !== []) {
            $results[] = $this->links($page, $links, $suggestion);
        }
        if (in_array(false, $results, true)) {
            return false;
        }
        $known = array_values(array_filter($results, fn (?bool $r): bool => $r !== null));
        $expected = ($fields !== [] ? 1 : 0) + (filled($new['html'] ?? null) || filled($new['internal_links'] ?? null) ? 1 : 0);

        return $known !== [] && count($known) === $expected ? true : null;
    }

    /**
     * The links the page was crawled with after the write: null while it was not crawled again.
     *
     * @param  list<string>  $urls
     */
    private function links(Page $page, array $urls, Suggestion $suggestion): ?bool
    {
        $edges = DB::table('website_link_edge')->where('digital_asset_id', $page->website_asset_id)->where('source_url', $page->url)
            ->where('last_collected_at', '>', $suggestion->applied_at)->pluck('normalized_target_url')->map(fn ($u): string => self::url((string) $u))->all();
        if ($edges === []) {
            return null;
        }

        return collect($urls)->every(fn (string $url): bool => in_array(self::url($url), $edges, true)
            || TemplateLinks::isTemplate((int) $page->website_asset_id, $url));
    }

    /** The Business Profile shows the written description once a snapshot taken after the write came in. */
    private function description(Suggestion $suggestion): ?bool
    {
        $proposed = (string) data_get($suggestion->action, 'proposed', '');
        $snapshot = app(GbpDesk::class)->snapshots([(int) $suggestion->target_id])[(int) $suggestion->target_id] ?? null;
        if ($proposed === '' || $snapshot === null || $snapshot['captured_at'] <= $suggestion->applied_at->toDateString()) {
            return null;
        }

        return self::normalize($snapshot['description']) === self::normalize($proposed);
    }

    /** Fixes that did not show go back to the desk (at most MAX_RETURNS times), with the reason on the row. */
    private function returnStillSeen(): int
    {
        $returned = 0;
        Suggestion::query()->where('status', Suggestion::APPLIED)->where('verification', Suggestion::VERIFY_STILL_SEEN)
            ->where('verified_at', '>=', now()->subDays(self::MAX_DAYS))
            ->orderBy('id')->chunkById(200, function ($chunk) use (&$returned): void {
                foreach ($chunk as $suggestion) {
                    $action = (array) $suggestion->action;
                    $times = (int) ($action[self::RETURNS] ?? 0);
                    if ($times >= self::MAX_RETURNS) {
                        continue;
                    }
                    if (isset($action['writes'])) {
                        $action['previous_writes'] = [...(array) ($action['previous_writes'] ?? []), ...(array) $action['writes']];
                    }
                    unset($action['writes']);
                    $action[self::RETURNS] = $times + 1;
                    $action[self::NOTE] = 'Gönderildi ama '.(str_starts_with((string) $suggestion->action_type, 'gbp_') ? 'profilde' : 'sitede')
                        .' hâlâ eski hali görünüyor; tekrar gönder ya da elle kontrol et.';
                    $suggestion->forceFill(['status' => Suggestion::OPEN, 'applied_at' => null, 'resolved_at' => null, 'verification' => null, 'verified_at' => null,
                        'action' => $action])->save();
                    $returned++;
                }
            });

        return $returned;
    }

    private static function url(string $url): string
    {
        // Internal links: the path decides (a proposal may carry a relative address, the crawl an absolute one).
        return '/'.trim((string) parse_url(mb_strtolower(trim($url)), PHP_URL_PATH), '/');
    }

    private static function normalize(?string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5))));
    }
}
