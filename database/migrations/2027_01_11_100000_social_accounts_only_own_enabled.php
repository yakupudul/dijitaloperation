<?php

use App\Models\SocialAccount;
use App\Models\SocialThread;
use App\Services\Social\SocialMetaConnector;
use App\Services\Social\SocialPlanner;
use Illuminate\Database\Migrations\Migration;

/**
 * The first "Meta'yı bağla" (2026-11-08) stored every Page the operator manages, customers' too, all switched on, so the
 * Moximu plan targeted 56 accounts. Only the agency's own Facebook Page and Instagram stay on; the rest are switched off
 * (kept for later), their inbox copies are dropped and the upcoming posts lose their rows for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (SocialAccount::query()->whereIn('platform', [SocialAccount::FACEBOOK, SocialAccount::INSTAGRAM])->get() as $account) {
            if (! SocialMetaConnector::isOwn((string) $account->name, $account->username)) {
                $account->forceFill(['enabled' => false, 'brand_id' => null])->save();
                // Their DMs and comments were read into the Gelen kutusu in the minutes after the login: a copy of
                // Meta's data, dropped so customers' messages do not wait in Moximu's queue (re-read if switched on).
                SocialThread::query()->where('social_account_id', $account->id)->delete();
            }
        }
        try {
            app(SocialPlanner::class)->syncUpcoming();
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function down(): void {}
};
