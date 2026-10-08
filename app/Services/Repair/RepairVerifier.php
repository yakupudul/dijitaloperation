<?php

namespace App\Services\Repair;

use App\Models\ExternalWriteAction;
use App\Models\Page;
use App\Models\Suggestion;

/**
 * Doğrula (Onarım masası): a written fix is checked against what the site reports afterwards. A website title /
 * description fix is confirmed when the page's stored title and description (refreshed by the WordPress plugin or the
 * crawl after the write) carry the written values, "still seen" when the page was read again without them. A fix whose
 * writes all failed goes back to the desk with the error, so it can be approved again.
 */
final class RepairVerifier
{
    /** Writes are left alone this long (the plugin and the crawl report the change). */
    public const int SETTLE_HOURS = 24;

    /** Applied fixes older than this are not checked any more. */
    public const int MAX_DAYS = 14;

    /** @return array{confirmed: int, still_seen: int, reopened: int} */
    public function run(): array
    {
        $done = ['confirmed' => 0, 'still_seen' => 0, 'reopened' => 0];
        Suggestion::query()->with('page')->where('channel', 'search')->where('status', Suggestion::APPLIED)
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
        $new = (array) data_get($action, 'proposal.new', []);
        $expected = array_filter(['title' => $new['seo_title'] ?? null, 'meta_description' => $new['meta_description'] ?? null], fn (mixed $v): bool => filled($v));
        $page = $suggestion->page;
        if ($expected === [] || ! $page instanceof Page) {
            return null;
        }
        if ($page->updated_at === null || $page->updated_at->lte($suggestion->applied_at)) {
            if ($suggestion->verification === null) {
                $suggestion->forceFill(['verification' => Suggestion::VERIFY_PENDING])->save();
            }

            return null;
        }
        $same = fn (?string $a, ?string $b): bool => self::normalize($a) === self::normalize($b);
        $ok = collect($expected)->every(fn (string $value, string $column): bool => $same($page->{$column}, $value));
        $suggestion->forceFill(['verification' => $ok ? Suggestion::VERIFY_CONFIRMED : Suggestion::VERIFY_STILL_SEEN, 'verified_at' => now()])->save();

        return $ok ? 'confirmed' : 'still_seen';
    }

    private static function normalize(?string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5))));
    }
}
