<?php

namespace MoxDop\Website\Standards;

final class StandardEvidenceGuard
{
    /** @param array<string, mixed> $page */
    public function containsExcerpt(array $page, mixed $excerpt): bool
    {
        if (! is_string($excerpt) || mb_strlen(trim($excerpt)) < 8 || mb_strlen($excerpt) > 600) {
            return false;
        }
        $text = implode(' ', array_filter([
            $page['title'] ?? null, $page['meta_description'] ?? null, $page['h1'] ?? null,
            $page['normalized_text_excerpt'] ?? null,
        ], 'is_string'));

        return str_contains($this->normalize($text), $this->normalize($excerpt));
    }

    /**
     * @param  list<array<string, mixed>>  $assessments
     * @param  list<array<string, mixed>>  $standards
     * @param  array<string, mixed>  $brand
     * @param  array<string, mixed>  $competitor
     * @return list<array<string, mixed>>
     */
    public function competitiveAssessments(array $assessments, array $standards, array $brand, array $competitor): array
    {
        $allowed = array_column($standards, null, 'id');
        $accepted = [];
        $states = ['pass', 'gap', 'partial', 'unknown', 'not_applicable'];
        foreach (array_slice($assessments, 0, 50) as $row) {
            if (! is_array($row) || ! isset($allowed[$row['standard_id'] ?? ''])
                || ! in_array($row['brand_state'] ?? '', $states, true)
                || ! in_array($row['competitor_state'] ?? '', $states, true)
                || ! $this->containsExcerpt($brand, $row['brand_evidence'] ?? null)
                || ! $this->containsExcerpt($competitor, $row['competitor_evidence'] ?? null)
                || ! is_string($row['rationale'] ?? null) || trim($row['rationale']) === '') {
                continue;
            }
            $accepted[$row['standard_id']] = [
                'standard_id' => $row['standard_id'], 'standard_version' => $allowed[$row['standard_id']]['version'],
                'brand_state' => $row['brand_state'], 'competitor_state' => $row['competitor_state'],
                'brand_evidence' => $row['brand_evidence'], 'competitor_evidence' => $row['competitor_evidence'],
                'rationale' => mb_substr($row['rationale'], 0, 2000),
            ];
        }

        return array_values($accepted);
    }

    private function normalize(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}
