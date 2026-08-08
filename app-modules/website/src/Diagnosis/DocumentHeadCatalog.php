<?php

namespace MoxDop\Website\Diagnosis;

/**
 * Document Head diagnosis rule IDs, classifications, and recommendation templates.
 * Catalog prose lives in docs/website/DIAGNOSIS_CATALOG.md — keep IDs aligned.
 */
final class DocumentHeadCatalog
{
    public const string RULE_TITLE_MISSING = 'website:head:title-missing';

    public const string RULE_TITLE_EMPTY = 'website:head:title-empty';

    public const string RULE_TITLE_LENGTH = 'website:head:title-length-heuristic';

    public const string RULE_META_DESCRIPTION_MISSING = 'website:head:meta-description-missing';

    public const string RULE_META_DESCRIPTION_EMPTY = 'website:head:meta-description-empty';

    public const string RULE_META_DESCRIPTION_LENGTH = 'website:head:meta-description-length-heuristic';

    public const string RULE_CHARSET_MISSING = 'website:head:charset-missing';

    public const string RULE_VIEWPORT_MISSING = 'website:head:viewport-missing';

    public const string RULE_ROBOTS_NOINDEX = 'website:head:meta-robots-noindex';

    public const string RULE_JSONLD_MALFORMED = 'website:head:jsonld-malformed';

    public const string RULE_OG_INCOMPLETE = 'website:head:open-graph-incomplete';

    public const string CLASS_PRIMARY_VERIFIED = 'PRIMARY_VERIFIED';

    public const string CLASS_HEURISTIC = 'HEURISTIC';

    public const string CLASS_ADVISORY = 'ADVISORY';

    /** Heuristic display title length band (not a Google ranking rule). */
    public const int TITLE_LENGTH_MAX_HEURISTIC = 60;

    /** Heuristic meta description length band (not a Google ranking rule). */
    public const int META_DESCRIPTION_MIN_HEURISTIC = 50;

    public const int META_DESCRIPTION_MAX_HEURISTIC = 160;

    /**
     * @return list<string>
     */
    public static function ruleIds(): array
    {
        return [
            self::RULE_TITLE_MISSING,
            self::RULE_TITLE_EMPTY,
            self::RULE_TITLE_LENGTH,
            self::RULE_META_DESCRIPTION_MISSING,
            self::RULE_META_DESCRIPTION_EMPTY,
            self::RULE_META_DESCRIPTION_LENGTH,
            self::RULE_CHARSET_MISSING,
            self::RULE_VIEWPORT_MISSING,
            self::RULE_ROBOTS_NOINDEX,
            self::RULE_JSONLD_MALFORMED,
            self::RULE_OG_INCOMPLETE,
        ];
    }

    public static function recommendationAction(string $ruleId): string
    {
        return match ($ruleId) {
            self::RULE_TITLE_MISSING, self::RULE_TITLE_EMPTY => 'Add a concise descriptive <title> for the page that names the primary topic. Dependency: deploy/publish the HTML head change. Success signal: rendered DOM exposes the expected title. Failure signal: title still missing/empty after publish. Watch: Search Console query/page coverage after subsequent crawls.',
            self::RULE_TITLE_LENGTH => 'Shorten the <title> toward a readable length (heuristic ~60 characters). This is a display/UX heuristic, not a Google ranking rule. Success signal: title renders without awkward truncation in browser tabs. Watch: CTR for associated queries after the change.',
            self::RULE_META_DESCRIPTION_MISSING => 'Add a unique meta description summarizing the page. Google may still generate snippets; a clear description helps influence snippet quality. Success signal: description present in rendered head. Watch: Search Console CTR for the page.',
            self::RULE_META_DESCRIPTION_EMPTY => 'Set a non-empty content value on the meta description tag. Success signal: description text present. Watch: snippet quality in Search results.',
            self::RULE_META_DESCRIPTION_LENGTH => 'Adjust meta description length into a readable band (heuristic ~50–160 characters). Not a Google ranking requirement. Success signal: description reads cleanly in SERP previews. Watch: CTR.',
            self::RULE_CHARSET_MISSING => 'Declare character encoding early in the document head (e.g. <meta charset="utf-8">). Success signal: charset present within the first 1024 bytes. Failure signal: encoding remains undeclared after deploy.',
            self::RULE_VIEWPORT_MISSING => 'Add <meta name="viewport" content="width=device-width, initial-scale=1"> for responsive rendering. Success signal: viewport meta present. Watch: mobile usability / Core Web Vitals after deploy.',
            self::RULE_ROBOTS_NOINDEX => 'Confirm whether noindex is intentional for this URL. If the page should appear in Google Search, remove noindex from meta robots/googlebot (and conflicting X-Robots-Tag headers). If intentional, keep and document. Success signal: intended indexability matches directive. Watch: Search Console page indexing status.',
            self::RULE_JSONLD_MALFORMED => 'Fix malformed application/ld+json so it parses as valid JSON. Parsing success is not Google rich-result eligibility. Success signal: JSON-LD blocks parse without errors. Watch: Rich Results / structured data reports after Google recrawl.',
            self::RULE_OG_INCOMPLETE => 'Add og:title, og:description, and og:image for higher-quality social previews. Advisory social metadata — not a critical SEO defect. Success signal: core Open Graph tags present. Watch: share previews on target networks.',
            default => 'Review the Document Head finding and republish a corrected HTML head.',
        };
    }

    public static function categoryLabel(string $ruleId): string
    {
        return match ($ruleId) {
            self::RULE_ROBOTS_NOINDEX => 'Indexability',
            self::RULE_JSONLD_MALFORMED => 'Structured Data',
            self::RULE_OG_INCOMPLETE => 'Social metadata',
            default => 'Document Head',
        };
    }

    public static function findingCategory(string $ruleId): string
    {
        return match ($ruleId) {
            self::RULE_ROBOTS_NOINDEX => 'indexability',
            self::RULE_JSONLD_MALFORMED => 'structured-data',
            self::RULE_OG_INCOMPLETE => 'social',
            default => 'document-head',
        };
    }
}
