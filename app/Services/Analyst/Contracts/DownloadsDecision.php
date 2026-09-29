<?php

namespace App\Services\Analyst\Contracts;

use App\Models\AnalystDecision;
use App\Models\User;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Optional for a ChannelAnalyst: a `run` action whose result is a file (e.g. the Meta change plan). The card button
 * (HandlesAnalystDecisions::runDecisionAction) returns the download instead of a result line; null = not a file.
 */
interface DownloadsDecision
{
    public function download(AnalystDecision $decision, User $user): ?StreamedResponse;
}
