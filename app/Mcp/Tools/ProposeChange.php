<?php

namespace App\Mcp\Tools;

use App\Models\SystemChange;
use App\Services\Operations\SystemChangeDesk;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Geliştirme havuzu: proposes one fix or improvement of MoxDOP itself for the operator to approve (kind: bug, collection, page, design, improvement). Write it in Turkish: title = what changes, detail = what is wrong, why it matters and the proposed fix (file:line when known). evidence = error groups, screen paths, ids. The same finding (same fingerprint, or kind + title) is never proposed twice; a rejected one stays rejected. Proposing changes nothing in the system.')]
class ProposeChange extends Tool
{
    public function handle(Request $request, SystemChangeDesk $desk): Response
    {
        $validated = $request->validate([
            'kind' => ['required', Rule::in(SystemChange::KINDS)],
            'title' => ['required', 'string', 'min:5', 'max:200'],
            'detail' => ['required', 'string', 'min:10', 'max:4000'],
            'evidence' => ['nullable', 'array', 'max:15'],
            'evidence.*' => ['string', 'max:500'],
            'priority' => ['nullable', 'integer', Rule::in([1, 2, 3])],
            'fingerprint' => ['nullable', 'string', 'max:300'],
        ]);
        $outcome = $desk->propose($validated);
        $change = $outcome['change'];

        return Response::text($outcome['created']
            ? 'Öneri #'.$change->id.' havuza eklendi; operatör onayını bekliyor.'
            : 'Bu bulgu zaten havuzda: #'.$change->id.' ('.(SystemChange::STATUS_LABELS[$change->status] ?? $change->status).').');
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'kind' => $schema->string()->enum(SystemChange::KINDS)->description('bug | collection | page | design | improvement')->required(),
            'title' => $schema->string()->description('What changes, Turkish, short.')->required(),
            'detail' => $schema->string()->description('Problem, why it matters, proposed fix (file:line), Turkish.')->required(),
            'evidence' => $schema->array()->items($schema->string())->description('Error groups, screen paths, ids, counts.'),
            'priority' => $schema->integer()->description('1 acil · 2 normal (default) · 3 düşük'),
            'fingerprint' => $schema->string()->description('Stable key of the finding (e.g. exception class + file:line, or screen path + issue).'),
        ];
    }
}
