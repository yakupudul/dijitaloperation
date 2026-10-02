<?php

namespace App\Contracts\Ai;

/**
 * A queued job that does AI work and should appear in AI işleri from the moment it is queued ("Sırada"), so it can be
 * removed before it runs and stopped while it runs (AiCancellation::throwIfRequested() between its AI calls).
 * Jobs can also be listed in AiJobCatalog instead of implementing this.
 */
interface TracksAiJob
{
    /** Short Turkish name of the job in AI işleri. */
    public function aiLabel(): string;

    /** The AI operation key (= prompt operation) the job runs, if one. */
    public function aiOperation(): ?string;

    /** Relative URL of the page that shows the job's result, if one. */
    public function aiResultUrl(): ?string;
}
