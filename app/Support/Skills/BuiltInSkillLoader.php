<?php

namespace App\Support\Skills;

use InvalidArgumentException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Safe built-in Skill Markdown loader.
 *
 * Skills are DATA / methodology — never executable code.
 */
final class BuiltInSkillLoader
{
    public const int MAX_FILE_BYTES = 65536;

    /**
     * @param  array{module: string, absolute_root: string}  $root
     * @return list<SkillDefinition>
     */
    public function loadFromRoot(array $root): array
    {
        $module = $root['module'];
        $absoluteRoot = $root['absolute_root'];
        $realRoot = realpath($absoluteRoot);

        if ($realRoot === false || ! is_dir($realRoot)) {
            throw new InvalidArgumentException("Skill root is not a directory [{$absoluteRoot}].");
        }

        $pattern = $realRoot.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'SKILL.md';
        $files = glob($pattern) ?: [];
        sort($files);

        $skills = [];

        foreach ($files as $file) {
            $realFile = realpath($file);
            if ($realFile === false || ! is_file($realFile)) {
                continue;
            }

            if (! str_starts_with($realFile, $realRoot.DIRECTORY_SEPARATOR)) {
                throw new InvalidArgumentException('Skill path traversal rejected.');
            }

            $skills[] = $this->loadFile($module, $realRoot, $realFile);
        }

        return $skills;
    }

    public function loadFile(string $module, string $realRoot, string $realFile): SkillDefinition
    {
        $size = filesize($realFile);
        if ($size === false || $size > self::MAX_FILE_BYTES) {
            throw new InvalidArgumentException("Skill file exceeds size limit [{$realFile}].");
        }

        $raw = file_get_contents($realFile);
        if ($raw === false) {
            throw new InvalidArgumentException("Unable to read Skill file [{$realFile}].");
        }

        if (! mb_check_encoding($raw, 'UTF-8')) {
            throw new InvalidArgumentException("Skill file must be UTF-8 [{$realFile}].");
        }

        // Reject obvious executable / include payloads.
        if (preg_match('/<\?php|eval\s*\(|shell_exec\s*\(|passthru\s*\(|proc_open\s*\(/i', $raw) === 1) {
            throw new InvalidArgumentException("Skill file must not contain executable code [{$realFile}].");
        }

        [$frontMatter, $body] = $this->splitFrontMatter($raw);
        $meta = $this->parseFrontMatter($frontMatter, $realFile);

        $slug = (string) ($meta['slug'] ?? '');
        $version = (string) ($meta['version'] ?? '');
        $name = (string) ($meta['name'] ?? '');
        $declaredModule = (string) ($meta['module'] ?? $module);
        $purpose = (string) ($meta['purpose'] ?? '');
        $definitionStatus = strtolower(trim((string) ($meta['definition_status'] ?? SkillDefinition::STATUS_ACTIVE)));

        if ($slug === '' || $version === '' || $name === '' || $purpose === '') {
            throw new InvalidArgumentException("Skill front matter missing required fields [{$realFile}].");
        }

        if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            throw new InvalidArgumentException("Skill slug is invalid [{$realFile}].");
        }

        if (! preg_match('/^\d+\.\d+\.\d+$/', $version)) {
            throw new InvalidArgumentException("Skill version must be semver [{$realFile}].");
        }

        if ($declaredModule !== $module) {
            throw new InvalidArgumentException("Skill module mismatch [{$realFile}].");
        }

        $allowedStatuses = [
            SkillDefinition::STATUS_ACTIVE,
            SkillDefinition::STATUS_DRAFT,
            SkillDefinition::STATUS_EXPERIMENTAL,
            SkillDefinition::STATUS_NEEDS_REVIEW,
            SkillDefinition::STATUS_DEPRECATED,
        ];
        if (! in_array($definitionStatus, $allowedStatuses, true)) {
            throw new InvalidArgumentException("Skill definition_status is invalid [{$realFile}].");
        }

        $sections = $this->parseSections($body);
        $relative = ltrim(str_replace('\\', '/', substr($realFile, strlen($realRoot))), '/');

        $requiredEvidenceRequirements = $this->parseEvidenceRequirements(
            $meta['required_evidence'] ?? [],
            optional: false
        );
        $optionalEvidenceRequirements = $this->parseEvidenceRequirements(
            $meta['optional_evidence'] ?? [],
            optional: true
        );

