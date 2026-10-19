<?php

namespace App\Services\Site;

use App\Models\ExternalWriteAction;
use App\Models\Suggestion;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Operator decisions on website suggestions: Onayla / Reddet (with reason). Every decision goes to the brand memory's
 * decision history so later AI packs see it.
 *
 * Onayla never hides work (yakup, 2026-10-19: "onayla'ya basınca sitede yaptığını düşünüyordum"): a type the system
 * writes (SiteSuggestionTypes::APPLICABLE, bozuk çeviri) stays open so it waits on the Onarım masası for the send,
 * and its new version is prepared when there is none yet. Only work done by hand on the site becomes "approved".
 */
final class SiteSuggestions
{
    public function __construct(private readonly BrandMemoryService $memory) {}

    /** Types the Onarım masası sends to the site. */
    public static function sendable(string $type): bool
    {
        return SiteSuggestionTypes::applicable($type) || $type === BrokenTranslations::TYPE;
    }

    /** @return string what happened, for the screen */
    public function approve(Suggestion $suggestion, User $user): string
    {
        if (! in_array($suggestion->status, [Suggestion::OPEN, Suggestion::RECHECK], true)) {
            throw ValidationException::withMessages(['suggestion' => 'Bu öneri zaten karara bağlandı.']);
        }
        if (self::sendable((string) $suggestion->action_type)) {
            $suggestion->forceFill(['action' => array_merge((array) $suggestion->action, ['operator_ok' => ['by' => (int) $user->id, 'at' => now()->toIso8601String()]])])->save();
            $this->memory->recordDecision($suggestion, 'onaylandı');
            $siteId = (int) ($suggestion->page?->website_asset_id ?? data_get($suggestion->action, 'site_id', 0));
            if (data_get($suggestion->action, 'proposal') === null) {
                if ($suggestion->action_type !== BrokenTranslations::TYPE && $siteId > 0) {
                    SiteOperations::dispatch($siteId, SiteOperations::APPLY_CHANGE, ['suggestion_id' => (int) $suggestion->id]);
                }

                return 'Düzeltme hazırlanıyor; hazır olunca Onarım masasında gönderini bekler.';
            }

            return 'Onarım masasında gönderini bekliyor.';
        }
        $suggestion->forceFill(['status' => Suggestion::APPROVED, 'resolved_by' => $user->id, 'resolved_at' => now()])->save();
        $this->memory->recordDecision($suggestion, 'onaylandı');

        return 'Yapıldı olarak işaretlendi; sistem sonraki taramada kontrol eder.';
    }

    /**
     * Before 2026-10-19 Onayla on Öneriler / Rakipler made such a row "approved", and the Onarım masası leaves approved
     * rows out: the fix was never sent. Those rows (nothing written yet) go back to open, on the desk again; overlaps
     * approved there without anything sent go back too.
     *
     * @return int rows returned
     */
    public static function returnUnsentApprovals(): int
    {
        $count = 0;
        Suggestion::query()->where('status', Suggestion::APPROVED)->whereNull('applied_at')
            ->whereIn('action_type', [...SiteSuggestionTypes::APPLICABLE, BrokenTranslations::TYPE, ClusterOverlaps::TYPE])
            ->orderBy('id')->each(function (Suggestion $s) use (&$count): void {
                $action = (array) $s->action;
                $sent = isset($action['writes']) || filled($action['merge_sent_at'] ?? null) || in_array(data_get($action, 'rewrite.state'), ['preparing', 'publishing', 'live'], true)
                    || ExternalWriteAction::query()->where('suggestion_id', $s->id)->exists();
                if ($sent) {
                    return;
                }
                $s->forceFill(['status' => Suggestion::OPEN, 'resolved_at' => null, 'action' => array_merge($action, ['operator_ok' => ['by' => (int) $s->resolved_by, 'at' => now()->toIso8601String()]])])->save();
                $count++;
            });

        return $count;
    }

    public function dismiss(Suggestion $suggestion, User $user, string $reason): void
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw ValidationException::withMessages(['reason' => 'Reddetme nedeni yazın.']);
        }
        if (in_array($suggestion->status, [Suggestion::APPLIED, Suggestion::DISMISSED], true)) {
            throw ValidationException::withMessages(['suggestion' => 'Bu öneri zaten karara bağlandı.']);
        }
        $suggestion->forceFill(['status' => Suggestion::DISMISSED, 'operator_note' => mb_substr($reason, 0, 1000), 'resolved_by' => $user->id, 'resolved_at' => now()])->save();
        $this->memory->recordDecision($suggestion, 'reddedildi', $reason);
    }
}
