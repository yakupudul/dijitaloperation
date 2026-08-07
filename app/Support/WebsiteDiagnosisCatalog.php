<?php

namespace App\Support;

use RuntimeException;

/**
 * Loads deterministic Website Diagnosis catalog contracts from docs/website/DIAGNOSIS_CATALOG.md (ADR-031).
 */
class WebsiteDiagnosisCatalog
{
    private const DEFAULT_RELATIVE_PATH = 'docs/website/DIAGNOSIS_CATALOG.md';

    /** @var array<string, array{id: string, recommendation_logic: string|null, finding_title: string|null}>|null */
    private ?array $items = null;

    public function __construct(
        private readonly string $catalogPath = '',
    ) {}

    public function recommendationLogic(string $catalogItemId): ?string
    {
        $item = $this->item($catalogItemId);

        if ($item === null) {
            return null;
        }

        $logic = $item['recommendation_logic'];

        if (! is_string($logic) || trim($logic) === '') {
            return null;
        }

        return trim($logic);
    }

    /**
     * @return array{id: string, recommendation_logic: string|null, finding_title: string|null}|null
     */
    public function item(string $catalogItemId): ?array
    {
        $items = $this->items();

        return $items[$catalogItemId] ?? null;
    }

    /**
     * @return array<string, array{id: string, recommendation_logic: string|null, finding_title: string|null}>
     */
    public function items(): array
    {
        if ($this->items !== null) {
            return $this->items;
        }

        $path = $this->resolvePath();

        if (! is_file($path)) {
            throw new RuntimeException('Website Diagnosis catalog missing at '.$path.' (ADR-031).');
        }

        $markdown = file_get_contents($path);

        if ($markdown === false) {
            throw new RuntimeException('Unable to read Website Diagnosis catalog at '.$path.'.');
        }

        $this->items = $this->parseMarkdown($markdown);

        return $this->items;
    }

    private function resolvePath(): string
    {
        if ($this->catalogPath !== '') {
            return $this->catalogPath;
        }

        return base_path(self::DEFAULT_RELATIVE_PATH);
    }

    /**
     * @return array<string, array{id: string, recommendation_logic: string|null, finding_title: string|null}>
     */
    private function parseMarkdown(string $markdown): array
    {
        $items = [];
        $sections = preg_split('/^###\s+\d+\.\s+`([^`]+)`\s*$/m', $markdown, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($sections === false || count($sections) < 3) {
            return [];
        }

        for ($i = 1; $i < count($sections); $i += 2) {
            $id = trim((string) $sections[$i]);
            $body = (string) ($sections[$i + 1] ?? '');

            if ($id === '') {
                continue;
            }

            $items[$id] = [
                'id' => $id,
                'recommendation_logic' => $this->tableField($body, 'recommendation_logic'),
                'finding_title' => $this->findingTitle($body),
            ];
        }

        return $items;
    }

    private function tableField(string $sectionBody, string $field): ?string
    {
        $pattern = '/^\|\s*\*\*'.preg_quote($field, '/').'\*\*\s*\|\s*(.*?)\s*\|\s*$/im';

        if (preg_match($pattern, $sectionBody, $matches) !== 1) {
            return null;
        }

        $value = trim($matches[1]);

        return $value === '' ? null : $value;
    }

    private function findingTitle(string $sectionBody): ?string
    {
        $findingOutput = $this->tableField($sectionBody, 'finding_output');

        if ($findingOutput === null) {
            return null;
        }

        if (preg_match('/\*\*title:\*\*\s*`([^`]+)`/i', $findingOutput, $matches) === 1) {
            return trim($matches[1]);
        }

        if (preg_match('/\*\*title:\*\*\s*([^·]+)/i', $findingOutput, $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
    }
}
