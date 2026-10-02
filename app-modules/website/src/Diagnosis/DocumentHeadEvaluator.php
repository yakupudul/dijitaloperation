<?php

namespace MoxDop\Website\Diagnosis;

use App\Models\DigitalAsset;
use App\Models\Run;
use App\Support\Findings\RuleEvaluationResult;
use App\Support\Findings\RuleMatch;
use DateTimeInterface;

/**
 * Deterministic Document Head rules over normalized page_html Evidence.
 * Uses FindingLifecycleService-compatible RuleEvaluationResult.
 */
final class DocumentHeadEvaluator
{
    /**
     * @param  array<string, mixed>|null  $pageHtmlPayload
     * @param  list<string>|null  $collectedDimensions  Null = full catalog (legacy diagnosis). Named dimensions only when those snapshot fields were actually collected.
     */
    public function evaluate(
        DigitalAsset $asset,
        Run $run,
        ?array $pageHtmlPayload,
        DateTimeInterface $observedAt,
        ?array $collectedDimensions = null,
    ): RuleEvaluationResult {
        if ($pageHtmlPayload === null) {
            // Missing Evidence must never resolve Document Head findings.
            return new RuleEvaluationResult(
                asset: $asset,
                sourceModule: 'website-diagnosis',
                run: $run,
                evaluationSuccessful: false,
                evaluatedRuleIds: [],
                matches: [],
                observedAt: $observedAt,
            );
        }

        $evaluateTitle = $this->dimensionCollected('title', $collectedDimensions);
        $evaluateDescription = $this->dimensionCollected('meta_description', $collectedDimensions);
        $evaluateCharset = $this->dimensionCollected('charset', $collectedDimensions);
        $evaluateViewport = $this->dimensionCollected('viewport', $collectedDimensions);
        $evaluateRobots = $this->dimensionCollected('robots', $collectedDimensions);
        $evaluateJsonLd = $this->dimensionCollected('jsonld', $collectedDimensions);
        $evaluateOg = $this->dimensionCollected('open_graph', $collectedDimensions);

        $document = is_array($pageHtmlPayload['document'] ?? null) ? $pageHtmlPayload['document'] : [];
        $meta = is_array($pageHtmlPayload['meta'] ?? null) ? $pageHtmlPayload['meta'] : [];
        $og = is_array($pageHtmlPayload['open_graph'] ?? null) ? $pageHtmlPayload['open_graph'] : [];
        $jsonLd = is_array($pageHtmlPayload['structured_data'] ?? null) ? $pageHtmlPayload['structured_data'] : [];

        $matches = [];
        $evaluatedRuleIds = [];

        if ($evaluateTitle) {
            $evaluatedRuleIds = array_merge($evaluatedRuleIds, [
                DocumentHeadCatalog::RULE_TITLE_MISSING,
                DocumentHeadCatalog::RULE_TITLE_EMPTY,
                DocumentHeadCatalog::RULE_TITLE_LENGTH,
            ]);
        }

        if ($evaluateTitle) {
            if (! (bool) ($document['title_present'] ?? false)) {
                $matches[] = $this->match(
                    DocumentHeadCatalog::RULE_TITLE_MISSING,
                    'high',
                    'Title missing',
                    'The primary HTML document has no <title> element.',
                );
            } elseif ((bool) ($document['title_empty'] ?? false)) {
                $matches[] = $this->match(
                    DocumentHeadCatalog::RULE_TITLE_EMPTY,
                    'high',
                    'Title empty',
                    'The primary HTML document has an empty <title> element.',
                );
            } elseif (is_numeric($document['title_length'] ?? null)
                && (int) $document['title_length'] > DocumentHeadCatalog::TITLE_LENGTH_MAX_HEURISTIC) {
                $matches[] = $this->match(
                    DocumentHeadCatalog::RULE_TITLE_LENGTH,
                    'low',
                    'Title longer than heuristic band',
                    'Title length is '.(int) $document['title_length'].' characters (heuristic display band ~'.DocumentHeadCatalog::TITLE_LENGTH_MAX_HEURISTIC.'). This is not a Google ranking rule.',
                );
            }
        }

        $descriptionPresent = (bool) ($meta['description_present'] ?? false);
        $descriptionEmpty = (bool) ($meta['description_empty'] ?? false);
        $descriptionLength = $meta['description_length'] ?? null;

        if ($evaluateDescription) {
            $evaluatedRuleIds = array_merge($evaluatedRuleIds, [
                DocumentHeadCatalog::RULE_META_DESCRIPTION_MISSING,
                DocumentHeadCatalog::RULE_META_DESCRIPTION_EMPTY,
                DocumentHeadCatalog::RULE_META_DESCRIPTION_LENGTH,
            ]);
        }

        if ($evaluateDescription) {
            if (! $descriptionPresent) {
                $matches[] = $this->match(
                    DocumentHeadCatalog::RULE_META_DESCRIPTION_MISSING,
                    'low',
                    'Meta description missing',
                    'No meta description tag was found. Google does not require one for ranking and may generate a snippet automatically.',
                );
            } elseif ($descriptionEmpty) {
                $matches[] = $this->match(
                    DocumentHeadCatalog::RULE_META_DESCRIPTION_EMPTY,
                    'medium',
                    'Meta description empty',
                    'A meta description tag is present but its content is empty.',
                );
            } elseif (is_numeric($descriptionLength)) {
                $len = (int) $descriptionLength;
                if ($len < DocumentHeadCatalog::META_DESCRIPTION_MIN_HEURISTIC
                    || $len > DocumentHeadCatalog::META_DESCRIPTION_MAX_HEURISTIC) {
                    $matches[] = $this->match(
                        DocumentHeadCatalog::RULE_META_DESCRIPTION_LENGTH,
                        'low',
                        'Meta description outside heuristic length band',
                        'Meta description length is '.$len.' characters (heuristic band '.DocumentHeadCatalog::META_DESCRIPTION_MIN_HEURISTIC.'–'.DocumentHeadCatalog::META_DESCRIPTION_MAX_HEURISTIC.'). Not a Google ranking requirement.',
                    );
                }
            }
        }

        if ($evaluateCharset) {
            $evaluatedRuleIds[] = DocumentHeadCatalog::RULE_CHARSET_MISSING;
        }

        if ($evaluateCharset && ! (bool) ($document['charset_present'] ?? false)) {
            $matches[] = $this->match(
                DocumentHeadCatalog::RULE_CHARSET_MISSING,
                'medium',
                'Character encoding not declared',
                'No charset declaration was found in the document head (meta charset or Content-Type http-equiv).',
            );
        }

        if ($evaluateViewport) {
            $evaluatedRuleIds[] = DocumentHeadCatalog::RULE_VIEWPORT_MISSING;
        }

        if ($evaluateViewport && ! (bool) ($document['viewport_present'] ?? false)) {
            $matches[] = $this->match(
                DocumentHeadCatalog::RULE_VIEWPORT_MISSING,
                'medium',
                'Viewport meta missing',
                'No viewport meta tag was found. Responsive sites typically declare width=device-width.',
            );
        }

        $robotsDirectives = is_array($meta['robots_directives'] ?? null) ? $meta['robots_directives'] : [];
        $googlebotDirectives = is_array($meta['googlebot_directives'] ?? null) ? $meta['googlebot_directives'] : [];
        $combined = array_values(array_unique([...$robotsDirectives, ...$googlebotDirectives]));

        if ($evaluateRobots) {
            $evaluatedRuleIds[] = DocumentHeadCatalog::RULE_ROBOTS_NOINDEX;
        }

        if ($evaluateRobots && in_array('noindex', $combined, true)) {
            $matches[] = $this->match(
                DocumentHeadCatalog::RULE_ROBOTS_NOINDEX,
                'medium',
                'Page declares noindex',
                'Meta robots/googlebot includes noindex. This may be intentional. If this URL should appear in Google Search, remove noindex; otherwise keep and document the intent.',
            );
        }

        $malformed = (int) ($jsonLd['malformed_count'] ?? 0);
        if ($evaluateJsonLd) {
            $evaluatedRuleIds[] = DocumentHeadCatalog::RULE_JSONLD_MALFORMED;
        }
        if ($evaluateJsonLd && $malformed > 0) {
            $matches[] = $this->match(
                DocumentHeadCatalog::RULE_JSONLD_MALFORMED,
                'medium',
                'Malformed JSON-LD block',
                $malformed.' application/ld+json block(s) failed JSON parsing. Successful parse is not Google rich-result eligibility.',
            );
        }

        $ogTitle = is_string($og['title'] ?? null) ? $og['title'] : null;
        $ogDescription = is_string($og['description'] ?? null) ? $og['description'] : null;
        $ogImage = is_string($og['image'] ?? null) ? $og['image'] : null;
        $ogCoreMissing = $ogTitle === null || $ogDescription === null || $ogImage === null;
        if ($evaluateOg) {
            $evaluatedRuleIds[] = DocumentHeadCatalog::RULE_OG_INCOMPLETE;
        }
        if ($evaluateOg && $ogCoreMissing) {
            $missing = array_keys(array_filter([
                'og:title' => $ogTitle === null,
                'og:description' => $ogDescription === null,
                'og:image' => $ogImage === null,
            ]));
            $matches[] = $this->match(
                DocumentHeadCatalog::RULE_OG_INCOMPLETE,
                'low',
                'Open Graph metadata incomplete',
                'Core Open Graph tags incomplete (missing: '.implode(', ', $missing).'). Advisory for social sharing quality — not a critical SEO defect.',
            );
        }

        return new RuleEvaluationResult(
            asset: $asset,
            sourceModule: 'website-diagnosis',
            run: $run,
            evaluationSuccessful: $evaluatedRuleIds !== [],
            evaluatedRuleIds: $evaluatedRuleIds,
            matches: $matches,
            observedAt: $observedAt,
        );
    }

    /**
     * @param  list<string>|null  $collectedDimensions
     */
    private function dimensionCollected(string $dimension, ?array $collectedDimensions): bool
    {
        if ($collectedDimensions === null) {
            return true;
        }

        return in_array($dimension, $collectedDimensions, true);
    }

    private function match(
        string $ruleId,
        string $severity,
        string $title,
        string $summary,
    ): RuleMatch {
        return new RuleMatch(
            ruleId: $ruleId,
            fingerprint: $ruleId,
            category: DocumentHeadCatalog::findingCategory($ruleId),
            severity: $severity,
            title: $title,
            summary: $summary,
            confidence: 0.9,
            recommendationTitle: 'Fix: '.$title,
            recommendationAction: DocumentHeadCatalog::recommendationAction($ruleId),
        );
    }
}
