<?php

namespace App\Services\Content;

use App\Models\ContentCalendarItem;
use App\Models\User;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Support\ServiceScope;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Publishes approved Business Profile posts whose time has come (ADR-073), on behalf of the Admin who approved them.
 * Other channels are not published by MoxDOP; they appear in the command center on their day.
 */
final class ContentCalendarPublisher
{
    /** Approved posts older than this are not published late. */
    public const int STALE_AFTER_HOURS = 48;

    public function __construct(private readonly ExternalWriteService $writes) {}

    public function publishDue(): int
    {
        $count = 0;
        // Service scope: items of a passive customer's brand (or a brandless profile) are skipped, not failed.
        app(ServiceScope::class)->constrain(ContentCalendarItem::query())->with('digitalAsset')->where('channel', 'gbp_post')->where('status', 'approved')
            ->whereNull('write_action_id')->where('scheduled_for', '<=', now())->orderBy('scheduled_for')->limit(50)->get()
            ->each(function (ContentCalendarItem $item) use (&$count): void {
                $approver = User::query()->find($item->approved_by);
                // A post whose time passed long ago (e.g. while the customer was passive) is not published late.
                if ($item->scheduled_for->lt(now()->subHours(self::STALE_AFTER_HOURS))) {
                    $item->forceFill(['status' => 'failed', 'error' => 'Yayın zamanı geçti; yeni bir tarih verip yeniden onaylayın.'])->save();

                    return;
                }
                try {
                    if ($approver === null || $item->digitalAsset === null) {
                        throw ValidationException::withMessages(['write' => 'Onaylayan kullanıcı ya da İşletme Profili bulunamadı.']);
                    }
                    $action = $this->writes->requestLocalPost($approver, $item->digitalAsset, [
                        'summary' => trim($item->title."\n\n".$item->body), 'url' => $item->url, 'action_type' => $item->action_type, 'calendar_id' => $item->id,
                    ]);
                    $item->forceFill(['write_action_id' => $action->id, 'error' => null])->save();
                    $count++;
                } catch (Throwable $error) {
                    $message = $error instanceof ValidationException ? (string) collect($error->errors())->flatten()->first() : $error->getMessage();
                    $item->forceFill(['status' => 'failed', 'error' => mb_substr($message, 0, 300)])->save();
                }
            });

        return $count;
    }
}
