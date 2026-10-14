<?php

namespace App\Jobs;

use App\Models\CoreConnection;
use App\Services\Integrations\WordPress\WordPressConnectorClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * "Bağlantıyı doğrula" on a site that fetches its work itself (connector 1.13.0+, host refuses MoxDOP): the status read
 * (plugin version, capabilities such as site building) waits for the site's next ask in the background, not in the
 * screen's request.
 */
final class RefreshWordPressConnectorStatusJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 170;

    public function __construct(public int $connectionId) {}

    public function handle(WordPressConnectorClient $client): void
    {
        $connection = CoreConnection::query()->with('credential')->find($this->connectionId);
        if ($connection === null || ! $connection->enabled) {
            return;
        }
        try {
            $client->status($connection);
        } catch (Throwable) {
            // The client already wrote the reason on the connection (last_error), which the page shows.
        }
    }
}
