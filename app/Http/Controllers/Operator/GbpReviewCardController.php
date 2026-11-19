<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\User;
use App\Services\Gbp\Desk\GbpDesk;
use App\Services\Gbp\Desk\ReviewDesk;
use App\Services\Site\Backlinks\LinkDesk;
use App\Support\LogoAccent;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
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
 *
 * Four designs (yakup 2026-11-19 20:18 "hepsi olsun, müşteri hangisini beğenirse o kalır"): sakin, antet, renk, cerceve.
 * Until the business picks one (brands.review_card_design) the page shows all four side by side, each with "Bu tasarımı
 * seç"; after that it shows the picked one, two per A4 page, with a link back to the four.
 */
final class GbpReviewCardController extends Controller
{
    /** @var array<string, string> design key => name shown to the business */
    public const DESIGNS = ['sakin' => 'Sakin', 'antet' => 'Antet', 'renk' => 'Renk alanı', 'cerceve' => 'Çerçeveli kod'];

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

        $chosen = self::design($location->brand_id !== null ? Brand::query()->whereKey($location->brand_id)->value('review_card_design') : null);
        $preview = self::design((string) $request->query('tasarim', ''));
        $public = $request->routeIs('gbp-review-card.public');

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
            'public' => $public,
            'designs' => self::DESIGNS,
            'chosen' => $chosen,
            // One design: the one asked for in the address, else the picked one; null = show all four to choose.
            'design' => $request->boolean('tumu') ? null : ($preview ?? $chosen),
            'chooseUrl' => URL::signedRoute('gbp-review-card.choose', ['assetId' => (int) $location->id]),
            'allUrl' => $public ? URL::signedRoute('gbp-review-card.public', ['assetId' => (int) $location->id, 'tumu' => 1])
                : route('operator.gbp-review-card', ['assetId' => (int) $location->id, 'tumu' => 1]),
        ]);
    }

    /** The business (or the operator) picks a design; it stays for the whole brand. */
    public function choose(Request $request, int $assetId, GbpDesk $desk): RedirectResponse
    {
        $location = $desk->locations()->firstWhere('id', $assetId) ?? abort(404);
        $design = self::design((string) $request->input('tasarim', '')) ?? abort(422);
        if ($location->brand_id !== null) {
            Brand::query()->whereKey($location->brand_id)->update(['review_card_design' => $design]);
        }

        return redirect()->to(auth()->user() instanceof User && ! $request->boolean('genel')
            ? route('operator.gbp-review-card', ['assetId' => $assetId])
            : self::shareUrl($assetId));
    }

    public static function design(?string $key): ?string
    {
        return $key !== null && array_key_exists($key, self::DESIGNS) ? $key : null;
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
