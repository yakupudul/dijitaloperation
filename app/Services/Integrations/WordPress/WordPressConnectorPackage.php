<?php

namespace App\Services\Integrations\WordPress;

use FilesystemIterator;
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