        return new SkillDefinition(
            name: $name,
            slug: $slug,
            version: $version,
            module: $module,
            purpose: $purpose,
            definitionStatus: $definitionStatus,
            whenToUse: $this->sectionText($sections, ['when to use', 'when_to_use']),
            doNotUseWhen: $this->sectionText($sections, ['do not use when', 'do_not_use_when', 'not for']),
            requiredContext: $this->stringList($meta['required_context'] ?? []),
            requiredEvidence: array_map(
                static fn (SkillEvidenceRequirement $r): string => $r->key,
                $requiredEvidenceRequirements
            ),
            optionalEvidence: array_map(
                static fn (SkillEvidenceRequirement $r): string => $r->key,
                $optionalEvidenceRequirements
            ),
            requiredEvidenceRequirements: $requiredEvidenceRequirements,
            optionalEvidenceRequirements: $optionalEvidenceRequirements,
            requiredCapabilities: $this->stringList($meta['required_capabilities'] ?? []),
            optionalCapabilities: $this->stringList($meta['optional_capabilities'] ?? []),
            methodology: $this->sectionText($sections, ['methodology', 'process', 'workflow']),
            methodologySteps: $this->parseMethodologySteps($meta['methodology_steps'] ?? []),
            rules: $this->sectionText($sections, ['rules', 'heuristics', 'critical rules']),
            allowedConclusions: $this->stringList($meta['allowed_conclusions'] ?? $this->sectionList($sections, ['allowed conclusions', 'allowed_conclusions'])),
            forbiddenClaims: $this->stringList($meta['forbidden_claims'] ?? $this->sectionList($sections, ['forbidden claims', 'forbidden_claims'])),
            abstentionRules: $this->stringList($meta['abstention_rules'] ?? $this->sectionList($sections, ['abstention', 'abstention rules', 'abstention_rules'])),
            dependencies: $this->stringList($meta['dependencies'] ?? $this->sectionList($sections, ['dependencies'])),
            outputContract: $this->sectionText($sections, ['output contract', 'output_contract', 'deliverables']),
            downstreamDomains: $this->stringList($meta['downstream_domains'] ?? $this->sectionList($sections, ['downstream domains', 'downstream_domains'])),
            successSignals: $this->stringList($meta['success_signals'] ?? $this->sectionList($sections, ['success signals', 'success_signals', 'success criteria'])),
            failureSignals: $this->stringList($meta['failure_signals'] ?? $this->sectionList($sections, ['failure signals', 'failure_signals'])),
            watchMetrics: $this->stringList($meta['watch_metrics'] ?? $this->sectionList($sections, ['watch metrics', 'watch_metrics'])),
            referenceSources: $this->stringList($meta['reference_sources'] ?? $this->sectionList($sections, ['reference sources', 'reference_sources', 'references'])),
            researchProvenance: $this->stringList($meta['research_provenance'] ?? $this->sectionList($sections, ['research provenance', 'research_provenance'])),
            bodyMarkdown: trim($body),
            relativePath: $relative,
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitFrontMatter(string $raw): array
    {
        if (! str_starts_with(ltrim($raw), '---')) {
            throw new InvalidArgumentException('Skill file must start with YAML front matter.');
        }

        if (preg_match('/\A---\R(.*?)\R---\R?(.*)\z/s', ltrim($raw), $matches) !== 1) {
            throw new InvalidArgumentException('Skill front matter is malformed.');
        }

        return [$matches[1], $matches[2]];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseFrontMatter(string $yaml, string $file): array
    {
        try {
            $parsed = Yaml::parse($yaml);
        } catch (ParseException $exception) {
            throw new InvalidArgumentException("Skill YAML front matter invalid [{$file}]: ".$exception->getMessage(), 0, $exception);
        }

        if (! is_array($parsed)) {
            throw new InvalidArgumentException("Skill front matter must be a mapping [{$file}].");
        }

        return $parsed;
    }

    /**
     * @return array<string, string>
     */
    private function parseSections(string $body): array
    {
        $sections = [];
        $current = '_preamble';
        $buffer = [];

        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            if (preg_match('/^##\s+(.+)\s*$/', $line, $matches) === 1) {
                $sections[$current] = trim(implode("\n", $buffer));
                $current = strtolower(trim($matches[1]));
                $buffer = [];

                continue;
            }

            $buffer[] = $line;
        }

        $sections[$current] = trim(implode("\n", $buffer));

        return $sections;
    }

    /**
     * @param  array<string, string>  $sections
     * @param  list<string>  $keys
     */
    private function sectionText(array $sections, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($sections[$key]) && trim($sections[$key]) !== '') {
                return trim($sections[$key]);
            }
        }

        return '';
    }

    /**
     * @param  array<string, string>  $sections
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function sectionList(array $sections, array $keys): array
    {
        $text = $this->sectionText($sections, $keys);
        if ($text === '') {
            return [];
        }

        $items = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $line = preg_replace('/^[-*]\s+/', '', $line) ?? $line;
            $line = preg_replace('/^\d+\.\s+/', '', $line) ?? $line;
            if ($line !== '') {
                $items[] = $line;
            }
        }

        return $items;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<SkillEvidenceRequirement>
     */
    private function parseEvidenceRequirements(mixed $value, bool $optional): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = SkillEvidenceRequirement::fromLegacyKey(trim($item), $optional);

                continue;
            }

            if (is_array($item) && isset($item['key']) && is_string($item['key']) && trim($item['key']) !== '') {
                if ($optional && ! isset($item['role'])) {
                    $item['role'] = SkillEvidenceRequirement::ROLE_OPTIONAL_ENRICHMENT;
                }
                if ($optional && ! isset($item['missing_behavior'])) {
                    $item['missing_behavior'] = SkillEvidenceRequirement::MISSING_CONTINUE;
                }
                if ($optional && ! isset($item['integrity_required'])) {
                    $item['integrity_required'] = false;
                }
                $out[] = SkillEvidenceRequirement::fromArray($item);
            }
        }

        return $out;
    }

    /**
     * @return list<array{key: string, type: string, purpose: string, inputs: list<string>, validation: string, abstain_when: string}>
     */
    private function parseMethodologySteps(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (! is_array($item)) {
                continue;
            }

            $key = trim((string) ($item['key'] ?? ''));
            if ($key === '') {
                continue;
            }

            $inputs = $this->stringList($item['inputs'] ?? []);

            $out[] = [
                'key' => $key,
                'type' => trim((string) ($item['type'] ?? 'CHECK')),
                'purpose' => trim((string) ($item['purpose'] ?? '')),
                'inputs' => $inputs,
                'validation' => trim((string) ($item['validation'] ?? '')),
                'abstain_when' => trim((string) ($item['abstain_when'] ?? '')),
            ];
        }

        return $out;
    }
}
