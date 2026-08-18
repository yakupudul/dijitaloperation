<?php

namespace App\Support\ReportSnapshots;

use App\Enums\ReportSnapshotSchemaVersion;
use App\Enums\ReportType;
use App\Models\Brand;
use App\Models\User;
use App\Support\ClientValueStory\Dto\ClientValueStory;
use App\Support\ClientValueStory\Dto\ClientValueStorySourceManifest;
use Illuminate\Validation\ValidationException;

/**
 * CLIENT_VALUE_STORY_V1 serializer — freezes Prompt 58 Story for historical display.
 */
final class ClientValueStorySnapshotSerializer
{
    public const string SCHEMA = 'client_value_story_v1';

    /**
     * @return array{
     *     content: array<string, mixed>,
     *     source_manifest: array<string, mixed>,
     *     source_manifest_fingerprint: string,
     *     content_checksum: string,
     *     title: string,
     *     header: array<string, mixed>
     * }
     */
    public function serialize(
        ClientValueStory $story,
        Brand $brand,
        User $actor,
        string $locale,
        string $reportingTimezone,
        ?string $customTitle = null,
        ?string $comparisonPeriodStart = null,
        ?string $comparisonPeriodEnd = null,
    ): array {
        if (! $story->sourceManifest instanceof ClientValueStorySourceManifest) {
            throw ValidationException::withMessages(['source_manifest' => 'MISSING_SOURCE_MANIFEST']);
        }

        $locale = in_array($locale, ['en', 'tr'], true) ? $locale : 'en';
        $title = $this->resolveTitle($story, $locale, $customTitle);
        $customerName = (string) ($brand->customer?->name ?? 'Customer');
        $brandName = (string) $brand->name;

        $header = [
            'customer_id' => (int) $story->customerId,
            'brand_id' => (int) $story->brandId,
            'customer_name' => $customerName,
            'brand_name' => $brandName,
            'report_type' => ReportType::ClientValueStory->value,
            'report_type_label' => ReportType::ClientValueStory->displayLabel($locale),
            'title' => $title,
            'period_start' => $story->periodStart,
            'period_end' => $story->periodEnd,
            'period_label' => $story->periodLabel,
            'comparison_period_start' => $comparisonPeriodStart,
            'comparison_period_end' => $comparisonPeriodEnd,
            'locale' => $locale,
            'reporting_timezone' => $reportingTimezone,
            'generated_by_display' => (string) ($actor->name ?? $actor->email ?? 'operator'),
        ];

        $presentation = $story->toPresentationArray();
        // Strip live URLs that would re-resolve current truth; keep frozen display fields.
        unset($presentation['source_manifest']);
        $presentation['locale'] = $locale;
        $presentation['brand_name'] = $brandName;
        $presentation['customer_name'] = $customerName;

        $content = [
            'schema_version' => ReportSnapshotSchemaVersion::ClientValueStoryV1->value,
            'report_type' => ReportType::ClientValueStory->value,
            'header' => $header,
            'story' => $presentation,
            'findings' => array_map(static fn ($f) => $f->toArray(), $story->findings),
            'opportunities' => array_map(static fn ($o) => $o->toArray(), $story->opportunities),
            'completed_work' => array_map(static fn ($w) => $w->toArray(), $story->completedWork),
            'active_work' => array_map(static fn ($w) => $w->toArray(), $story->activeWork),
            'business_outcomes' => array_map(static fn ($o) => $o->toArray(), $story->outcomes),
            'limitations' => array_map(static fn ($l) => $l->value, $story->limitations),
            'claims' => array_map(static fn ($c) => $c->toArray(), $story->claims),
            'status' => $story->status->value,
            'attribution_established' => false,
            'causality_established' => false,
            'ai_assisted' => false,
            'section_labels' => [
                'observed' => 'WHAT WE OBSERVED',
                'potential' => 'WHERE WE SAW POTENTIAL',
                'work' => 'WHAT WE DID',
                'outcomes' => 'WHAT THE BUSINESS REPORTED',
                'limitations' => 'LIMITATIONS',
            ],
            'comparison' => ($comparisonPeriodStart !== null && $comparisonPeriodEnd !== null)
                ? [
                    'period_start' => $comparisonPeriodStart,
                    'period_end' => $comparisonPeriodEnd,
                    'formula_version_id' => null,
                    'result' => null,
                    'supported' => false,
                ]
                : null,
        ];

        $this->assertNoUnsafeContent($content);

        $manifest = $story->sourceManifest->toArray();
        $fingerprint = $story->sourceManifest->fingerprint();
        $checksum = ReportSnapshotChecksum::hash($content);

        return [
            'content' => $content,
            'source_manifest' => $manifest,
            'source_manifest_fingerprint' => $fingerprint,
            'content_checksum' => $checksum,
            'title' => $title,
            'header' => $header,
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     */
    public function validate(array $content): void
    {
        $version = (string) ($content['schema_version'] ?? '');
        if ($version !== ReportSnapshotSchemaVersion::ClientValueStoryV1->value) {
            throw ValidationException::withMessages([
                'snapshot_schema_version' => 'UNSUPPORTED_SNAPSHOT_SCHEMA',
            ]);
        }
        if (($content['report_type'] ?? '') !== ReportType::ClientValueStory->value) {
            throw ValidationException::withMessages(['report_type' => 'INVALID_REPORT_TYPE']);
        }
        if (! isset($content['header'], $content['story'], $content['business_outcomes'])) {
            throw ValidationException::withMessages(['content_payload' => 'INVALID_SNAPSHOT_PAYLOAD']);
        }
        if (($content['attribution_established'] ?? true) !== false
            || ($content['causality_established'] ?? true) !== false) {
            throw ValidationException::withMessages(['content_payload' => 'ATTRIBUTION_FORBIDDEN']);
        }
        $this->assertNoUnsafeContent($content);
    }

    private function resolveTitle(ClientValueStory $story, string $locale, ?string $customTitle): string
    {
        if ($customTitle !== null) {
            $title = trim(strip_tags($customTitle));
            if ($title === '' || mb_strlen($title) > 200) {
                throw ValidationException::withMessages(['title' => 'INVALID_REPORT_TITLE']);
            }

            return $title;
        }

        $prefix = $locale === 'tr' ? 'Müşteri Değer Hikayesi' : 'Client Value Story';

        return $prefix.' — '.$story->periodStart.' → '.$story->periodEnd;
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function assertNoUnsafeContent(array $content): void
    {
        $json = CanonicalJson::encode($content);
        foreach (['<?php', '<script', 'javascript:', 'eval(', 'unserialize('] as $needle) {
            if (stripos($json, $needle) !== false) {
                throw ValidationException::withMessages(['content_payload' => 'EXECUTABLE_CONTENT_FORBIDDEN']);
            }
        }
    }
}
