<?php

namespace App\Services\GoogleAds;

use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\Outcomes\OutcomeTracker;
use App\Services\Suggestions\AssetSuggestions;
use App\Services\Work\WorkVerifier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * Google Ads Yapılacaklar (Faz 5): every suggestion of one account in the ONE `suggestions` table (channel and target
 * `google_ads`). Groups: `check` (system checks, no AI), `negative` (search-term review), `structure` / `budget` /
 * `experiment` (campaign strategy), `rsa:<ad group>` (ad texts). Apply: shared-list negatives → Admin-approved
 * ADR-064 write (undo from the list), applied when the write succeeded; everything else → approved draft → Google Ads
 * Editor file (a downloaded batch) → applied when the operator confirms "Editor'a aktardım". Apply stores the outcome
 * baseline (OutcomeTracker: 28 days of the referenced campaign, else the account) + the evidence.
 */
final class GoogleAdsSuggestions extends AssetSuggestions
{
    public const string CHANNEL = 'google_ads';

    public const string TARGET = 'google_ads';

    /** Suggestions that become rows of the Editor file once approved. */
    public const array EDITOR_TYPES = ['ads_campaign', 'ads_rsa', 'ads_negative'];

    public function __construct(
        private readonly GoogleAdsChecks $checks,
    ) {}

    /** System checks → suggestions (failing ones) and the state list of the overview. */
    public function syncChecks(DigitalAsset $asset): int
    {
        $results = $this->checks->run($asset);
        Cache::put(self::checksKey((int) $asset->id), ['at' => now()->toIso8601String(), 'items' => array_map(
            fn (array $c): array => ['id' => $c['id'], 'label' => $c['label'], 'state' => $c['state'], 'reason' => $c['reason']], $results)], now()->addDays(3));
        $items = [];
        foreach ($results as $check) {
            if ($check['state'] !== 'fail') {
                continue;
            }
            $items[] = ['key' => 'check:'.$check['id'], 'title' => $check['label'], 'reason' => $check['reason'], 'priority' => $check['priority'],
                'evidence' => $check['evidence'], 'action_type' => 'ads_check', 'action' => ['check' => $check['id'], 'todo' => $check['todo']]];
        }

        $stored = $this->replaceGroup($asset, 'check', $items);
        app(WorkVerifier::class)->checks($asset, $this->channel(), 'ads_check',
            array_values(array_map(fn (array $c): string => (string) $c['id'], array_filter($results, fn (array $c): bool => $c['state'] === 'pass'))),
            array_values(array_map(fn (array $c): string => (string) $c['id'], array_filter($results, fn (array $c): bool => $c['state'] === 'fail'))));

        return $stored;
    }

    /** @return array{at: string, items: list<array{id: string, label: string, state: string, reason: string}>}|null */
    public function checkStates(DigitalAsset $asset): ?array
    {
        $states = Cache::get(self::checksKey((int) $asset->id));

        return is_array($states) ? $states : null;
    }

    public static function checksKey(int $assetId): string
    {
        return 'google-ads-checks:'.$assetId;
    }

    /** @return Collection<int, Suggestion> approved drafts waiting for the Editor file */
    public function approved(DigitalAsset $asset): Collection
    {
        return $this->query($asset)->where('status', Suggestion::APPROVED)->orderBy('priority')->orderBy('id')->get();
    }

    /** @return Collection<int, Suggestion> approved drafts not yet in a downloaded Editor file (not shared-list negatives) */
    public function editorDrafts(DigitalAsset $asset): Collection
    {
        return $this->approved($asset)->reject(fn (Suggestion $s): bool => self::isSharedNegative($s) || $s->action_type === GoogleAdsChanges::TYPE || filled($s->action['editor_batch'] ?? null))->values();
    }

    /** @return Collection<string, Collection<int, Suggestion>> downloaded Editor batches waiting for "Editor'a aktardım", by batch id */
    public function editorBatches(DigitalAsset $asset): Collection
    {
        return $this->approved($asset)->filter(fn (Suggestion $s): bool => ! self::isSharedNegative($s) && filled($s->action['editor_batch'] ?? null))
            ->groupBy(fn (Suggestion $s): string => (string) $s->action['editor_batch']);
    }

