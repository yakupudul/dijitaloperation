<?php

namespace App\Mcp\Tools;

use App\Services\AiTasks\ResultCorrections;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Corrects a result you already submitted where it landed (find it with task-result): one field of a desk / content row (target suggestion; path like article.html, translations.en.html, proposal.seo_title, proposal.meta_description, proposal.internal_links, proposed, repair_review.reason) or a planned Business Profile post (target gbp_post, path summary). Give replacements (exact find → replace pairs; every occurrence) or a whole new value. Not sent yet → corrected in place, sector checks run again and the row is AI-checked again; it still goes out only with the operator\'s daily click or desk approval. Already sent → nothing goes to the site: a new row with the corrected value waits for the operator\'s approval. Never invent facts, links or claims; fix only what is wrong.')]
class CorrectResult extends Tool
{
    public function handle(Request $request, ResultCorrections $corrections): Response
    {
        $validated = $request->validate([
            'target' => ['required', Rule::in([ResultCorrections::TARGET_SUGGESTION, ResultCorrections::TARGET_GBP_POST])],
            'id' => ['required', 'integer'],
            'path' => ['required', 'string', 'max:80'],
            'replacements' => ['nullable', 'array', 'max:30'],
            'replacements.*.find' => ['required', 'string', 'max:2000'],
            'replacements.*.replace' => ['present', 'nullable', 'string', 'max:4000'],
            'value' => ['nullable'],
            'note' => ['required', 'string', 'min:5', 'max:300'],
            'task_id' => ['nullable', 'integer'],
        ]);
        $value = $validated['value'] ?? null;
        if (is_string($value) && $validated['path'] === 'proposal.internal_links') {
            $value = json_decode($value, true);
        }
        try {
            $result = $corrections->correct($validated['target'], (int) $validated['id'], $validated['path'],
                array_map(fn (array $p): array => ['find' => (string) $p['find'], 'replace' => (string) ($p['replace'] ?? '')], (array) ($validated['replacements'] ?? [])),
                $value, $validated['note'], isset($validated['task_id']) ? (int) $validated['task_id'] : null);
        } catch (ValidationException $exception) {
            return Response::error(implode(' ', $exception->validator->errors()->all()));
        }

        return Response::json($result);
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'target' => $schema->string()->enum([ResultCorrections::TARGET_SUGGESTION, ResultCorrections::TARGET_GBP_POST])->description('suggestion | gbp_post')->required(),
            'id' => $schema->integer()->description('Row id (suggestion id or gbp post id) from task-result.')->required(),
            'path' => $schema->string()->description('Field path from task-result fields.')->required(),
            'replacements' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'find' => $row->string()->required(), 'replace' => $row->string()->required(),
            ]))->description('Exact text → replacement pairs (preferred for small fixes).'),
            'value' => $schema->string()->description('Or: the whole new text (proposal.internal_links: the kept links as a JSON list; only existing URLs).'),
            'note' => $schema->string()->description('What was wrong, Turkish, short.')->required(),
            'task_id' => $schema->integer()->description('The AI task whose answer this corrects (for the log).'),
        ];
    }
}
