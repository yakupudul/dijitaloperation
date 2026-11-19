<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Gbp\Desk\GbpDesk;
use App\Services\Gbp\Desk\ReviewDesk;
use App\Services\Site\Backlinks\LinkDesk;
use App\Support\LogoAccent;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * İşletme profilleri › Yorumlar › "Yazdırılabilir kart" (ADR-079): an A6 counter card with the branch name, Google's
 * "write a review" QR code, printed from the browser (two cards per A4 page side by side, crop marks at the corners).
 * White only: the dark version was dropped (2026-11-13) because a clinic prints it on white paper.
 *
 * Design "Antet" (yakup 2026-11-19, one standard for every brand): a thin strip in the brand's colour (read from its
 * logo, LogoAccent), the logo, "Deneyiminizi Google’da paylaşın", Google's G with five stars, one line on why, the QR
 * with a one-line instruction, and the area + phone of the profile at the foot. The same page opens without login
 * through a signed link (route gbp-review-card.public) so the operator can send it to the business to print.
 */
final class GbpReviewCardController extends Controller
{
    public function __invoke(Request $request, int $assetId, GbpDesk $desk, ReviewDesk $reviews): View
    {
        // The public route is guarded by the "signed" middleware; the operator route needs an active user.
        if (! $request->routeIs('gbp-review-card.public')) {
            $actor = auth()->user();
            abort_unless($actor instanceof User && $actor->is_active, 403);
        }
        $location = $desk->locations()->firstWhere('id', $assetId) ?? abort(404);
        $kit = $reviews->kit($location);
        abort_if($kit['link'] === null, 404, 'Bu profilin Google yorum bağlantısı henüz yok.');
        $snapshot = $desk->snapshots([(int) $location->id])[$location->id] ?? null;

        $business = (string) ($location->brand?->name ?: GbpDesk::shortName((string) $location->name));
        $branch = GbpDesk::shortName((string) $location->name);

        $logo = filter_var((string) $location->brand?->logo_url, FILTER_VALIDATE_URL) ? (string) $location->brand?->logo_url : null;
        $area = (string) ($snapshot['area'] ?? '');

        return view('operator.gbp.review-card', [
            'business' => $business,
            'branch' => $branch,
            'place' => self::place($business, $branch, $area),
            'area' => self::footArea($area),
            'phone' => trim((string) ($snapshot['phone'] ?? '')),
            'link' => $kit['link'],
            'qr' => $kit['qr'],
            'logo' => $logo,
            'accent' => LogoAccent::for($logo),
            'why' => $location->brand !== null && LinkDesk::isHealth($location->brand)
                ? 'Görüşleriniz, bizi araştıran hastalara yol gösterir.'
                : 'Görüşleriniz, bizi araştıran başka insanlara yol gösterir.',
            'public' => $request->routeIs('gbp-review-card.public'),
        ]);
    }

    /** The link the operator sends to the business: opens the same card without login, never expires. */
    public static function shareUrl(int $assetId): string
    {
        return URL::signedRoute('gbp-review-card.public', ['assetId' => $assetId]);
    }

    /** Foot of the card: district and city ("Pendik, İstanbul"), the province left out when it repeats the city. */
    public static function footArea(string $area): string
    {
        $parts = [];
        foreach (explode(',', $area) as $piece) {
            $piece = trim($piece);
            if ($piece !== '' && ! in_array(ReviewDesk::lower($piece), array_map(ReviewDesk::lower(...), $parts), true)) {
                $parts[] = $piece;
            }
        }

        return implode(', ', array_slice($parts, 0, 2));
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
