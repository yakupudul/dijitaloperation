<?php

namespace App\Http\Controllers\Demo;

use App\Support\Demo\SiteConnectorFixtures;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class SiteConnectorDownloadController
{
    public function __invoke(Request $request, string $connector): BinaryFileResponse
    {
        abort_if(SiteConnectorFixtures::connector($connector) === null, 404);

        $absolute = SiteConnectorFixtures::ensureDemoZip();
        $filename = SiteConnectorFixtures::demoZipDownloadName();

        return response()->download($absolute, $filename, [
            'Content-Type' => 'application/zip',
            'X-MoxDOP-Package' => 'DEMO CONNECTOR PACKAGE — NOT PRODUCTION INSTALLABLE',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
