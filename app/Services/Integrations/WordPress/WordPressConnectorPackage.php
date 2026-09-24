<?php

namespace App\Services\Integrations\WordPress;

use FilesystemIterator;
use Illuminate\Support\Facades\URL;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

final class WordPressConnectorPackage
{
    public function filename(): string
    {
        return 'moxdop-wordpress-connector-'.config('moxdop-wordpress.connector_version', '1.0.0').'.zip';
    }

    /**
     * 1.4.1: a fixed copy of the ZIP named by its hash, so the file the plugin downloads is exactly the one whose
     * SHA-256 MoxDOP sent. Copies older than a day are removed.
     *
     * @return array{version: string, sha256: string, file: string, path: string, url: string}
     */
    public function release(): array
    {
        $built = $this->build();
        $sha = hash_file('sha256', $built);
        $version = (string) config('moxdop-wordpress.connector_version', '1.0.0');
        $file = 'moxdop-wordpress-connector-'.$version.'-'.substr($sha, 0, 16).'.zip';
        $directory = self::releaseDirectory();
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('WordPress Connector release directory could not be created.');
        }
        foreach (glob($directory.'/*.zip') ?: [] as $old) {
            if (filemtime($old) < time() - 86400) {
                @unlink($old);
            }
        }
        $path = $directory.'/'.$file;
        if (! is_file($path) && ! copy($built, $path)) {
            throw new RuntimeException('WordPress Connector release could not be stored.');
        }

        return ['version' => $version, 'sha256' => $sha, 'file' => $file, 'path' => $path,
            'url' => URL::temporarySignedRoute('api.connectors.wordpress.release', now()->addMinutes((int) config('moxdop-wordpress.package_link_minutes', 15)), ['file' => $file])];
    }

    public static function releaseDirectory(): string
    {
        return storage_path('app/site-connectors/wordpress/releases');
    }

    public function build(): string
    {
        $source = base_path('connectors/wordpress/moxdop-connector');
        if (! is_dir($source)) {
            throw new RuntimeException('WordPress Connector source is unavailable.');
        }

        $directory = storage_path('app/site-connectors/wordpress');
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('WordPress Connector package directory could not be created.');
        }
        $target = $directory.'/'.$this->filename();
        $zip = new ZipArchive;
        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('WordPress Connector ZIP could not be created.');
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($source) + 1);
            $zip->addFile($file->getPathname(), 'moxdop-connector/'.$relative);
        }
        $zip->close();

        if (! is_file($target) || filesize($target) === 0) {
            throw new RuntimeException('WordPress Connector ZIP is empty.');
        }

        return $target;
    }
}
