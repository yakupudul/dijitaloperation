<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Gbp\Desk\GbpDesk;
use App\Services\Gbp\Desk\ReviewDesk;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * İşletme profilleri › Yorumlar › "Yazdırılabilir kart" (ADR-079): an A6 counter card with the branch name, Google's
 * "write a review" QR code, printed from the browser (two cards per A4 page side by side). `?tema=koyu` prints the
 * dark version; the brand's logo heads the card when it has one.
 */
final class GbpReviewCardController extends Controller
{
    public function __invoke(Request $request, int $assetId, GbpDesk $desk, ReviewDesk $reviews): View
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && $actor->is_active, 403);
        $location = $desk->locations()->firstWhere('id', $assetId) ?? abort(404);
        $kit = $reviews->kit($location);
        abort_if($kit['link'] === null, 404, 'Bu profilin Google yorum bağlantısı henüz yok.');
        $snapshot = $desk->snapshots([(int) $location->id])[$location->id] ?? null;

        return view('operator.gbp.review-card', [
            'business' => (string) ($location->brand?->name ?: GbpDesk::shortName((string) $location->name)),
            'branch' => GbpDesk::shortName((string) $location->name),
            'area' => (string) ($snapshot['area'] ?? ''),
            'link' => $kit['link'],
            'qr' => $kit['qr'],
            'logo' => filter_var((string) $location->brand?->logo_url, FILTER_VALIDATE_URL) ? (string) $location->brand?->logo_url : null,
            'theme' => $request->query('tema') === 'koyu' ? 'koyu' : 'acik',
        ]);
    }
}
