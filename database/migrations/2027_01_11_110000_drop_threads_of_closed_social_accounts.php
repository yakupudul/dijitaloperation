<?php

use App\Models\SocialAccount;
use App\Models\SocialThread;
use Illuminate\Database\Migrations\Migration;

/**
 * 2026-11-08: the sync that was running while customers' Pages were switched off wrote their comments and DMs back
 * (Gelen kutusu showed another brand's lipstick-post comments with ready drafts). Drop every thread of an account that
 * is off; the queue and the sync now only see accounts that are on.
 */
return new class extends Migration
{
    public function up(): void
    {
        $closed = SocialAccount::query()->where('enabled', false)->pluck('id');
        if ($closed->isNotEmpty()) {
            SocialThread::query()->whereIn('social_account_id', $closed)->delete();
        }
    }

    public function down(): void {}
};
