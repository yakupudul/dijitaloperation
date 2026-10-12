<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\Strategy;
use App\Models\User;
use App\Services\Strategy\StrategyAdsEditorExport;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Stratejiler › "Ads Editor dosyası": the approved Google Ads strategy's plan as a Google Ads Editor import file
 * (CSV) or the same rows as an Excel workbook with a notes sheet. Only for an approved or running strategy with a
 * plan; nothing is sent to the ad account.
 */
final class StrategyAdsEditorController extends Controller
{
    public function __invoke(int $strategyId, string $format): Response
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && $actor->is_active, 403);
        $strategy = Strategy::query()->findOrFail($strategyId);
        abort_unless($strategy->isExportable(), 404);

        $base = Str::slug($strategy->title).'-ads-editor-v'.$strategy->current_version;
        if ($format === 'xlsx') {
            return response(StrategyAdsEditorExport::xlsx($strategy), 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="'.$base.'.xlsx"',
            ]);
        }

        return response(StrategyAdsEditorExport::csv($strategy), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$base.'.csv"',
        ]);
    }
}
