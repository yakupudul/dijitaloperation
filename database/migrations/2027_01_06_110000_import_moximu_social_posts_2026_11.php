<?php

use App\Models\SocialPost;
use App\Services\Social\SocialPlanner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * ADR-083: the first two weeks of Moximu's own posts (9-20 Kasım 2026: 7 feed posts, 7 stories, 2 Reels), prepared by
 * Claude, enter the plan as drafts. Nothing goes out until the Admin approves the week on /sosyal. Runs once per post
 * (the note keeps the import key); skipped in tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing') || ! class_exists(SocialPlanner::class)) {
            return;
        }
        $dir = database_path('data/social/2026-11');
        $posts = json_decode((string) @file_get_contents($dir.'/posts.json'), true);
        if (! is_array($posts)) {
            return;
        }
        $planner = app(SocialPlanner::class);
        foreach ($posts as $p) {
            $key = 'import:2026-11:'.$p['slug'];
            if (SocialPost::query()->where('note', $key)->exists()) {
                continue;
            }
            try {
                $files = array_map(fn (string $f): array => ['bytes' => (string) file_get_contents($dir.'/'.$f), 'name' => $f], $p['files']);
                $post = $planner->create([
                    'kind' => $p['kind'], 'pillar' => $p['pillar'] ?? null, 'title' => $p['title'] ?? null,
                    'caption' => $p['caption'] ?? null, 'linkedin_text' => $p['linkedin_text'] ?? null,
                    'publish_at' => $p['publish_at'], 'created_by' => 'claude',
                ], $files);
                $post->forceFill(['note' => $key])->save();
            } catch (Throwable $e) {
                Log::warning('Social import failed.', ['slug' => $p['slug'], 'error' => $e->getMessage()]);
            }
        }
    }

    public function down(): void {}
};
