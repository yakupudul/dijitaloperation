<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Gbp\Desk\GbpDesk;
use App\Services\Gbp\Desk\ReviewDesk;
use App\Services\Gbp\GbpDailyWorkspace;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * İşletme profilleri › Yorumlar › "PDF indir (marka onayı)": the reviews waiting for a reply with the reply MoxDOP
 * would send (AI draft or the operator's own text), numbered, for the brand to approve before anything is published.
 * The picked reviews (`yorumlar`), else every waiting review that has a reply text, in the brand filter (`marka`).
 */
final class GbpReviewRepliesPdfController extends Controller
{
    public const int MAX = 300;

    public function __invoke(Request $request, GbpDesk $desk, ReviewDesk $reviews, GbpDailyWorkspace $daily): Response
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && $actor->is_active, 403);
        $brandId = $request->integer('marka') ?: null;
        $locations = $desk->locations($brandId);
        $resources = $daily->resourceIds($locations->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $picked = array_filter(array_map('intval', explode(',', (string) $request->query('yorumlar', ''))));
        $rows = array_values(array_filter($reviews->reviews($resources, 'bekleyen', '', 2000)['rows'],
            fn (array $r): bool => ! ReviewDesk::busy($r) && trim((string) $r['draft']) !== '' && ($picked === [] || in_array($r['id'], $picked, true))));
        abort_if($rows === [], 404, 'İndirilecek hazır yanıt yok: önce taslak yazdırın ya da yanıtı kendiniz yazın.');
        $rows = array_slice($rows, 0, self::MAX);
        $byAsset = $locations->keyBy('id');
        $brandNames = array_values(array_unique(array_filter(array_map(fn (array $r): string => (string) $byAsset->get($r['asset_id'])?->brand?->name, $rows))));
        $title = count($brandNames) === 1 ? $brandNames[0] : 'Markalar';

        return Pdf::loadView('operator.gbp.review-replies-pdf', [
            'title' => $title,
            'date' => now('Europe/Istanbul')->format('d.m.Y'),
            'rows' => $rows,
            'names' => $locations->mapWithKeys(fn ($l): array => [(int) $l->id => GbpDesk::shortName((string) $l->name)])->all(),
        ])->setPaper('a4')->download('yorum-yanitlari-'.Str::slug($title).'-'.now('Europe/Istanbul')->format('Y-m-d').'.pdf');
    }
}
