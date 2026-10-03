<?php

namespace App\Mcp\Tools;

use App\Models\Suggestion;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Site\SiteOperations;
use App\Services\Site\SiteSuggestionTypes;
use App\Support\Ai\AiRouteKeys;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Starts "Taslak hazırla" for an operator-approved content title, exactly like the button: MoxDOP builds the writer input and, when site.write_article is set to Claude (MCP), a write task appears in list-tasks within a minute; do it like any task. The draft then waits in MoxDOP for the operator; sending it to WordPress stays the operator\'s click.')]
class RequestArticle extends Tool
{
    public function __construct(private readonly AiTaskQueue $tasks) {}

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['suggestion_id' => ['required', 'integer']]);
        $suggestion = Suggestion::query()->where('action_type', SiteSuggestionTypes::CONTENT)->whereHas('brand', fn ($q) => $q->operational())->find($validated['suggestion_id']);
        if ($suggestion === null) {
            return Response::error('İçerik önerisi bulunamadı ya da marka aktif değil.');
        }
        if ($suggestion->status !== Suggestion::APPROVED) {
            return Response::error('Bu başlık operatör tarafından onaylanmamış (durum: '.$suggestion->status.'). Yalnız onaylı başlıklar yazılır.');
        }
        $siteId = (int) (((array) $suggestion->action)['site_id'] ?? 0);
        if ($siteId < 1) {
            return Response::error('Önerinin sitesi yok.');
        }
        if (isset(((array) $suggestion->action)['article'])) {
            return Response::error('Bu başlığın taslağı zaten var; operatör MoxDOP\'ta inceleyecek.');
        }
        SiteOperations::dispatch($siteId, SiteOperations::WRITE_ARTICLE, ['suggestion_id' => $suggestion->id]);

        return Response::text($this->tasks->delegated(AiRouteKeys::SITE_WRITE_ARTICLE)
            ? 'Taslak işi başladı; yazma görevi kısa süre içinde list-tasks\'ta görünecek.'
            : 'Taslak işi başladı; site.write_article Claude (MCP) seçili olmadığı için API modeliyle yazılacak.');
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'suggestion_id' => $schema->integer()->description('Content suggestion id from content-queue.')->required(),
        ];
    }
}
