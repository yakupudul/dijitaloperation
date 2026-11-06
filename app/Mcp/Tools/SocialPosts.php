<?php

namespace App\Mcp\Tools;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Services\Social\SocialPlanner;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Throwable;

#[Description('Moximu\'s own social media plan (Instagram, Facebook, LinkedIn; ADR-083). action=list: accounts, upcoming and recent posts with channel results. action=draft: adds a post as a DRAFT (never published before the operator approves it on /sosyal): kind image | carousel (2-10 files, in order) | reels (mp4) | story; media = files as https URLs or base64; caption = Instagram+Facebook text; linkedin_text (optional; LinkedIn gets it only when given); publish_at in Istanbul time "Y-m-d H:i". action=update: change caption / linkedin_text / publish_at of a draft or approved post. action=delete: remove a draft. Rules: corporate plain Turkish, no MoxDOP name, no guarantees, numbers only from recorded results, no AI images.')]
class SocialPosts extends Tool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['list', 'draft', 'update', 'delete'])],
            'id' => ['nullable', 'integer'],
            'kind' => ['nullable', Rule::in(SocialPost::KINDS)],
            'pillar' => ['nullable', Rule::in(SocialPost::PILLARS)],
            'title' => ['nullable', 'string', 'max:190'],
            'caption' => ['nullable', 'string', 'max:2200'],
            'linkedin_text' => ['nullable', 'string', 'max:3000'],
            'publish_at' => ['nullable', 'date'],
            'source_url' => ['nullable', 'string', 'max:1000'],
            'platforms' => ['nullable', 'array'],
            'platforms.*' => [Rule::in(SocialAccount::PLATFORMS)],
            'media' => ['nullable', 'array', 'max:10'],
            'media.*.url' => ['nullable', 'string', 'max:2000'],
            'media.*.base64' => ['nullable', 'string'],
            'media.*.name' => ['nullable', 'string', 'max:120'],
        ]);
        $planner = app(SocialPlanner::class);

        try {
            return match ($validated['action']) {
                'list' => Response::json($this->listing()),
                'draft' => $this->draft($planner, $validated),
                'update' => $this->update($planner, $validated),
                'delete' => $this->remove($planner, $validated),
            };
        } catch (Throwable $e) {
            return Response::error($e->getMessage());
        }
    }

    /** @param  array<string, mixed>  $v */
    private function draft(SocialPlanner $planner, array $v): Response
    {
        if (($v['kind'] ?? null) === null || ($v['publish_at'] ?? null) === null || ($v['media'] ?? []) === []) {
            return Response::error('draft için kind, publish_at ve media gerekir.');
        }
        $files = [];
        foreach ($v['media'] as $i => $item) {
            if (is_string($item['base64'] ?? null) && $item['base64'] !== '') {
                $bytes = base64_decode(preg_replace('#^data:[^,]+,#', '', $item['base64']) ?? '', true);
                if ($bytes === false) {
                    return Response::error('media['.$i.'] base64 çözülemedi.');
                }
                $files[] = ['bytes' => $bytes, 'name' => (string) ($item['name'] ?? 'dosya-'.($i + 1).'.png')];
            } elseif (is_string($item['url'] ?? null)) {
                $files[] = $planner->download($item['url']);
            }
        }
        $post = $planner->create([
            'kind' => $v['kind'], 'pillar' => $v['pillar'] ?? null, 'title' => $v['title'] ?? null, 'caption' => $v['caption'] ?? null,
            'linkedin_text' => $v['linkedin_text'] ?? null, 'publish_at' => $v['publish_at'], 'source_url' => $v['source_url'] ?? null,
            'created_by' => 'claude', 'platforms' => $v['platforms'] ?? null,
        ], $files);

        return Response::text('Taslak #'.$post->id.' eklendi ('.$post->targets->count().' kanal); operatörün /sosyal ekranında onayını bekliyor.');
    }

    /** @param  array<string, mixed>  $v */
    private function update(SocialPlanner $planner, array $v): Response
    {
        $post = SocialPost::query()->find($v['id'] ?? 0);
        if (! $post instanceof SocialPost) {
            return Response::error('Gönderi bulunamadı.');
        }
        $planner->edit($post, array_intersect_key($v, array_flip(['caption', 'linkedin_text', 'publish_at', 'title'])));

        return Response::text('Gönderi #'.$post->id.' güncellendi.');
    }

    /** @param  array<string, mixed>  $v */
    private function remove(SocialPlanner $planner, array $v): Response
    {
        $post = SocialPost::query()->find($v['id'] ?? 0);
        if (! $post instanceof SocialPost) {
            return Response::error('Gönderi bulunamadı.');
        }
        $planner->delete($post);

        return Response::text('Gönderi #'.$v['id'].' silindi.');
    }

    /** @return array<string, mixed> */
    private function listing(): array
    {
        $tz = SocialPlanner::TIMEZONE;
        $row = fn (SocialPost $p): array => [
            'id' => $p->id, 'kind' => $p->kind, 'pillar' => $p->pillar, 'title' => $p->title, 'status' => $p->status,
            'publish_at' => $p->publish_at->timezone($tz)->format('Y-m-d H:i'), 'caption' => $p->caption, 'linkedin_text' => $p->linkedin_text,
            'media' => array_map(fn (array $m): string => $m['url'], $p->mediaItems()), 'created_by' => $p->created_by,
            'channels' => $p->targets->map(fn (SocialPostTarget $t): array => [
                'platform' => $t->account?->platform, 'status' => $t->status, 'permalink' => $t->permalink, 'error' => $t->error,
            ])->values()->all(),
        ];

        return [
            'accounts' => SocialAccount::query()->get()->map(fn (SocialAccount $a): array => [
                'platform' => $a->platform, 'name' => $a->displayName(), 'enabled' => $a->enabled, 'automatic' => ! $a->isManual(),
            ])->all(),
            'upcoming' => SocialPost::query()->where('publish_at', '>=', now()->subDay())->with('targets.account')->orderBy('publish_at')->limit(60)->get()->map($row)->all(),
            'recent' => SocialPost::query()->where('publish_at', '<', now()->subDay())->with('targets.account')->orderByDesc('publish_at')->limit(30)->get()->map($row)->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['list', 'draft', 'update', 'delete'])->required(),
            'id' => $schema->integer()->description('Post id for update / delete.'),
            'kind' => $schema->string()->enum(SocialPost::KINDS)->description('draft: image | carousel | reels | story'),
            'pillar' => $schema->string()->enum(SocialPost::PILLARS)->description('rehber | vaka | gundem | anlatim | guven'),
            'title' => $schema->string()->description('Short internal label shown on the approval card.'),
            'caption' => $schema->string()->description('Instagram + Facebook text (with hashtags).'),
            'linkedin_text' => $schema->string()->description('LinkedIn text; omit to skip LinkedIn.'),
            'publish_at' => $schema->string()->description('Istanbul time, "Y-m-d H:i".'),
            'source_url' => $schema->string()->description('The site page the post is based on.'),
            'platforms' => $schema->array()->items($schema->string()->enum(SocialAccount::PLATFORMS))->description('Only these channels (default: every enabled account that fits).'),
            'media' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'url' => $row->string()->description('https URL of a JPG/PNG/MP4'),
                'base64' => $row->string()->description('File content in base64 (instead of url)'),
                'name' => $row->string()->description('File name with extension'),
            ]))->description('Files in order (carousel: 2-10).'),
        ];
    }
}
