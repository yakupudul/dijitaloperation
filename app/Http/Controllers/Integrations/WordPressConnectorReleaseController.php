<?php

namespace App\Http\Controllers\Integrations;

use App\Services\Integrations\WordPress\WordPressConnectorPackage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Serves one hash-named connector ZIP behind a short-lived signed link (plugin self-update, 1.4.1). */
final class WordPressConnectorReleaseController
{
    public function __invoke(string $file): BinaryFileResponse
    {
        $path = WordPressConnectorPackage::releaseDirectory().'/'.basename($file);
        abort_unless(is_file($path), 404);

        return response()->download($path, $file, ['Content-Type' => 'application/zip']);
    }
}
