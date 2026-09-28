<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\DigitalAsset;
use App\Services\ContentDelivery\ContentExportService;
use App\Services\ContentStudio\ContentStudio;
use App\Support\Roles;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * ADR-076: downloads the site's new-page drafts or İçerik Stüdyosu articles (`?articles=1,2`) as a WordPress WXR file (Admin only). Articles that still break a
 * blocking sector rule are refused. Nothing is sent to the site.
 */
final class WxrExportController extends Controller
{
    public function __invoke(Request $request, int $site, ContentExportService $exports): Response
    {
        $user = $request->user();
        abort_unless($user?->is_active && $user->hasRole(Roles::ADMIN), 403, 'Dışa aktarmayı yalnız Admin yapabilir.');
        $asset = DigitalAsset::query()->where('type', 'website')->findOrFail($site);
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) $request->query('items', ''))), fn (int $id): bool => $id > 0));
        $articles = array_values(array_filter(array_map('intval', explode(',', (string) $request->query('articles', ''))), fn (int $id): bool => $id > 0));
        try {
            // Faz 4: İçerik Stüdyosu articles (with their language versions); otherwise the site-fix new pages.
            $export = $articles !== []
                ? app(ContentStudio::class)->exportWxr($asset, array_slice($articles, 0, 200))
                : $exports->wxr($asset, $exports->siteFixArticles($asset, array_slice($ids, 0, 200)));
        } catch (ValidationException $exception) {
            return response((string) collect($exception->errors())->flatten()->first(), 422, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        return response($export['xml'], 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$export['filename'].'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
