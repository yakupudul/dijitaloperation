<?php

namespace Tests\Feature\Social;

use App\Models\Agreement;
use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\GbpReview;
use App\Models\SocialPost;
use App\Services\Site\BrandCover;
use App\Services\Social\ClientPosts;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Hekim ve klinik gönderileri (yakup 2026-11-13): a new clinic's first agreement prepares a welcome post, a hekim's
 * Google review on Moximu's own profile prepares a review post; both wait for his approval, pinned to the same day.
 */
final class ClientPostsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-13 14:00', 'Europe/Istanbul'));
        Storage::fake('public');
    }

    private function agreement(Brand $brand, string $kind = 'monthly', string $starts = '2026-11-13'): Agreement
    {
        return Agreement::query()->create(['brand_id' => $brand->id, 'kind' => $kind, 'fee' => 14000, 'starts_on' => $starts]);
    }

    public function test_a_new_clinics_first_agreement_prepares_one_welcome_draft_for_approval(): void
    {
        $clinic = Brand::factory()->create(['name' => 'GÜLÜŞ DİŞ KLİNİĞİ - Manisa']);
        $this->agreement($clinic);

        $post = SocialPost::withoutGlobalScopes()->where('note', 'client:welcome:'.$clinic->id)->sole();
        $this->assertSame(SocialPost::DRAFT, $post->status);
        $this->assertTrue((bool) $post->pinned);
        $this->assertStringStartsWith('Hoş geldiniz, Gülüş Diş Kliniği.', $post->caption);
        $this->assertSame('2026-11-13 15:30', $post->publish_at->timezone('Europe/Istanbul')->format('Y-m-d H:i'));

        $this->agreement($clinic, 'one_time');
        $this->assertSame(1, SocialPost::withoutGlobalScopes()->count(), 'only the first agreement welcomes');
    }

    public function test_no_welcome_for_non_clinics_yearly_rows_old_clients_or_moximu_itself(): void
    {
        $this->agreement(Brand::factory()->create(['name' => 'Bornova Hurda']));
        $this->agreement(Brand::factory()->create(['name' => 'Ada Diş Kliniği']), 'yearly');
        $this->agreement(Brand::factory()->create(['name' => 'Eski Dermatoloji']), 'monthly', '2025-03-01');
        $this->agreement(Brand::factory()->create(['name' => 'Moximu Klinik', 'cover_style' => BrandCover::MOXIMU_REHBER]));
        // A clinic long in the panel whose fee is only now written into Finans.
        $this->agreement(Brand::factory()->create(['name' => 'Yıllanmış Diş Kliniği', 'created_at' => now()->subDays(90)]));

        $this->assertSame(0, SocialPost::withoutGlobalScopes()->count());
    }

    public function test_a_hekim_review_on_moximus_own_profile_becomes_one_review_draft(): void
    {
        $moximu = Brand::factory()->create(['name' => 'Moximu', 'auto_blog' => ['per_day' => 2], 'cover_style' => BrandCover::MOXIMU_REHBER]);
        $profile = DigitalAsset::factory()->create(['brand_id' => $moximu->id]);
        $other = DigitalAsset::factory()->create();
        $review = fn (int $asset, string $id, string $name, string $comment, string $stars = 'FIVE', string $at = '2026-11-12 10:00') => GbpReview::query()->forceCreate([
            'digital_asset_id' => $asset, 'external_resource_id' => 1, 'run_id' => 1, 'location_name' => 'locations/1', 'review_id' => $id,
            'reviewer' => ['displayName' => $name], 'star_rating' => $stars, 'comment' => $comment, 'create_time' => $at, 'raw_payload' => [], 'collected_at' => now()]);

        $review($profile->id, 'r1', 'Dt. Ayşe Kaya', 'Kliniğimizin sitesini ve Google profilini düzenli takip ediyorlar, teşekkürler.');
        $review($profile->id, 'r2', 'Mehmet Yılmaz', 'Hızlı ve ilgili bir ekip.');
        $review($profile->id, 'r3', 'Dr. Ali Er', 'Fena değil.', 'THREE');
        $review($profile->id, 'r4', 'Dr. Can Demir', 'Çok memnunuz.', 'FIVE', '2026-10-01 10:00');
        $review($other->id, 'r5', 'Dr. Başka', 'Kliniğim için harika.');

        $this->assertSame(1, app(ClientPosts::class)->reviews());
        $post = SocialPost::withoutGlobalScopes()->where('note', 'client:review:r1')->sole();
        $this->assertSame(SocialPost::DRAFT, $post->status);
        $this->assertStringContainsString('Dt. Ayşe Kaya', $post->caption);
        $this->assertSame(0, app(ClientPosts::class)->reviews(), 'never twice');
    }

    public function test_hekim_detection_and_display_names(): void
    {
        $this->assertTrue(ClientPosts::isHekim('Uzm. Dr. Zeynep Ak', 'Teşekkürler'));
        $this->assertTrue(ClientPosts::isHekim('Zeynep Ak', 'Hastalarımız artık bizi kolayca buluyor.'));
        $this->assertFalse(ClientPosts::isHekim('Drina Ak', 'Güzel hizmet.'));
        $this->assertSame('Dr. Dt. Burçin Öncül', ClientPosts::displayName('Dr. Dt. Burçin ÖNCÜL'));
        $this->assertSame('Işık Diş', ClientPosts::displayName('IŞIK DİŞ | İzmir'));
    }
}