    /**
     * The drafts went into a downloaded Editor file: they stay approved (nothing changed on Google yet) and wait for the
     * operator's "Editor'a aktardım".
     *
     * @param  Collection<int, Suggestion>  $drafts
     */
    public function markDownloaded(Collection $drafts): string
    {
        $batch = now()->format('Y-m-d H:i:s');
        foreach ($drafts as $draft) {
            $draft->forceFill(['action' => array_merge((array) $draft->action, ['editor_batch' => $batch])])->save();
        }

        return $batch;
    }

    /** "Editor'a aktardım": the batch's drafts are applied now (baseline from today). */
    public function confirmEditorBatch(DigitalAsset $asset, string $batch, ?User $user): int
    {
        $drafts = $this->editorBatches($asset)->get($batch, collect());
        foreach ($drafts as $draft) {
            $this->markApplied($draft, $user, ['editor_file_at' => $batch]);
        }

        return $drafts->count();
    }

    public static function isSharedNegative(Suggestion $suggestion): bool
    {
        return $suggestion->action_type === 'ads_negative' && ($suggestion->action['scope'] ?? '') === 'shared';
    }

    /**
     * Approve: an Editor draft (campaign, ad text, campaign / ad group negative) waits for the file; a shared-list negative
     * waits for the Admin send; checks, budget split and experiments are done by the operator (applied now).
     */
    public function approve(Suggestion $suggestion, ?User $user): void
    {
        if ($suggestion->action_type === GoogleAdsChanges::TYPE) {
            // ADR-081: approving a prepared setting change writes it to Google (Admin).
            abort_if($user === null, 403);
            app(GoogleAdsChanges::class)->send($user, $suggestion);

            return;
        }
        if (in_array($suggestion->action_type, self::EDITOR_TYPES, true)) {
            $suggestion->forceFill(['status' => Suggestion::APPROVED, 'resolved_by' => $user?->id, 'resolved_at' => now()])->save();

            return;
        }
        $this->markApplied($suggestion, $user);
    }

    /**
     * Applied: status applied with the outcome baseline (OutcomeTracker: 28 days of the referenced campaign, else the
     * account) and the suggestion's evidence at apply time.
     *
     * @param  array<string, mixed>  $extra
     */
    public function markApplied(Suggestion $suggestion, ?User $user, array $extra = []): void
    {
        app(OutcomeTracker::class)->apply($suggestion, $user, ['facts' => $suggestion->evidence ?? []] + $extra);
    }

    /**
     * ADR-064: the selected open / approved shared-list negatives go to the account's shared negative list in one
     * Admin-approved write (undo from the list). They wait as "gönderiliyor"; writeFinished() applies them (baseline)
     * once the write succeeded.
     *
     * @param  list<int>  $ids
     */
    public function sendNegatives(DigitalAsset $asset, User $user, array $ids, ExternalWriteService $writes): ExternalWriteAction
    {
        $rows = $this->query($asset)->whereIn('id', $ids)->where('action_type', 'ads_negative')
            ->whereIn('status', [Suggestion::OPEN, Suggestion::APPROVED, Suggestion::SNOOZED])->get()
            ->filter(fn (Suggestion $s): bool => ($s->action['scope'] ?? '') === 'shared')->values();
        if ($rows->isEmpty()) {
            throw ValidationException::withMessages(['write' => 'Paylaşılan listeye gidecek negatif seçin.']);
        }
        $lines = $rows->map(fn (Suggestion $s): string => self::line((string) $s->action['text'], (string) $s->action['match_type']))->implode("\n");
        // Waiting as "gönderiliyor" before the write is queued: a synchronous write finishes inside the request.
        $before = $rows->mapWithKeys(fn (Suggestion $row): array => [$row->id => [$row->status, $row->action]]);
        foreach ($rows as $row) {
            $row->forceFill(['status' => Suggestion::APPROVED, 'resolved_by' => $user->id, 'resolved_at' => now(),
                'action' => array_merge((array) $row->action, ['sending_write_id' => 'queued'])])->save();
        }
        try {
            $action = $writes->requestNegativeList($user, $asset, $lines, $rows->first(), $rows->pluck('id')->map(fn ($id): int => (int) $id)->all());
        } catch (\Throwable $error) {
            foreach ($rows as $row) {
                $row->forceFill(['status' => $before[$row->id][0], 'action' => $before[$row->id][1]])->save();
            }

            throw $error;
        }
        foreach ($rows as $row) {
            $row->refresh();
            if (($row->action['sending_write_id'] ?? null) === 'queued') {
                $row->forceFill(['action' => array_merge((array) $row->action, ['sending_write_id' => $action->id])])->save();
            }
        }

        return $action;
    }

