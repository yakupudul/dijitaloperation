<?php

namespace App\Services\Site;

use App\Models\Suggestion;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Operator decisions on website suggestions: Onayla / Reddet (with reason). Every decision goes to the brand memory's
 * decision history so later AI packs see it.
 */
final class SiteSuggestions
{
    public function __construct(private readonly BrandMemoryService $memory) {}

    public function approve(Suggestion $suggestion, User $user): void
    {
        if (! in_array($suggestion->status, [Suggestion::OPEN, Suggestion::RECHECK], true)) {
            throw ValidationException::withMessages(['suggestion' => 'Bu öneri zaten karara bağlandı.']);
        }
        $suggestion->forceFill(['status' => Suggestion::APPROVED, 'resolved_by' => $user->id, 'resolved_at' => now()])->save();
        $this->memory->recordDecision($suggestion, 'onaylandı');
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
