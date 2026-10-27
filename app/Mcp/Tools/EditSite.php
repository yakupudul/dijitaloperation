<?php

namespace App\Mcp\Tools;

use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\Site\OverlapAutopilot;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Throwable;

#[Description(<<<'TEXT'
Siteyi düzenleme: changes a brand's WordPress site in the Admin's name, only when the operator asked for it in this conversation. Goes through the same write path as an approved Onarım masası row: logged on Akış and in the site's log, undoable, the connector keeps the previous value. One of:
- changes: up to 100 fixes on existing posts / pages / images, each {type, object_id, value}: seo_title (≤ 60 chars), seo_description (≤ 155), alt_text (object_id = media id), noindex (true/false), canonical (URL on the site), schema (JSON-LD object), internal_link (value {anchor, url}: the first plain-text anchor in the post becomes a link to a page of the same site). Needs "SEO fixes" on in the plugin (list-sites edit.fixes).
- content: {object_id, title?, html}: a new version of an existing page; it lands as a WordPress draft copy next to the live page (the live page is untouched until the operator puts it live). Keep every fact, image, list and form. Needs "Content updates" (edit.content).
- draft: {title, content_html, post_type: post|page, excerpt}: a new draft post or page, never published. Needs drafts (edit.drafts).
- undo: an action_id from site-writes: puts that write back.
Read the page first (read-site ids or get-page). Never invent prices, phone numbers or claims. A site without a brand gets no edits. The write runs in the background: follow it with site-writes.
TEXT)]
class EditSite extends Tool
{
    public function handle(Request $request, ExternalWriteService $writes): Response
    {
        $validated = $request->validate([
            'site_id' => ['required', 'integer'],
            'changes' => ['nullable', 'array', 'max:100'],
            'changes.*.type' => ['required_with:changes', 'string', 'in:seo_title,seo_description,alt_text,noindex,canonical,schema,internal_link'],
            'changes.*.object_id' => ['required_with:changes', 'integer', 'min:1'],
            'content' => ['nullable', 'array'],
            'content.object_id' => ['required_with:content', 'integer', 'min:1'],
            'content.title' => ['nullable', 'string', 'max:200'],
            'content.html' => ['required_with:content', 'string', 'max:300000'],
            'draft' => ['nullable', 'array'],
            'draft.title' => ['required_with:draft', 'string', 'max:200'],
            'draft.content_html' => ['required_with:draft', 'string', 'max:300000'],
            'draft.post_type' => ['nullable', 'in:post,page'],
            'draft.excerpt' => ['nullable', 'string', 'max:300'],
            'undo' => ['nullable', 'integer', 'min:1'],
        ]);
        $asks = array_filter([isset($validated['changes']), isset($validated['content']), isset($validated['draft']), isset($validated['undo'])]);
        if (count($asks) !== 1) {
            return Response::error('Bir çağrıda yalnız biri: changes, content, draft ya da undo.');
        }
        $site = DigitalAsset::query()->find((int) $validated['site_id']);
        if ($site === null) {
            return Response::error('Bu site_id ile site yok; list-sites ile bak.');
        }
        $admin = OverlapAutopilot::admin();
        if ($admin === null) {
            return Response::error('Aktif Admin kullanıcısı yok.');
        }
        Auth::setUser($admin);
        $stamp = 'claude-mcp:'.now()->format('YmdHis');
        try {
            if (isset($validated['undo'])) {
                $action = ExternalWriteAction::query()->where('digital_asset_id', $site->id)->find((int) $validated['undo']);
                if ($action === null) {
                    return Response::error('Bu sitede bu numarayla yazma yok.');
                }
                $action = $writes->requestUndo($admin, $action);
            } elseif (isset($validated['changes'])) {
                $changes = array_map(fn (array $change, int $i): array => [
                    'type' => (string) $change['type'], 'object_id' => (int) $change['object_id'], 'value' => $change['value'] ?? null,
                    'reference' => $stamp.':'.$change['type'].':'.$change['object_id'].':'.$i,
                ], array_values($request->get('changes')), array_keys(array_values($request->get('changes'))));
                $action = $writes->requestSiteFixes($admin, $site, $changes);
            } elseif (isset($validated['content'])) {
                $action = $writes->requestContentDraft($admin, $site, [
                    'object_id' => (int) $validated['content']['object_id'], 'title' => $validated['content']['title'] ?? null,
                    'html' => (string) $validated['content']['html'], 'reference' => $stamp.':content:'.$validated['content']['object_id'],
                ]);
            } else {
                $action = $writes->requestDraft($admin, $site, [
                    'title' => (string) $validated['draft']['title'], 'content_html' => (string) $validated['draft']['content_html'],
                    'post_type' => $validated['draft']['post_type'] ?? 'post', 'excerpt' => (string) ($validated['draft']['excerpt'] ?? ''),
                    'reference' => $stamp.':draft',
                ]);
            }
        } catch (ValidationException $exception) {
            return Response::error(collect($exception->errors())->flatten()->implode(' '));
        } catch (Throwable $exception) {
            return Response::error($exception->getMessage());
        }

        return Response::json(['action_id' => $action->id, 'status' => $action->status, 'status_label' => $action->statusLabel(),
            'next' => 'Arka planda gönderiliyor; sonucu site-writes ile oku (ids=['.$action->id.']).']);
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'site_id' => $schema->integer()->description('site_id from list-sites.')->required(),
            'changes' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'type' => $row->string()->enum(['seo_title', 'seo_description', 'alt_text', 'noindex', 'canonical', 'schema', 'internal_link'])->required(),
                'object_id' => $row->integer()->description('WordPress post / page id (media id for alt_text).')->required(),
                'value' => $row->string()->description('The new value; internal_link: {anchor, url}; schema: JSON-LD object; noindex: true / false.'),
            ]))->description('Fixes on existing content (see the tool description).'),
            'content' => $schema->object(fn (JsonSchema $row): array => [
                'object_id' => $row->integer()->required(), 'title' => $row->string(), 'html' => $row->string()->description('The FULL new body.')->required(),
            ])->description('A new version of an existing page, as a WordPress draft copy.'),
            'draft' => $schema->object(fn (JsonSchema $row): array => [
                'title' => $row->string()->required(), 'content_html' => $row->string()->required(), 'post_type' => $row->string()->enum(['post', 'page']), 'excerpt' => $row->string(),
            ])->description('A new draft post or page.'),
            'undo' => $schema->integer()->description('action_id of an earlier write to put back.'),
        ];
    }
}
