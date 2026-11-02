<?php

namespace App\Http\Controllers\Integrations;

use App\Services\Integrations\WordPress\WordPressThemePackage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Serves one hash-named theme ZIP behind a short-lived signed link (site build "theme" operation, 1.33.0). */
final class WordPressThemeReleaseController
{
    public function __invoke(string $file): BinaryFileResponse
    {
        $path = WordPressThemePackage::releaseDirectory().'/'.basename($file);
        abort_unless(is_file($path), 404);

        return response()->download($path, $file, ['Content-Type' => 'application/zip']);
    }
}
