<?php

namespace App\Services\Strategy;

use App\Models\Strategy;
use App\Models\StrategyVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Stratejiler: the one write path for strategies (the panel, the MCP `save-strategy` tool and the import command).
 * A new text becomes a new version; scope fields are changed in place; the same text again adds no version.
 */
final class StrategyBook
{
    /** Scope fields that can be set on create and changed later. */
    public const array SCOPE = ['brand_id', 'title', 'channel', 'service', 'markets', 'languages', 'budget_amount', 'budget_currency',
        'budget_period', 'summary', 'request', 'source_ref'];

    /** @return array<string, mixed> validation rules shared by every caller */
    public static function rules(bool $creating): array
    {
        return [
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'min:3', 'max:200'],
            'channel' => ['nullable', Rule::in(array_keys(Strategy::CHANNELS))],
            'service' => ['nullable', 'string', 'max:200'],
            'markets' => ['nullable', 'array', 'max:40'],
            'markets.*' => ['string', 'max:80'],
            'languages' => ['nullable', 'array', 'max:20'],
            'languages.*' => ['string', 'max:40'],
            'budget_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'budget_currency' => ['nullable', 'string', 'size:3'],
            'budget_period' => ['nullable', Rule::in(array_keys(Strategy::BUDGET_PERIODS))],
            'status' => ['nullable', Rule::in(array_keys(Strategy::STATUSES))],
            'summary' => ['nullable', 'string', 'max:2000'],
            'request' => ['nullable', 'string', 'max:5000'],
            'source_ref' => ['nullable', 'string', 'max:500'],
            'body' => [$creating ? 'required' : 'nullable', 'string', 'min:20', 'max:400000'],
            'change_note' => ['nullable', 'string', 'max:500'],
            'evidence' => ['nullable', 'array', 'max:40'],
            'evidence.*.label' => ['required_with:evidence', 'string', 'max:200'],
            'evidence.*.detail' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Creates a strategy (with version 1) or, given one, changes its scope and adds a version when the text differs.
     *
     * @param  array<string, mixed>  $data  validated by rules()
     */
    public function save(array $data, string $author = 'Claude', ?Strategy $strategy = null): Strategy
    {
        return DB::transaction(function () use ($data, $author, $strategy): Strategy {
            $scope = array_intersect_key($data, array_flip(self::SCOPE));
            foreach (['markets', 'languages'] as $list) {
                if (array_key_exists($list, $scope)) {
                    $scope[$list] = array_values(array_unique(array_filter(array_map(fn ($v): string => trim((string) $v), (array) $scope[$list])))) ?: null;
                }
            }
            if (isset($scope['budget_currency'])) {
                $scope['budget_currency'] = Str::upper((string) $scope['budget_currency']);
            } elseif (array_key_exists('budget_currency', $scope)) {
                unset($scope['budget_currency']);
            }
            if (array_key_exists('channel', $scope) && $scope['channel'] === null) {
                unset($scope['channel']);
            }

            if ($strategy === null) {
                $strategy = Strategy::query()->create($scope + ['status' => $data['status'] ?? 'draft', 'current_version' => 0, 'status_changed_at' => now()]);
            } else {
                $strategy->fill($scope);
                if (isset($data['status'])) {
                    $this->applyStatus($strategy, (string) $data['status']);
                }
                $strategy->save();
            }

            $body = isset($data['body']) ? trim((string) $data['body']) : '';
            $current = $strategy->current_version > 0 ? $strategy->versions()->where('version', $strategy->current_version)->first() : null;
            if ($body !== '' && ($current === null || trim($current->body) !== $body)) {
                $next = (int) $strategy->versions()->max('version') + 1;
                StrategyVersion::query()->create([
                    'strategy_id' => $strategy->id, 'version' => $next, 'body' => $body,
                    'change_note' => $data['change_note'] ?? ($next === 1 ? 'İlk sürüm' : null),
                    'evidence' => $this->evidence($data['evidence'] ?? null) ?? $current?->evidence,
                    'author' => Str::limit($author, 60, ''),
                ]);
                $strategy->forceFill(['current_version' => $next])->save();
            } elseif ($current !== null && array_key_exists('evidence', $data) && $data['evidence'] !== null) {
                $current->update(['evidence' => $this->evidence($data['evidence'])]);
            }

            return $strategy->refresh();
        });
    }

    public function setStatus(Strategy $strategy, string $status): void
    {
        if (! isset(Strategy::STATUSES[$status])) {
            return;
        }
        $this->applyStatus($strategy, $status);
        $strategy->save();
    }

    /** Shows an earlier version again by copying it as the newest one (history stays whole). */
    public function restore(Strategy $strategy, int $version, string $author): void
    {
        $old = $strategy->versions()->where('version', $version)->first();
        if ($old === null || $version === $strategy->current_version) {
            return;
        }
        $this->save(['body' => $old->body, 'evidence' => $old->evidence, 'change_note' => 'v'.$version.' geri alındı'], $author, $strategy);
    }

    /**
     * The Markdown as HTML for the strategy page: an id on every h2/h3 (the table of contents is built from them),
     * tables wrapped so wide ones scroll, and the leading h1 dropped because the page shows the title itself.
     *
     * @return array{html: string, toc: list<array{id: string, text: string, level: int}>, words: int}
     */
    public function render(string $markdown): array
    {
        $html = Str::markdown($markdown, ['html_input' => 'escape', 'allow_unsafe_links' => false]);
        $html = (string) preg_replace('/\A\s*<h1>.*?<\/h1>/s', '', $html, 1);
        $toc = [];
        $html = (string) preg_replace_callback('/<h([23])>(.*?)<\/h\1>/s', function (array $m) use (&$toc): string {
            $id = 'bolum-'.(count($toc) + 1);
            $toc[] = ['id' => $id, 'text' => html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5), 'level' => (int) $m[1]];

            return '<h'.$m[1].' id="'.$id.'" class="scroll-mt-24">'.$m[2].'</h'.$m[1].'>';
        }, $html);
        $html = str_replace(['<table>', '</table>'], ['<div class="table-wrap"><table data-sortable="false">', '</table></div>'], $html);

        return ['html' => $html, 'toc' => $toc, 'words' => str_word_count(strip_tags($html))];
    }

    private function applyStatus(Strategy $strategy, string $status): void
    {
        if ($strategy->status !== $status) {
            $strategy->status = $status;
            $strategy->status_changed_at = now();
        }
    }

    /**
     * @param  mixed  $evidence
     * @return list<array{label: string, detail: string|null}>|null
     */
    private function evidence($evidence): ?array
    {
        if (! is_array($evidence)) {
            return null;
        }
        $rows = [];
        foreach ($evidence as $row) {
            $label = trim((string) ($row['label'] ?? ''));
            if ($label !== '') {
                $rows[] = ['label' => $label, 'detail' => isset($row['detail']) ? trim((string) $row['detail']) : null];
            }
        }

        return $rows ?: null;
    }
}
