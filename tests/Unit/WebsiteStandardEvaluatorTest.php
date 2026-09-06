<?php

namespace Tests\Unit;

use MoxDop\Website\Standards\StandardEvidenceGuard;
use MoxDop\Website\Standards\WebsiteStandardCatalog;
use MoxDop\Website\Standards\WebsiteStandardEvaluator;
use PHPUnit\Framework\TestCase;

final class WebsiteStandardEvaluatorTest extends TestCase
{
    private function check(string $id, array $page): array
    {
        return (new WebsiteStandardEvaluator)->evaluate((new WebsiteStandardCatalog)->definitions()[$id], $page + ['url' => 'https://example.test/service']);
    }

    public function test_missing_observation_is_not_a_failure_or_zero(): void
    {
        foreach (['reachability-http', 'website:head:title-missing', 'website:head:meta-robots-noindex', 'website:structure:internal-links'] as $id) {
            $this->assertSame('unknown', $this->check($id, [])['state']);
        }
    }

    public function test_noindex_needs_a_declared_target_before_becoming_a_defect(): void
    {
        $page = ['facts' => ['document_head' => ['robots' => 'noindex, follow']]];
        $this->assertSame('review', $this->check('website:head:meta-robots-noindex', $page)['state']);
        $this->assertSame('fail', $this->check('website:head:meta-robots-noindex', $page + ['search_target' => true])['state']);
        $this->assertSame('not_applicable', $this->check('website:head:meta-robots-noindex', $page + ['excluded_kind' => true])['state']);
    }

    public function test_none_directive_and_token_boundaries(): void
    {
        $this->assertSame('fail', $this->check('website:head:meta-robots-noindex', ['facts' => ['document_head' => ['robots' => 'none']], 'search_target' => true])['state']);
        $this->assertSame('pass', $this->check('website:head:meta-robots-noindex', ['facts' => ['document_head' => ['robots' => 'x-noindex-example']]])['state']);
    }

    public function test_optional_canonical_and_length_heuristics_are_not_hard_errors(): void
    {
        $this->assertSame('not_applicable', $this->check('canonical-link-consistency', ['facts' => ['document_head' => ['canonical_hrefs' => []]]])['state']);
        $this->assertSame('review', $this->check('website:head:title-length-heuristic', ['facts' => ['document_head' => ['title_present' => true, 'title' => str_repeat('a', 65)]]])['state']);
    }

    public function test_missing_and_empty_title_are_distinct(): void
    {
        $missing = ['facts' => ['document_head' => ['title_present' => false, 'title' => null]]];
        $empty = ['facts' => ['document_head' => ['title_present' => true, 'title' => '']]];
        $this->assertSame('fail', $this->check('website:head:title-missing', $missing)['state']);
        $this->assertSame('not_applicable', $this->check('website:head:title-empty', $missing)['state']);
        $this->assertSame('pass', $this->check('website:head:title-missing', $empty)['state']);
        $this->assertSame('fail', $this->check('website:head:title-empty', $empty)['state']);
    }

    public function test_relative_canonical_is_resolved_and_conflicting_canonicals_are_not_accepted(): void
    {
        $this->assertSame('pass', $this->check('canonical-link-consistency', ['facts' => ['document_head' => ['canonical_hrefs' => ['/service']]]])['state']);
        $this->assertSame('fail', $this->check('canonical-link-consistency', ['facts' => ['document_head' => ['canonical_hrefs' => ['/service', '/other']]], 'search_target' => true])['state']);
    }

    public function test_old_observations_and_default_link_counts_are_unknown(): void
    {
        $this->assertSame('unknown', $this->check('reachability-http', [
            'facts' => ['http' => ['status_code' => 503, 'observed_at' => '2026-07-01T00:00:00Z']],
            'evaluated_at' => '2026-09-06T00:00:00Z',
        ])['state']);
        $this->assertSame('unknown', $this->check('website:structure:internal-links', ['facts' => ['links' => ['internal' => 0]]])['state']);
        $this->assertSame('unknown', $this->check('reachability-http', ['facts' => ['http' => ['status_code' => 100]]])['state']);
    }

    public function test_competitor_evidence_rejects_invented_standards_and_excerpts(): void
    {
        $guard = new StandardEvidenceGuard;
        $standards = [['id' => 'website:review:questions', 'version' => 1]];
        $brand = ['normalized_text_excerpt' => 'Bakım süreci ve kontrol adımları burada anlatılır.'];
        $competitor = ['normalized_text_excerpt' => 'Bakım ücretine dahil işlemler açıkça açıklanır.'];
        $row = ['standard_id' => 'website:review:questions', 'brand_state' => 'partial', 'competitor_state' => 'pass',
            'brand_evidence' => 'Bakım süreci ve kontrol adımları', 'competitor_evidence' => 'Bakım ücretine dahil işlemler', 'rationale' => 'Kapsam bilgisi karşılaştırılıyor.'];
        $this->assertCount(1, $guard->competitiveAssessments([$row], $standards, $brand, $competitor));
        $this->assertSame([], $guard->competitiveAssessments([array_replace($row, ['standard_id' => 'invented'])], $standards, $brand, $competitor));
        $this->assertSame([], $guard->competitiveAssessments([array_replace($row, ['brand_evidence' => 'Sayfada olmayan bir açıklama'])], $standards, $brand, $competitor));
    }
}
