<?php

use App\Models\SocialPost;
use App\Services\Social\SocialPlanner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * ADR-083: yakup approved the social strategy (2026-11-07 "onaylıyorum yap"). The 16 earlier drafts of 9-20 Kasım
 * (import:2026-11:*) that are not yet published are set aside as "skipped"; the new 4-week calendar (9 Kasım - 6
 * Aralık 2026: series Rakamla, Bunu yazabilir misiniz?, Kaydet ve kullan, Site vitrini, Hekim soruyor; photo covers +
 * info-card inner pages; 4 Reels; stories) enters as drafts. Nothing goes out until the Admin approves it on /sosyal.
 * Posts whose title starts with "Klinik izni gerekli" show a client's site and wait for the clinic's consent.
 * Runs once per post (the note keeps the import key); skipped in tests, which call apply().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing') || ! class_exists(SocialPlanner::class)) {
            return;
        }
        $this->apply(database_path('data/social/2026-11b'));
    }

    public function apply(string $dir): int
    {
        $posts = json_decode((string) @file_get_contents($dir.'/posts.json'), true);
        if (! is_array($posts)) {
            return 0;
        }
        SocialPost::query()->where('note', 'like', 'import:2026-11:%')
            ->whereIn('status', [SocialPost::DRAFT, SocialPost::APPROVED])
            ->update(['status' => SocialPost::SKIPPED]);

        $planner = app(SocialPlanner::class);
        $created = 0;
        foreach ($posts as $p) {
            $key = 'import:2026-11b:'.$p['slug'];
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
                $created++;
            } catch (Throwable $e) {
                Log::warning('Social import failed.', ['slug' => $p['slug'], 'error' => $e->getMessage()]);
            }
        }

        return $created;
    }

    public function down(): void {}
};
