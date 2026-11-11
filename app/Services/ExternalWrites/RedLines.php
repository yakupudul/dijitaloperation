<?php

namespace App\Services\ExternalWrites;

use App\Models\Brand;
use App\Models\ExternalWriteAction;
use App\Services\Compliance\ForbiddenTerms;

/**
 * Kırmızı çizgiler (Bağlantı Düzeni 3.1): the last lock before a text leaves MoxDOP. Every review reply, Business
 * Profile post, profile description and site field (title, description, heading, alt text) is read against the
 * brand's forbidden phrases once more at send time (the same compliance_rules the article gate uses: sector pack,
 * sector and the brand's own rules, "engelle" severity). A text that crosses a line is not sent; the write ends with
 * the phrase and where it stood, so the operator fixes the text and approves again.
 *
 * Why at send time too: the rules can change after a text was approved, and not every path that queues a write
 * checks them (a reply typed by hand, an old scheduled post).
 */
final class RedLines
{
    /** Site fix types whose value is an address or code, not a sentence a visitor reads. */
    private const array NOT_TEXT = ['redirect', 'merge_redirect', 'canonical', 'schema', 'noindex', 'speed'];

    /** @return ?string why the write may not go (Turkish, for the operator); null = nothing crossed */
    public static function crossed(ExternalWriteAction $action): ?string
    {
        $texts = self::texts($action);
        if ($texts === [] || $action->brand_id === null) {
            return null;
        }
        $brand = Brand::query()->find($action->brand_id);
        if ($brand === null) {
            return null;
        }
        $terms = ForbiddenTerms::forBrand($brand);
        foreach ($texts as $where => $text) {
            $hits = $terms->blocking(strip_tags($text));
            if ($hits !== []) {
                return $where.' içinde bu markanın yasak ifadesi var: «'.implode('», «', array_slice($hits, 0, 3)).'». Metni düzeltip yeniden onaylayın.';
            }
        }

        return null;
    }

    /** @return array<string, string> the sentences this write would publish, by where they stand */
    private static function texts(ExternalWriteAction $action): array
    {
        $payload = (array) $action->request_payload;
        $texts = match ($action->action) {
            ExternalWriteAction::ACTION_REVIEW_REPLY => ['Yorum yanıtı' => $payload['comment'] ?? null],
            ExternalWriteAction::ACTION_LOCAL_POST => ['İşletme Profili gönderisi' => $payload['summary'] ?? null],
            ExternalWriteAction::ACTION_PROFILE_FIELDS => ['Profil açıklaması' => data_get($payload, 'fields.description')],
            ExternalWriteAction::ACTION_SITE_FIX => self::siteFixTexts((array) ($payload['changes'] ?? [])),
            default => [],
        };

        return array_filter(array_map(fn (mixed $text): string => is_string($text) ? trim($text) : '', $texts), fn (string $text): bool => $text !== '');
    }

    /**
     * @param  array<int, mixed>  $changes
     * @return array<string, mixed>
     */
    private static function siteFixTexts(array $changes): array
    {
        $texts = [];
        foreach ($changes as $change) {
            $value = is_array($change) ? ($change['value'] ?? null) : null;
            if (! is_string($value) || in_array((string) ($change['type'] ?? ''), self::NOT_TEXT, true) || preg_match('#^(https?://|/)#', $value) === 1) {
                continue;
            }
            $texts['Site düzeltmesi ('.mb_substr((string) ($change['reference'] ?? $change['type']), 0, 80).')'] = $value;
        }

        return $texts;
    }
}
