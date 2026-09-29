<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\AnalystDecision;
use App\Models\User;
use App\Services\Advisor\GoogleAds\GoogleAdsEditorExport;
use App\Services\Analyst\GoogleAds\GoogleAdsAnalyst;
use App\Support\ServiceScope;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Brand workspace › Google Ads "Editor dosyası indir": the card's own items (negatives, new keywords, AI RSA draft) as a
 * Google Ads Editor import file. Nothing is sent to Google Ads; the operator imports and posts it in Editor.
 */
final class GoogleAdsEditorDecisionExportController extends Controller
{
    public function __invoke(Request $request, int $brand, int $decision, GoogleAdsAnalyst $analyst, GoogleAdsEditorExport $export): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->is_active, 403);
        abort_unless(app(ServiceScope::class)->isBrandOperational($brand), 403, ServiceScope::NOT_SERVED);
        $card = AnalystDecision::query()->where('brand_id', $brand)->where('channel', 'google_ads')->where('action_type', 'export_editor')->findOrFail($decision);
        $result = $analyst->editorRows($card);
        if ($result['rows'] === []) {
            return response('Bu karttan Editor dosyasına çevrilebilen satır çıkmadı (kampanya / reklam grubu adı ya da hazır metin taslağı yok); adımları elle uygula.', 422,
                ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        return response($export->file($result['rows']), 200, [
            'Content-Type' => 'text/csv; charset=UTF-16LE',
            'Content-Disposition' => 'attachment; filename="moxdop-google-ads-editor-'.$card->id.'.csv"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
