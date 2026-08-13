<?php

namespace App\Support\Demo;

use ZipArchive;

/**
 * Deterministic Site Connector catalog fixtures (WordPress demo package).
 *
 * Provider product names (WordPress) stay untranslated as proper nouns.
 */
final class SiteConnectorFixtures
{
    public const string WORDPRESS = 'wordpress';

    public const string DEMO_VERSION = '0.1.0-demo';

    public const string DEMO_ZIP_RELATIVE = 'site-connectors/wordpress/moxdop-wordpress-connector-0.1.0-demo.zip';

    /**
     * @return list<array<string, mixed>>
     */
    public static function catalog(): array
    {
        return [
            self::wordpress(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function connector(string $id): ?array
    {
        foreach (self::catalog() as $item) {
            if (($item['id'] ?? '') === $id) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function wordpress(): array
    {
        return [
            'id' => self::WORDPRESS,
            'name' => 'WordPress',
            'slug' => 'wordpress',
            'logo_type' => 'website',
            'status' => 'demo_available',
            'status_label' => 'Demo available',
            'summary' => 'Pairs a managed Website Digital Asset with a WordPress site via a read-only demo connector package.',
            'capabilities' => [
                'Site identity + CMS detection signals',
                'Plugin / theme inventory snapshot (demo)',
                'Pairing code handshake (demo state only)',
                'No production writes — Demo Mode boundary',
            ],
            'requirements' => [
                'WordPress 6.x (demo assumption)',
                'HTTPS site URL',
                'Operator ACCESS_APP session',
            ],
            'demo_boundary' => 'DEMO CONNECTOR PACKAGE — NOT PRODUCTION INSTALLABLE',
            'releases' => self::releases(),
            'install_steps' => self::installSteps(),
            'connected_sites' => self::connectedSites(),
            'pairing' => self::pairingState(),
            'activity' => self::activity(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function releases(): array
    {
        return [
            [
                'version' => 'v0.1.0 Demo',
                'channel' => 'demo',
                'released_at' => '2026-08-01',
                'notes' => 'First demo package with README-only payload. Not for production installs.',
                'downloadable' => true,
                'filename' => 'moxdop-wordpress-connector-0.1.0-demo.zip',
            ],
        ];
    }

    /**
     * @return list<array{step: int, title: string, detail: string}>
     */
    public static function installSteps(): array
    {
        return [
            [
                'step' => 1,
                'title' => 'Download the demo package',
                'detail' => 'Download the labeled demo ZIP from Releases. It contains README.txt only.',
            ],
            [
                'step' => 2,
                'title' => 'Review Demo boundary',
                'detail' => 'Confirm the package is marked DEMO CONNECTOR PACKAGE — NOT PRODUCTION INSTALLABLE.',
            ],
            [
                'step' => 3,
                'title' => 'Simulate pairing',
                'detail' => 'In Demo Mode, pairing stays in-session. No live WordPress install is required.',
            ],
            [
                'step' => 4,
                'title' => 'Bind to Website asset',
                'detail' => 'Connected Sites lists demo bindings to atlasdental.example.',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function connectedSites(): array
    {
        return [
            [
                'site' => 'atlasdental.example',
                'cms' => 'WordPress',
                'asset_id' => DemoCatalog::WEBSITE_ASSET_ID,
                'brand' => 'Atlas Dental Ankara',
                'pair_state' => 'demo_paired',
                'pair_label' => 'Demo paired',
                'last_check' => '2 hours ago',
            ],
            [
                'site' => 'staging.atlasdental.example',
                'cms' => 'WordPress',
                'asset_id' => DemoCatalog::WEBSITE_ASSET_ID,
                'brand' => 'Atlas Dental Ankara',
                'pair_state' => 'awaiting_code',
                'pair_label' => 'Awaiting pairing code',
                'last_check' => 'Yesterday',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function pairingState(): array
    {
        return [
            'state' => 'demo_ready',
            'code_hint' => 'DEMO-PAIR-ATLAS',
            'expires' => 'Demo codes do not expire (fixtures).',
            'note' => 'Pairing is simulated — no outbound write to WordPress.',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function activity(): array
    {
        return [
            [
                'at' => '2 hours ago',
                'event' => 'Demo package downloaded',
                'actor' => 'Operator',
                'detail' => 'moxdop-wordpress-connector-0.1.0-demo.zip',
            ],
            [
                'at' => 'Yesterday',
                'event' => 'Pairing code issued (demo)',
                'actor' => 'System',
                'detail' => 'DEMO-PAIR-ATLAS for atlasdental.example',
            ],
            [
                'at' => '3 days ago',
                'event' => 'Catalog entry published',
                'actor' => 'MoxDOP',
                'detail' => 'WordPress connector v0.1.0 Demo',
            ],
        ];
    }

    /**
     * Ensure the deterministic demo ZIP exists on the local disk root (storage/app).
     */
    public static function ensureDemoZip(): string
    {
        $absolute = storage_path('app/'.self::DEMO_ZIP_RELATIVE);
        $directory = dirname($absolute);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        if (is_file($absolute) && filesize($absolute) > 0) {
            return $absolute;
        }

        $readme = "DEMO CONNECTOR PACKAGE — NOT PRODUCTION INSTALLABLE\n"
            ."\n"
            ."MoxDOP WordPress Connector v0.1.0 Demo\n"
            ."\n"
            ."This archive is a deterministic Demo Mode artifact for the Site Connectors\n"
            ."workspace. It does not contain a production plugin and must not be installed\n"
            ."on a live WordPress site.\n"
            ."\n"
            ."Provider name: WordPress (proper noun — keep untranslated).\n";

        $zip = new ZipArchive;
        if ($zip->open($absolute, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Unable to create demo connector ZIP.');
        }
        $zip->addFromString('README.txt', $readme);
        $zip->close();

        return $absolute;
    }

    public static function demoZipDownloadName(): string
    {
        return 'moxdop-wordpress-connector-0.1.0-demo.zip';
    }
}
