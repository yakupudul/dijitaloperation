<?php

namespace App\Services\Integrations\WordPress;

use FilesystemIterator;
use Illuminate\Support\Facades\URL;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

/**
 * Connector 1.33.0: a WordPress theme kept in this repository (connectors/wordpress/themes/<slug>), zipped and served
 * behind a short-lived signed link named by its hash, so a site build installs exactly the file whose SHA-256 it sent.
 */
final class WordPressThemePackage
{
    public static function sourceDirectory(string $slug): string
    {
        return base_path('connectors/wordpress/themes/'.$slug);
    }

    public static function releaseDirectory(): string
    {
        return storage_path('app/site-connectors/wordpress/themes');
    }

    public static function exists(string $slug): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9-]{1,40}$/', $slug) === 1 && is_file(self::sourceDirectory($slug).'/style.css');
    }

    /** @return array{slug: string, sha256: string, file: string, path: string, url: string} */
    public function release(string $slug): array
    {
        if (! self::exists($slug)) {
            throw new RuntimeException('Tema bulunamadı: '.$slug);
        }
        $directory = self::releaseDirectory();
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('Tema paketi klasörü oluşturulamadı.');
        }
        foreach (glob($directory.'/*.zip') ?: [] as $old) {
            if (filemtime($old) < time() - 86400) {
                @unlink($old);
            }
        }
        $built = $this->build($slug, $directory.'/'.$slug.'-build.zip');
        $sha = hash_file('sha256', $built);
        $file = $slug.'-'.substr($sha, 0, 16).'.zip';
        $path = $directory.'/'.$file;
        if (! is_file($path) && ! rename($built, $path)) {
            throw new RuntimeException('Tema paketi saklanamadı.');
        }
        @unlink($built);

        return ['slug' => $slug, 'sha256' => $sha, 'file' => $file, 'path' => $path,
            'url' => URL::temporarySignedRoute('api.connectors.wordpress.theme', now()->addMinutes((int) config('moxdop-wordpress.package_link_minutes', 15)), ['file' => $file])];
    }

    private function build(string $slug, string $target): string
    {
        $source = self::sourceDirectory($slug);
        $zip = new ZipArchive;
        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Tema ZIP oluşturulamadı.');
        }
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY) as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);
        $zip->addEmptyDir($slug);
        foreach ($files as $path) {
            $name = $slug.'/'.str_replace('\\', '/', substr($path, strlen($source) + 1));
            $zip->addFile($path, $name);
            $zip->setMtimeName($name, 0);
        }
        $zip->close();

        return $target;
    }
}
