<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Services\Intel\KmlBuilder;
use App\Support\Permissions;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/** Download the brand's KML pin file for Google My Maps (Faz 8b). Nothing is sent to Google. */
final class KmlExportController extends Controller
{
    public function __invoke(Brand $brand, KmlBuilder $builder): Response
    {
        abort_unless(auth()->user()?->is_active && auth()->user()?->can(Permissions::ACCESS_APP), 403);
        $result = $builder->build($brand);

        return response($result['kml'], 200, [
            'Content-Type' => 'application/vnd.google-earth.kml+xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.Str::slug($brand->name).'-harita-pinleri.kml"',
        ]);
    }
}
