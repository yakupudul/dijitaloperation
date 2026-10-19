<?php

namespace App\Jobs;

use App\Models\CoreConnection;
use App\Models\ExternalWriteAction;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\Integrations\WordPress\WordPressConnectorCommands;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Runs one approved external write (or its undo). One attempt: writes are never retried blindly.
 *
 * A worker that times out ends the write as failed with the reason (it used to stay "running" forever). When a site
 * did not answer two writes in the last half hour, its next writes are not sent at all but returned with the reason:
 * on 2026-10-13 hundreds of approved Avrupadent site fixes each waited out the timeout one after another and held the
 * "default" queue for hours. A site that fetches its work itself (connector pull, Avrupadent) is the exception: while
 * it is not asking, its writes wait and are tried again (ExternalWriteService::waitForSite) instead of failing, since
 * nothing ran on the site (2026-10-14: ~1000 approved Avrupadent writes failed this way, none reached WordPress).
 */
final class ExecuteExternalWriteJob implements ShouldQueue
{
    use Queueable;

    /** Start of the error a timed-out write ends with; the circuit below counts these. */
    public const string TIMEOUT_ERROR = 'Zaman aşımı';

    /** Unanswered writes on one site within SITE_SILENT_MINUTES after which its next writes are not sent. */
    private const int SITE_SILENT_WRITES = 2;

    private const int SITE_SILENT_MINUTES = 30;

    /** A pull site that has not asked for work within this many minutes is not handed new writes yet. */
    private const int PULL_SEEN_MINUTES = 15;

    public int $tries = 1;

    /** A site that fetches its work itself is waited for up to ~90 s per call; an article and its translation are 2-3 calls. */
    public int $timeout = 420;

    /** @param  ?string  $waitId  a try of a write waiting for its site; only the latest try runs (ExternalWriteService::wakeForSite) */
    public function __construct(public int $actionId, public bool $undo = false, public ?string $waitId = null) {}

    public function handle(ExternalWriteService $service): void
    {
        $action = ExternalWriteAction::query()->find($this->actionId);
        if ($action === null) {
            return;
        }
        if ($this->undo) {
            if ($action->status === 'undoing') {
                $service->executeUndo($action);
            }

            return;
        }
        if ($action->status !== 'queued' || ($this->waitId !== null && data_get($action->result, 'wait_id') !== $this->waitId)) {
            return;
        }
        if (($waiting = $this->siteNotAsking($action)) !== null) {
            $service->waitForSite($action, $waiting);

            return;
        }
        if (($silent = $this->silentSite($action, $service)) > 0) {
            $service->abandon($action, 'Gönderilmedi: site son '.self::SITE_SILENT_MINUTES.' dakikada '.$silent.' yazmaya yanıt vermedi. Site yanıt verince yeniden onaylayın.');

            return;
        }
        $service->execute($action);
    }

    /** The worker timed out (or died) while the write ran: it ends as failed instead of staying "running". */
    public function failed(?Throwable $exception = null): void
    {
        $action = ExternalWriteAction::query()->find($this->actionId);
        if ($this->undo || $action === null || ! in_array($action->status, ['queued', 'running'], true)) {
            return;
        }
        app(ExternalWriteService::class)->abandon($action, self::TIMEOUT_ERROR.': yazma '.$this->timeout.' saniyede bitmedi, site yanıt vermedi'
            .($exception !== null ? ' ('.class_basename($exception).')' : '').'. Sitede sonucu kontrol edin; gerekirse yeniden onaylayın.');
    }

    /**
     * A site that fetches its work itself (connector pull) and has not asked lately, or already has a full hand of work
     * waiting, is not handed more: the write waits (ExternalWriteService::waitForSite) instead of holding a worker for
     * the whole pickup wait and then failing. Null = send now.
     */
    private function siteNotAsking(ExternalWriteAction $action): ?string
    {
        if ($action->channel !== ExternalWriteAction::CHANNEL_WORDPRESS || $action->digital_asset_id === null) {
            return null;
        }
        $connection = CoreConnection::query()->where('digital_asset_id', $action->digital_asset_id)->where('type', 'wordpress_connector')->where('enabled', true)->first();
        if ($connection === null || data_get($connection->config, 'rest_transport') !== 'pull') {
            return null;
        }
        $seen = WordPressConnectorCommands::seenAt($connection);
        if ($seen === null || $seen->lt(now()->subMinutes(self::PULL_SEEN_MINUTES))) {
            return 'site son '.self::PULL_SEEN_MINUTES.' dakikada iş sormadı; '.WordPressConnectorCommands::lastAskText($connection);
        }
        $waiting = DB::table('website_connector_commands')->where('connection_id', $connection->id)->whereIn('status', ['pending', 'sent'])->count();

        return $waiting >= WordPressConnectorCommands::MAX_WAITING ? 'sitede alınmayı bekleyen '.$waiting.' iş var' : null;
    }

    /**
     * How many writes to the same site went unanswered in the last half hour (0 = send). Writes left "running" by a
     * worker that was killed before this rule existed are ended first, as timeouts.
     */
    private function silentSite(ExternalWriteAction $action, ExternalWriteService $service): int
    {
        if ($action->channel !== ExternalWriteAction::CHANNEL_WORDPRESS || $action->digital_asset_id === null) {
            return 0;
        }
        $sameSite = fn () => ExternalWriteAction::query()->where('channel', ExternalWriteAction::CHANNEL_WORDPRESS)
            ->where('digital_asset_id', $action->digital_asset_id)->whereKeyNot($action->id);
        foreach ($sameSite()->where('status', 'running')->where('started_at', '<', now()->subMinutes(15))->get() as $dead) {
            $service->abandon($dead, self::TIMEOUT_ERROR.': yazma bitmeden işçisi durdu, site yanıt vermedi. Sitede sonucu kontrol edin; gerekirse yeniden onaylayın.');
        }
        $unanswered = $sameSite()->where('status', 'failed')->where('finished_at', '>=', now()->subMinutes(self::SITE_SILENT_MINUTES))
            ->where(fn ($q) => $q->where('error', 'like', self::TIMEOUT_ERROR.'%')->orWhere('error', 'like', '%saniyede gelip almadı%')
                ->orWhere('error', 'like', '%saniyede gelmedi%'))->count();

        return $unanswered >= self::SITE_SILENT_WRITES ? $unanswered : 0;
    }
}
