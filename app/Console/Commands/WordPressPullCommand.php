<?php

namespace App\Console\Commands;

use App\Models\CoreConnection;
use App\Models\DigitalAsset;
use App\Services\Integrations\WordPress\WordPressConnectorPairingService;
use Illuminate\Console\Command;

/**
 * moxdop:wordpress:pull {site} — connector 1.13.0: the site fetches its work from MoxDOP itself (for a host that
 * refuses MoxDOP's server); --off sends requests to the site again.
 */
final class WordPressPullCommand extends Command
{
    protected $signature = 'moxdop:wordpress:pull {site : site address or id, e.g. avrupadent.com.tr} {--off : send requests to the site again}';

    protected $description = 'Let a WordPress site fetch MoxDOP requests itself (host refuses MoxDOP), or switch it back.';

    public function handle(): int
    {
        $site = (string) $this->argument('site');
        $asset = DigitalAsset::query()->where('type', 'website')
            ->where(fn ($q) => $q->where('id', ctype_digit($site) ? (int) $site : 0)->orWhere('domain', $site)->orWhere('name', $site)->orWhere('primary_url', 'like', '%'.$site.'%'))
            ->orderBy('id')->first();
        $connection = $asset === null ? null : CoreConnection::query()->where('digital_asset_id', $asset->id)
            ->where('type', WordPressConnectorPairingService::CONNECTION_TYPE)->first();
        if ($connection === null) {
            $this->error('Bu adrese bağlı WordPress Connector bulunamadı: '.$site);

            return self::FAILURE;
        }
        $off = (bool) $this->option('off');
        $connection->forceFill(['config' => array_merge((array) $connection->config, ['rest_transport' => $off ? 'path' : 'pull'])])->save();
        $this->info($off
            ? $asset->name.': istekler yine doğrudan siteye gidecek.'
            : $asset->name.': site işleri MoxDOP\'tan kendisi alacak (eklenti en az 1.13.0 olmalı). Eklenti bunu ilk yoklamasında öğrenir, sonra dakikada bir sorar.');

        return self::SUCCESS;
    }
}
