<?php

namespace App\Services\Site;

use App\Models\Brand;
use App\Models\Suggestion;
use App\Services\Brand\NewOrder;

/**
 * İçerik kuralı (Marka Düzeni 2.7): the system does not pile up article ideas by itself.
 *  - at most MONTHLY new article ideas per brand in a calendar month (it was four every week);
 *  - no new idea while BACKLOG written articles wait unpublished: the waiting ones go out or are merged first;
 *  - a brand in the "yeni düzen" gets no automatic ideas at all: its articles are picked in the monthly session.
 * Only the automatic weekly run obeys it; an idea the operator asks for by hand is always made.
 */
final class ContentRule
{
    public const int MONTHLY = 4;

    public const int BACKLOG = 5;

    /** @return ?string why the automatic run makes no idea for this brand now (Turkish, shown on İçerik); null = it may */
    public static function hold(Brand $brand): ?string
    {
        if (NewOrder::active((int) $brand->id)) {
            return 'Bu marka yeni düzende: yazı fikri kendiliğinden üretilmez, aylık oturumda seçilir.';
        }
        $waiting = self::unpublished($brand);
        if ($waiting >= self::BACKLOG) {
            return $waiting.' yazılmış yazı yayımlanmayı bekliyor. '.self::BACKLOG.' bekleyen varken yeni fikir açılmaz; önce bekleyenler yayımlanır ya da birleştirilir.';
        }
        $month = self::openedThisMonth($brand);
        if ($month >= self::MONTHLY) {
            return 'Bu ay '.$month.' yazı fikri açıldı. Ayda en çok '.self::MONTHLY.' yeni yazı; sıradaki fikirler ay başında gelir.';
        }

        return null;
    }

    /** How many ideas the automatic run may still open this month. */
    public static function room(Brand $brand): int
    {
        return max(0, self::MONTHLY - self::openedThisMonth($brand));
    }

    /** Articles written and not yet published. */
    public static function unpublished(Brand $brand): int
    {
        return Suggestion::query()->where('brand_id', $brand->id)->where('action_type', SiteSuggestionTypes::CONTENT)
            ->whereIn('status', [Suggestion::OPEN, Suggestion::APPROVED])->whereNotNull('action->article')->count();
    }

    public static function openedThisMonth(Brand $brand): int
    {
        return Suggestion::query()->where('brand_id', $brand->id)->where('action_type', SiteSuggestionTypes::CONTENT)
            ->where('created_at', '>=', now('Europe/Istanbul')->startOfMonth()->utc())->where('status', '!=', Suggestion::DISMISSED)->count();
    }
}
