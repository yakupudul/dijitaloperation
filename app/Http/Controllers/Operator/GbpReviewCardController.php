<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Gbp\Desk\GbpDesk;
use App\Services\Gbp\Desk\ReviewDesk;
use Illuminate\Contracts\View\View;

/**
 * İşletme profilleri › Yorumlar › "Yazdırılabilir kart" (ADR-079): an A6 counter card with the branch name, Google's
 * "write a review" QR code, printed from the browser (two cards per A4 page side by side, crop marks at the corners).
 * White only: the dark version was dropped (2026-11-13) because a clinic prints it on white paper. The brand's logo
 * heads the card when it has one.
 */
final class GbpReviewCardController extends Controller
{
    public function __invoke(int $assetId, GbpDesk $desk, ReviewDesk $reviews): View
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && $actor->is_active, 403);
        $location = $desk->locations()->firstWhere('id', $assetId) ?? abort(404);
        $kit = $reviews->kit($location);
        abort_if($kit['link'] === null, 404, 'Bu profilin Google yorum bağlantısı henüz yok.');
        $snapshot = $desk->snapshots([(int) $location->id])[$location->id] ?? null;

        $business = (string) ($location->brand?->name ?: GbpDesk::shortName((string) $location->name));
        $branch = GbpDesk::shortName((string) $location->name);

        return view('operator.gbp.review-card', [
            'business' => $business,
            'branch' => $branch,
            'place' => self::place($business, $branch, (string) ($snapshot['area'] ?? '')),
            'link' => $kit['link'],
            'qr' => $kit['qr'],
            'logo' => filter_var((string) $location->brand?->logo_url, FILTER_VALIDATE_URL) ? (string) $location->brand?->logo_url : null,
        ]);
    }

    /**
     * The line under the business name: the branch without the repeated business name, then the parts of the area the
     * branch does not already say ("Avrupadent Buca" + "Buca, İzmir" under "Avrupadent" → "Buca, İzmir").
     */
    public static function place(string $business, string $branch, string $area): string
    {
        $lower = static fn (string $text): string => ReviewDesk::lower(trim($text));
        $rest = trim($branch);
        if ($lower($business) !== '' && str_starts_with($lower($rest), $lower($business))) {
            $rest = trim((string) preg_replace('/^[\s\-–—·,|:]+/u', '', mb_substr($rest, mb_strlen(trim($business)))));
        }
        $parts = [$rest];
        foreach (explode(',', $area) as $piece) {
            if (trim($piece) !== '' && ! str_contains($lower($branch), $lower($piece))) {
                $parts[] = trim($piece);
            }
        }

        return implode(', ', array_filter($parts, static fn (string $part): bool => $part !== ''));
    }
}
