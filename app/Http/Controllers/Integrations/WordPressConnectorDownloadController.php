<?php

namespace App\Http\Controllers\Integrations;

use App\Services\Integrations\WordPress\WordPressConnectorPackage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class WordPressConnectorDownloadController
{
    public function __invoke(string $connector, WordPressConnectorPackage $package): BinaryFileResponse
    {
        abort_unless($connector === 'wordpress', 404);

        return response()->download($package->build(), $package->filename(), [
            'Content-Type' => 'application/zip',
            'X-MoxDOP-Package' => 'WORDPRESS CONNECTOR PRODUCTION PACKAGE',
        ]);
    }
}

