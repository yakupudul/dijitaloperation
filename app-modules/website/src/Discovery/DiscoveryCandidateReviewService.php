<?php

namespace MoxDop\Website\Discovery;

use App\Models\Brand;
use App\Models\BrandIntelligenceContext;
use App\Models\DigitalAsset;
use App\Models\DiscoveryCandidate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Human review actions for Discovery candidates → Brand Context / asset fields.
 */
final class DiscoveryCandidateReviewService
{
    public const string SOURCE_PUBLIC_DISCOVERY = 'public_discovery';

    public const string SOURCE_PUBLIC_DISCOVERY_EDITED = 'public_discovery_edited';

    public function accept(DiscoveryCandidate $candidate, User $actor, ?string $editedValue = null): DiscoveryCandidate
    {
        if ($candidate->status === DiscoveryCandidate::STATUS_ACCEPTED) {
            return $candidate;
        }

        $value = $editedValue !== null ? trim($editedValue) : (string) $candidate->proposed_value;
        if ($value === '') {
            throw new InvalidArgumentException('Accepted value cannot be empty.');
        }

        $edited = $editedValue !== null && trim($editedValue) !== (string) $candidate->proposed_value;

        return DB::transaction(function () use ($candidate, $actor, $value, $edited): DiscoveryCandidate {
            $this->applyToCanonical($candidate, $value, $edited, $actor);

            $candidate->forceFill([
                'status' => DiscoveryCandidate::STATUS_ACCEPTED,
                'reviewed_by_id' => $actor->id,
                'reviewed_at' => now(),
                'accepted_value' => $value,
                'was_edited' => $edited,
            ])->save();

            return $candidate->refresh();
        });
    }

    public function ignore(DiscoveryCandidate $candidate, User $actor): DiscoveryCandidate
    {
        $candidate->forceFill([
            'status' => DiscoveryCandidate::STATUS_IGNORED,
            'reviewed_by_id' => $actor->id,
            'reviewed_at' => now(),
        ])->save();

        return $candidate->refresh();
    }

    private function applyToCanonical(DiscoveryCandidate $candidate, string $value, bool $edited, User $actor): void
    {
        $field = $candidate->target_field;

        if ($field === 'languages') {
            $asset = DigitalAsset::query()->findOrFail($candidate->digital_asset_id);
            $languages = is_array($asset->languages) ? $asset->languages : [];
            if (! in_array($value, $languages, true)) {
                $languages[] = $value;
                $asset->forceFill(['languages' => array_values($languages)])->save();
            }

            return;
        }

        if ($field === 'social_links') {
            // No Brand Context social schema in V1 — acceptance records operator decision only.
            return;
        }

        $brand = Brand::query()->findOrFail($candidate->brand_id);
        $context = $brand->intelligenceContext()->firstOrNew(['brand_id' => $brand->id]);

        match ($field) {
            'business_summary' => $this->setScalarIfEmptyOrAppendConflict($context, 'business_summary', $value, $candidate),
            'positioning' => $this->setScalarIfEmptyOrAppendConflict($context, 'positioning', $value, $candidate),
            'products_services' => $this->appendNamed($context, 'products_services', $value),
            'differentiators' => $this->appendStringList($context, 'differentiators', $value),
            'target_audiences' => $this->appendNamed($context, 'target_audiences', $value, noteKey: 'note'),
            'target_markets' => $this->appendNamed($context, 'target_markets', $value, noteKey: 'note'),
            'known_competitors' => $this->appendCompetitor($context, $value, $candidate),
            default => null,
        };

        $context->source = $edited
            ? BrandIntelligenceContext::SOURCE_PUBLIC_DISCOVERY_EDITED
            : BrandIntelligenceContext::SOURCE_PUBLIC_DISCOVERY;
        $context->updated_by = $actor->id;
        $context->save();
    }

    private function setScalarIfEmptyOrAppendConflict(
        BrandIntelligenceContext $context,
        string $attribute,
        string $value,
        DiscoveryCandidate $candidate,
    ): void {
        $current = is_string($context->{$attribute} ?? null) ? trim((string) $context->{$attribute}) : '';
        if ($current === '') {
            $context->{$attribute} = $value;

            return;
        }

        if ($this->normalize($current) === $this->normalize($value)) {
            return;
        }

        // Human override wins: do not overwrite. Candidate remains accepted as reviewed proposal.
        $support = is_array($candidate->support_json) ? $candidate->support_json : [];
        $support['conflict_with_existing'] = $current;
        $candidate->support_json = $support;
    }

    private function appendNamed(BrandIntelligenceContext $context, string $attribute, string $value, string $noteKey = 'description'): void
    {
        $rows = is_array($context->{$attribute}) ? $context->{$attribute} : [];
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['name']) && $this->normalize((string) $row['name']) === $this->normalize($value)) {
                return;
            }
        }
        $rows[] = ['name' => $value, $noteKey === 'description' ? 'description' : 'note' => 'From public discovery'];
        $context->{$attribute} = $rows;
    }

    private function appendStringList(BrandIntelligenceContext $context, string $attribute, string $value): void
    {
        $rows = is_array($context->{$attribute}) ? $context->{$attribute} : [];
        foreach ($rows as $row) {
            $existing = is_string($row) ? $row : (is_array($row) ? (string) ($row['name'] ?? '') : '');
            if ($this->normalize($existing) === $this->normalize($value)) {
                return;
            }
        }
        $rows[] = $value;
        $context->{$attribute} = $rows;
    }

    private function appendCompetitor(BrandIntelligenceContext $context, string $value, DiscoveryCandidate $candidate): void
    {
        $rows = is_array($context->known_competitors) ? $context->known_competitors : [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = isset($row['name']) ? (string) $row['name'] : '';
            $url = isset($row['url']) ? (string) $row['url'] : '';
            if ($this->normalize($name) === $this->normalize($value) || $this->normalize($url) === $this->normalize($value)) {
                return;
            }
        }

        $support = is_array($candidate->support_json) ? $candidate->support_json : [];
        $domain = isset($support['domain']) && is_string($support['domain']) ? $support['domain'] : $value;
        $rows[] = [
            'name' => $domain,
            'url' => str_contains($domain, '.') ? 'https://'.$domain : null,
            'note' => 'Accepted from public discovery competitor candidate',
        ];
        $context->known_competitors = $rows;
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));
    }
}
