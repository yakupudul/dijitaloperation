<?php

namespace App\Services\ContentDelivery;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use XMLWriter;

/**
 * ADR-076: WordPress eXtended RSS 1.2 (WXR) file of N articles for Tools › Import › WordPress. Every item is a draft
 * with title, slug, body (content:encoded), excerpt, dates, categories / tags (nicename) and Yoast + Rank Math SEO
 * fields. The importer drops Polylang languages and links, so each item also carries `_moxdop_language` and
 * `_moxdop_translation_key`: with the MoxDOP Connector (≥ 1.5.0) installed, Tools › MoxDOP Polylang links them after
 * the import. Callers must pass only articles that passed ContentComplianceGate.
 */
final class WxrExporter
{
    public const string NS_EXCERPT = 'http://wordpress.org/export/1.2/excerpt/';

    public const string NS_CONTENT = 'http://purl.org/rss/1.0/modules/content/';

    public const string NS_WFW = 'http://wellformedweb.org/CommentAPI/';

    public const string NS_DC = 'http://purl.org/dc/elements/1.1/';

    public const string NS_WP = 'http://wordpress.org/export/1.2/';

    /** Language of the export (its terms keep plain slugs; other languages get a "-{lang}" suffix). */
    private string $primaryLanguage = 'tr';

    /**
     * @param  iterable<ArticleDraft>  $articles
     * @param  array{site_url?: string, site_title?: string, language?: string, author_login?: string, author_display_name?: string, timezone?: string, generated_at?: CarbonImmutable}  $options
     */
    public function export(iterable $articles, array $options = []): string
    {
        $siteUrl = rtrim((string) ($options['site_url'] ?? 'https://example.com'), '/');
        $author = Str::limit(preg_replace('/[^A-Za-z0-9._@-]/', '', (string) ($options['author_login'] ?? config('moxdop-wordpress.wxr_author_login', 'admin'))) ?: 'admin', 60, '');
        $timezone = (string) ($options['timezone'] ?? config('app.timezone', 'UTC'));
        $now = ($options['generated_at'] ?? CarbonImmutable::now())->setTimezone('UTC');
        $articles = is_array($articles) ? array_values($articles) : iterator_to_array($articles, false);
        $this->primaryLanguage = strtolower((string) ($options['language'] ?? 'tr'));

        $x = new XMLWriter;
        $x->openMemory();
        $x->setIndent(true);
        $x->setIndentString("\t");
        $x->startDocument('1.0', 'UTF-8');
        $x->writeComment(' MoxDOP WXR 1.2 export. Import: Tools > Import > WordPress. Then (Polylang): Tools > MoxDOP Polylang. ');
        $x->startElement('rss');
        $x->writeAttribute('version', '2.0');
        $x->writeAttribute('xmlns:excerpt', self::NS_EXCERPT);
        $x->writeAttribute('xmlns:content', self::NS_CONTENT);
        $x->writeAttribute('xmlns:wfw', self::NS_WFW);
        $x->writeAttribute('xmlns:dc', self::NS_DC);
        $x->writeAttribute('xmlns:wp', self::NS_WP);
        $x->startElement('channel');
        $x->writeElement('title', (string) ($options['site_title'] ?? parse_url($siteUrl, PHP_URL_HOST) ?? 'MoxDOP'));
        $x->writeElement('link', $siteUrl);
        $x->writeElement('description', 'MoxDOP makale taslakları');
        $x->writeElement('pubDate', $now->format(DATE_RSS));
        $x->writeElement('language', $this->primaryLanguage);
        $x->writeElement('wp:wxr_version', '1.2');
        $x->writeElement('wp:base_site_url', $siteUrl);
        $x->writeElement('wp:base_blog_url', $siteUrl);

        $x->startElement('wp:author');
        $x->writeElement('wp:author_id', '1');
        $this->cdata($x, 'wp:author_login', $author);
        $this->cdata($x, 'wp:author_email', '');
        $this->cdata($x, 'wp:author_display_name', (string) ($options['author_display_name'] ?? $author));
        $this->cdata($x, 'wp:author_first_name', '');
        $this->cdata($x, 'wp:author_last_name', '');
        $x->endElement();

        $terms = ['category' => [], 'post_tag' => []];
        foreach ($articles as $article) {
            if ($article->postType !== 'post') {
                continue;
            }
            foreach (['category' => $article->categories, 'post_tag' => $article->tags] as $taxonomy => $list) {
                foreach ($list as $term) {
                    $terms[$taxonomy][$this->nicename($term['name'], $article->language)] = $term['name'];
                }
            }
        }
        $termId = 1;
        foreach ($terms['category'] as $nicename => $name) {
            $x->startElement('wp:category');
            $x->writeElement('wp:term_id', (string) $termId++);
            $this->cdata($x, 'wp:category_nicename', $nicename);
            $this->cdata($x, 'wp:category_parent', '');
            $this->cdata($x, 'wp:cat_name', $name);
            $x->endElement();
        }
        foreach ($terms['post_tag'] as $nicename => $name) {
            $x->startElement('wp:tag');
            $x->writeElement('wp:term_id', (string) $termId++);
            $this->cdata($x, 'wp:tag_slug', $nicename);
            $this->cdata($x, 'wp:tag_name', $name);
            $x->endElement();
        }

        foreach ($articles as $index => $article) {
            $this->item($x, $article, $index + 1, $siteUrl, $author, $timezone, $now);
        }

        $x->endElement();
        $x->endElement();
        $x->endDocument();

        return $x->outputMemory();
    }

