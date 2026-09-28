<?php

namespace App\Services\ContentDelivery;

use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\User;
use App\Services\ExternalWrites\ExternalWriteService;
use Illuminate\Validation\ValidationException;

/**
 * ADR-076 entry point for sending an article (and its language versions) to WordPress as drafts. It orders the
 * versions (source first), gives them one translation key, checks the languages against the site's Polylang languages
 * and hands them to ExternalWriteService (Admin approval, compliance gate, queue, record, undo = trash all drafts).
 * The connector creates the source draft first and links every translation to it (translation_of).
 */
final class ContentDraftPublisher
{
    public function __construct(
        private readonly ExternalWriteService $writes,
        private readonly LanguageLinkMap $links,
    ) {}

    /**
     * @param  ArticleDraft|array<string, mixed>  $article  the source-language article
     * @param  array<int|string, ArticleDraft|array<string, mixed>>  $languages  its localized versions (each with `language`), any order
     */
    public function publish(User $user, DigitalAsset $site, ArticleDraft|array $article, array $languages = []): ExternalWriteAction
    {
        [$source, $translations] = $this->prepare($site, $article, $languages);

        return $this->writes->requestArticleDrafts($user, $site, $source, $translations);
    }

    /**
     * Normalised, ordered versions: [source, translations]. Throws a Turkish ValidationException for impossible sets.
     *
     * @param  ArticleDraft|array<string, mixed>  $article
     * @param  array<int|string, ArticleDraft|array<string, mixed>>  $languages
     * @return array{0: ArticleDraft, 1: list<ArticleDraft>}
     */
    public function prepare(DigitalAsset $site, ArticleDraft|array $article, array $languages = []): array
    {
        $source = $article instanceof ArticleDraft ? $article : ArticleDraft::fromArray($article);
        $translations = [];
        foreach ($languages as $key => $version) {
            $version = $version instanceof ArticleDraft ? $version : ArticleDraft::fromArray(is_array($version) ? $version + (is_string($key) ? ['language' => $key] : []) : []);
            $translations[] = $version;
        }
        if ($translations === []) {
            return [$source->translationKey === null && $source->language !== null ? $source->with(['translation_key' => $source->reference]) : $source, []];
        }

        $available = array_column($this->links->languages($site), 'slug');
        if ($available === []) {
            throw ValidationException::withMessages(['write' => 'Bu sitede Polylang dilleri görünmüyor (eklenti 1.5.0 ve Polylang gerekli, envanter yenilenmeli). Çevirileri WXR olarak dışa aktarabilirsin.']);
        }
        if ($source->language === null) {
            throw ValidationException::withMessages(['write' => 'Kaynak makalenin dili belirtilmeli.']);
        }
        $key = $source->translationKey ?? $source->reference;
        $seen = [$source->language => true];
        foreach ($translations as $translation) {
            if ($translation->language === null || isset($seen[$translation->language])) {
                throw ValidationException::withMessages(['write' => 'Her çevirinin kaynaktan ve birbirinden farklı bir dili olmalı.']);
            }
            $seen[$translation->language] = true;
        }
        foreach (array_keys($seen) as $language) {
            if (! in_array($language, $available, true)) {
                throw ValidationException::withMessages(['write' => 'Sitede "'.$language.'" dili yok (Polylang dilleri: '.implode(', ', $available).').']);
            }
        }
        $source = $source->with(['translation_key' => $key]);
        $translations = array_map(fn (ArticleDraft $t): ArticleDraft => $t->with([
            'translation_key' => $key,
            'reference' => $t->reference === $source->reference ? $source->reference.'-'.$t->language : $t->reference,
        ]), $translations);

        return [$source, $translations];
    }
}
