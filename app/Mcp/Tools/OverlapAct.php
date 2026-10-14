<?php

namespace App\Mcp\Tools;

use App\Services\Site\OverlapAutopilot;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Acts on Onarım masası cluster overlaps in the Admin\'s name, only after you checked each with cluster-overlaps and only when the operator asked you to work the overlaps. decision: redirect ("301 ile birleştir": redirect into the main page through the site plugin, page to draft), keep ("Ayrı kalsın": closes a wrong proposal), make_main ("Ana sayfa bu olsun"), rewrite ("Ayrıştır": the AI writes the page\'s refocused version in the background; state in cluster-overlaps rewrite.state), submit_rewrite (one id: your own version when rewrite.state is needs_claude; same checks as the AI\'s), publish (send a checked ready version: SEO fields at once, body as a WordPress draft copy), go_live (put that confirmed draft copy live). Everything is logged and undoable on the site.')]
class OverlapAct extends Tool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'], 'ids.*' => ['integer'],
            'decision' => ['required', 'in:'.implode(',', OverlapAutopilot::DECISIONS)],
            'seo_title' => ['nullable', 'string', 'max:200'], 'meta_description' => ['nullable', 'string', 'max:400'], 'html' => ['nullable', 'string', 'max:60000'],
            'internal_links' => ['nullable', 'array', 'max:5'], 'note' => ['nullable', 'string', 'max:300'],
        ]);
        if ($validated['decision'] === 'submit_rewrite' && count($validated['ids']) !== 1) {
            return Response::error('submit_rewrite tek bir çakışma için gönderilir.');
        }
        $admin = OverlapAutopilot::admin();
        if ($admin === null) {
            return Response::error('Aktif Admin kullanıcısı yok.');
        }
        Auth::setUser($admin);
        $result = app(OverlapAutopilot::class)->act(array_map('intval', $validated['ids']), $validated['decision'], $admin,
            array_intersect_key($validated, array_flip(['seo_title', 'meta_description', 'html', 'internal_links', 'note'])));

        return Response::json(['decision' => $validated['decision'], 'results' => $result]);
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'ids' => $schema->array()->items($schema->integer())->description('Overlap ids from cluster-overlaps.')->required(),
            'decision' => $schema->string()->enum(OverlapAutopilot::DECISIONS)->required(),
            'seo_title' => $schema->string()->description('submit_rewrite: the page\'s new SEO title (≤ 60 characters).'),
            'meta_description' => $schema->string()->description('submit_rewrite: the new meta description (≤ 155 characters).'),
            'html' => $schema->string()->description('submit_rewrite: the FULL new page body (only when the page body is editable; keep every fact, list, image and form).'),
            'internal_links' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => ['anchor' => $row->string()->required(), 'url' => $row->string()->required()]))
                ->description('submit_rewrite: links to add (site pages only), e.g. the one link to the main page.'),
            'note' => $schema->string()->description('submit_rewrite: one Turkish sentence on what changed.'),
        ];
    }
}
