<?php

namespace App\Services\Brain\Proposals;

use App\Models\BrainProposal;
use App\Models\User;

/**
 * One kind of prepared change in the Brain review queue. prepare() fills the queue (rules, embeddings, AI); apply()
 * carries out ONE approved proposal and throws when it can no longer be applied (the row is then marked failed).
 */
interface ProposalKind
{
    public function kind(): string;

    /** Short Turkish label shown on the queue and on the "AI ile hazırla" button. */
    public function label(): string;

    /** Whether preparing this kind calls an AI provider (for the cost / budget note on the button). */
    public function usesAi(): bool;

    /**
     * Prepare proposals. Returns how many new proposals were queued.
     *
     * @param  array<string, mixed>  $options
     */
    public function prepare(array $options): int;

    public function apply(BrainProposal $proposal, User $actor): void;
}
