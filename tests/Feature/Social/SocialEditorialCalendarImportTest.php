<?php

namespace Tests\Feature\Social;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Services\Social\SocialPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** The approved 4-week editorial calendar (9 Kasım - 6 Aralık 2026) replaces the earlier unpublished drafts. */
final class SocialEditorialCalendarImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_calendar_enters_as_drafts_and_old_unpublished_drafts_are_skipped(): void
    {
        Storage::fake('public');
        SocialAccount::query()->create(['platform' => 'instagram', 'external_id' => 'IG1', 'username' => 'moximucom', 'access_token' => 't', 'page_id' => 'P1']);
        $img = imagecreatetruecolor(20, 25);
        ob_start();
        imagejpeg($img);
        $jpg = (string) ob_get_clean();
        $planner = app(SocialPlanner::class);
        $oldDraft = $planner->create(['kind' => 'image', 'caption' => 'eski', 'publish_at' => '2026-11-09 11:30'], [['bytes' => $jpg, 'name' => 'a.jpg']]);
        $oldDraft->forceFill(['note' => 'import:2026-11:w1-pzt-mevzuat'])->save();
        $published = $planner->create(['kind' => 'image', 'caption' => 'yayında', 'publish_at' => '2026-11-08 11:30'], [['bytes' => $jpg, 'name' => 'b.jpg']]);
        $published->forceFill(['note' => 'import:2026-11:x', 'status' => SocialPost::PUBLISHED])->save();

        $migration = require database_path('migrations/2027_01_07_170000_replace_moximu_social_posts_with_editorial_calendar.php');
        $created = $migration->apply(database_path('data/social/2026-11b'));

        $this->assertSame(35, $created);
        $this->assertSame(SocialPost::SKIPPED, $oldDraft->fresh()->status);
        $this->assertSame(SocialPost::PUBLISHED, $published->fresh()->status);
        $new = SocialPost::query()->where('note', 'like', 'import:2026-11b:%')->get();
        $this->assertCount(35, $new);
        $this->assertTrue($new->every(fn (SocialPost $p): bool => $p->status === SocialPost::DRAFT));
        $this->assertSame(4, $new->where('kind', 'reels')->count());
        $this->assertSame(3, $new->filter(fn (SocialPost $p): bool => str_starts_with((string) $p->title, 'Klinik izni gerekli'))->count());
        $this->assertSame(0, $migration->apply(database_path('data/social/2026-11b')));
    }
}
