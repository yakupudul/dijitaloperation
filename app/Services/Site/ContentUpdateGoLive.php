<?php

namespace App\Services\Site;

use App\Models\ExternalWriteAction;
use App\Models\Suggestion;
use App\Models\User;
use Throwable;

/**
 * yakup, 2026-11-02 ("taslakları ayırt edemiyorum"): a new version of an existing page (content update idea, overlap
 * "Ayrıştır" rewrite, AI site fix with a body) no longer waits in WordPress as a second draft. The operator approves it
 * once in MoxDOP; as soon as WordPress confirmed the draft copy it replaces the live page (content_apply: the connector
 * keeps a revision and the change log, so it stays undoable) and the copy leaves the drafts list. Only new articles
 * stay as drafts on the site.
 */
final class ContentUpdateGoLive
{
    public function draftFinished(ExternalWriteAction $draft): void
    {
        if ($draft->action !== ExternalWriteAction::ACTION_CONTENT_DRAFT || $draft->status !== 'succeeded' || $draft->suggestion_id === null
            || (int) data_get($draft->request_payload, 'object_id', 0) < 1) {
            return;
        }
        $suggestion = Suggestion::query()->find($draft->suggestion_id);
        $user = User::query()->find($draft->requested_by);
        if ($suggestion === null || $user === null || $suggestion->action_type === BrokenTranslations::TYPE) {
            // Broken translation fixes already go live by themselves (BrokenTranslations::draftFinished).
            return;
        }
        if (ExternalWriteAction::query()->where('suggestion_id', $suggestion->id)->where('action', ExternalWriteAction::ACTION_CONTENT_APPLY)
            ->where('request_payload->draft_id', (int) ($draft->result['post_id'] ?? 0))->exists()) {
            return;
        }
        $action = (array) $suggestion->action;
        try {
            if (! empty($action['page_update'])) {
                if (! isset($action['page_apply_write_id']) && (int) ($action['article_write_id'] ?? 0) === (int) $draft->id) {
                    app(ContentPlanner::class)->applyPageUpdate($suggestion, $user);
                }
            } elseif ($suggestion->decision_key === ClusterOverlaps::DECISION) {
                if (data_get($action, 'rewrite.state') === 'publishing') {
                    app(OverlapAutopilot::class)->act([(int) $suggestion->id], 'go_live', $user);
                }
            } elseif ($suggestion->status !== Suggestion::APPLIED && in_array((int) $draft->id, array_map('intval', (array) ($action['writes'] ?? [])), true)) {
                $id = app(ChangeApplier::class)->publishDraft($suggestion, $user);
                $suggestion->refresh();
                $suggestion->forceFill(['status' => Suggestion::APPLIED, 'action' => array_merge((array) $suggestion->action, ['live_write_id' => $id])])->save();
            }
        } catch (Throwable $exception) {
            // The draft copy stays; the old "Sayfaya uygula" / "Canlıya al" buttons still work for it.
            report($exception);
        }
    }
}