    public function filename(string $siteName, CarbonImmutable $at): string
    {
        return Str::slug($siteName ?: 'site').'-moxdop-wxr-'.$at->format('Y-m-d').'.xml';
    }

    private function item(XMLWriter $x, ArticleDraft $article, int $postId, string $siteUrl, string $author, string $timezone, CarbonImmutable $now): void
    {
        $slug = $article->effectiveSlug();
        $date = $article->postDate ?? $now;
        $local = $date->setTimezone($timezone);
        $gmt = $date->setTimezone('UTC');

        $x->startElement('item');
        $x->writeElement('title', $article->title);
        $x->writeElement('link', $siteUrl.'/?p='.$postId);
        $x->writeElement('pubDate', $gmt->format(DATE_RSS));
        $this->cdata($x, 'dc:creator', $author);
        $x->startElement('guid');
        $x->writeAttribute('isPermaLink', 'false');
        $x->text($siteUrl.'/?p='.$postId.'&moxdop='.$article->reference);
        $x->endElement();
        $x->writeElement('description', '');
        $this->cdata($x, 'content:encoded', $article->html);
        $this->cdata($x, 'excerpt:encoded', $article->excerpt);
        $x->writeElement('wp:post_id', (string) $postId);
        $this->cdata($x, 'wp:post_date', $local->format('Y-m-d H:i:s'));
        $this->cdata($x, 'wp:post_date_gmt', $gmt->format('Y-m-d H:i:s'));
        $this->cdata($x, 'wp:post_modified', $now->setTimezone($timezone)->format('Y-m-d H:i:s'));
        $this->cdata($x, 'wp:post_modified_gmt', $now->format('Y-m-d H:i:s'));
        $this->cdata($x, 'wp:comment_status', 'closed');
        $this->cdata($x, 'wp:ping_status', 'closed');
        $this->cdata($x, 'wp:post_name', $slug);
        $this->cdata($x, 'wp:status', 'draft');
        $x->writeElement('wp:post_parent', '0');
        $x->writeElement('wp:menu_order', '0');
        $this->cdata($x, 'wp:post_type', $article->postType);
        $this->cdata($x, 'wp:post_password', '');
        $x->writeElement('wp:is_sticky', '0');
        if ($article->postType === 'post') {
            foreach (['category' => $article->categories, 'post_tag' => $article->tags] as $domain => $list) {
                foreach ($list as $term) {
                    $x->startElement('category');
                    $x->writeAttribute('domain', $domain);
                    $x->writeAttribute('nicename', $this->nicename($term['name'], $article->language));
                    $x->writeCdata($term['name']);
                    $x->endElement();
                }
            }
        }
        $meta = [
            '_yoast_wpseo_title' => $article->metaTitle,
            '_yoast_wpseo_metadesc' => $article->metaDescription,
            '_yoast_wpseo_focuskw' => $article->focusKeyword,
            'rank_math_title' => $article->metaTitle,
            'rank_math_description' => $article->metaDescription,
            'rank_math_focus_keyword' => $article->focusKeyword,
            '_moxdop_draft_reference' => $article->reference,
            '_moxdop_translation_key' => (string) ($article->translationKey ?? ($article->language !== null ? $article->reference : '')),
            '_moxdop_language' => (string) $article->language,
        ];
        foreach ($meta as $key => $value) {
            if (trim($value) === '') {
                continue;
            }
            $x->startElement('wp:postmeta');
            $this->cdata($x, 'wp:meta_key', $key);
            $this->cdata($x, 'wp:meta_value', $value);
            $x->endElement();
        }
        $x->endElement();
    }

    /** The term slug, language-suffixed for non-Turkish terms so Polylang can keep one term per language. */
    private function nicename(string $name, ?string $language): string
    {
        $slug = Str::slug($name, '-', $language ?? 'tr') ?: 'kategori';

        return $language !== null && $language !== $this->primaryLanguage ? $slug.'-'.$language : $slug;
    }

    private function cdata(XMLWriter $x, string $element, string $value): void
    {
        $x->startElement($element);
        // "]]>" inside the value would end the section early; split it like WordPress's own exporter.
        $x->writeCdata(str_replace(']]>', ']]]]><![CDATA[>', $value));
        $x->endElement();
    }
}