    /**
     * Success path of a shared-list write (ExternalWriteService::execute): the negatives Google accepted (added or
     * already in the list) are applied with the write in their baseline; the others can be sent again.
     */
    public function writeFinished(ExternalWriteAction $action): void
    {
        $ids = array_map('intval', (array) ($action->request_payload['suggestion_ids'] ?? []));
        if ($ids === []) {
            return;
        }
        $failed = collect((array) ($action->result['failed'] ?? []))->map(fn ($k): string => mb_strtolower((string) ($k['text'] ?? '')).'|'.strtoupper((string) ($k['match_type'] ?? '')))->all();
        $user = $action->requested_by !== null ? User::query()->find($action->requested_by) : null;
        $rows = Suggestion::query()->whereIn('id', $ids)->where('status', Suggestion::APPROVED)->get();
        foreach ($rows as $row) {
            // Sent as parse(line()) sends it: lower-case, and anything but EXACT as PHRASE.
            $key = trim((string) preg_replace('/\s+/u', ' ', mb_strtolower((string) ($row->action['text'] ?? '')))).'|'
                .(strtoupper((string) ($row->action['match_type'] ?? '')) === 'EXACT' ? 'EXACT' : 'PHRASE');
            $action->status === 'failed' || in_array($key, $failed, true)
                ? $row->forceFill(['action' => array_diff_key((array) $row->action, ['sending_write_id' => true])])->save()
                : $this->markApplied($row, $user, ['write_action_id' => $action->id]);
        }
    }

    /** Paste line of the shared-list writer: [x] exact, "x" phrase (a single-word broad negative is sent as phrase). */
    public static function line(string $text, string $matchType): string
    {
        return strtoupper($matchType) === 'EXACT' ? '['.$text.']' : '"'.$text.'"';
    }

    /**
     * Operator edit of a draft: the action is replaced and locked (AI never overwrites it; a new AI version is kept as a
     * change proposal).
     *
     * @param  array<string, mixed>  $action
     */
    public function saveEdit(Suggestion $suggestion, array $action): void
    {
        $suggestion->forceFill(['action' => ['locked' => true] + $action])->save();
    }

    /** Accept the AI change proposal of a locked draft: the proposal replaces the edit and the lock is lifted. */
    public function acceptProposal(Suggestion $suggestion): void
    {
        $proposal = $suggestion->action['proposal'] ?? null;
        if (is_array($proposal)) {
            $suggestion->forceFill(['action' => $proposal])->save();
        }
    }

    /** @return Collection<int, ExternalWriteAction> the account's recent negative-list writes */
    public function negativeWrites(DigitalAsset $asset): Collection
    {
        return ExternalWriteAction::query()->where('digital_asset_id', $asset->id)->where('channel', ExternalWriteAction::CHANNEL_GOOGLE_ADS)
            ->where('action', ExternalWriteAction::ACTION_NEGATIVE_LIST_ADD)->latest('id')->limit(5)->get();
    }

    protected function channel(): string
    {
        return self::CHANNEL;
    }

    protected function target(): string
    {
        return self::TARGET;
    }

    protected function prefix(): string
    {
        return 'ads';
    }
}
