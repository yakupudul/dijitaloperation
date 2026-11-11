<?php

namespace Tests\Feature;

use App\Livewire\Operator\Settings\BooksPage;
use App\Mcp\Tools\Playbooks as PlaybooksTool;
use App\Models\Playbook;
use App\Models\User;
use App\Services\Brain\Playbooks;
use App\Services\Moxi\MoxiTools;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Beyin › Kitaplar (Marka Düzeni 2.4): shipped books, the operator's edits, lessons only from a correction or a measurement. */
class PlaybooksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true, 'name' => 'Yakup']);
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);
    }

    public function test_the_shipped_books_are_listed_within_the_size_limit_and_read_by_key(): void
    {
        $books = app(Playbooks::class)->all();
        $this->assertSame(['seo', 'aeo', 'google-ads', 'meta', 'sektor-saglik', 'sektor-yerel-hizmet'], array_column($books, 'key'));
        foreach ($books as $book) {
            $this->assertLessThanOrEqual(Playbooks::MAX_BODY, $book['size'], $book['key']);
            $this->assertNotSame('', $book['when'], $book['key']);
        }
        $this->assertStringContainsString('google-ads', MoxiTools::callMcp(PlaybooksTool::class, ['action' => 'list']));
        $read = MoxiTools::callMcp(PlaybooksTool::class, ['action' => 'get', 'keys' => ['seo', 'yok']]);
        $this->assertStringContainsString('SEO usta kitab', $read);
        $this->assertStringContainsString('Bu adla kitap yok', $read);
    }

    public function test_a_lesson_enters_only_from_a_correction_or_a_measurement_with_its_evidence(): void
    {
        $this->assertStringContainsString('duzeltme', MoxiTools::callMcp(PlaybooksTool::class, ['action' => 'lesson', 'key' => 'seo', 'text' => 'Bence böyle.', 'source' => 'fikir', 'evidence' => 'x']));
        $this->assertStringContainsString('dayanağı', MoxiTools::callMcp(PlaybooksTool::class, ['action' => 'lesson', 'key' => 'seo', 'text' => 'Başlığa şehir eklenir.', 'source' => 'olcum']));
        $this->assertSame(0, Playbook::query()->count());

        MoxiTools::callMcp(PlaybooksTool::class, ['action' => 'lesson', 'key' => 'seo', 'text' => 'Başlığa şehir eklenir.', 'source' => 'olcum', 'evidence' => 'Demiderm, Kasım: tık 38 → 71']);
        $book = app(Playbooks::class)->get('seo');
        $this->assertCount(1, $book['lessons']);
        $this->assertFalse($book['edited'], 'a lesson does not replace the shipped text');
        $this->assertStringContainsString('tık 38 → 71', app(Playbooks::class)->text($book));

        $this->assertStringContainsString('yalnız yeni', MoxiTools::callMcp(PlaybooksTool::class, ['action' => 'save', 'key' => 'seo', 'body' => 'ezdim']));
        MoxiTools::callMcp(PlaybooksTool::class, ['action' => 'save', 'key' => 'sektor-veteriner', 'title' => 'Sektör kitabı: veteriner', 'when' => 'veteriner markalarında', 'body' => '## Talep\n- Acil.']);
        $this->assertNotNull(app(Playbooks::class)->get('sektor-veteriner'));
    }

    public function test_the_operator_edits_a_book_takes_the_edit_back_and_removes_a_lesson(): void
    {
        $shipped = app(Playbooks::class)->get('meta')['body'];
        $page = Livewire::test(BooksPage::class)->call('open', 'meta')->assertSee('Meta reklamları usta kitabı')->call('edit')
            ->set('body', str_repeat('a', Playbooks::MAX_BODY + 1))->call('save')->assertSee('geçemez')
            ->set('body', "## Sınır\n- Yeni kural.")->call('save')->assertSee('Yeni kural.')->assertSee('Son düzenleyen Yakup');
        $this->assertSame("## Sınır\n- Yeni kural.", app(Playbooks::class)->get('meta')['body']);
        $page->call('undo');
        $this->assertSame($shipped, app(Playbooks::class)->get('meta')['body']);

        $page->set('lesson', 'Sıklık 3 üstünde görsel değişir.')->call('addLesson')->assertSee('Sıklık 3 üstünde görsel değişir.');
        $id = app(Playbooks::class)->get('meta')['lessons'][0]['id'];
        $page->call('dropLesson', $id);
        $this->assertSame([], app(Playbooks::class)->get('meta')['lessons']);

        $this->actingAs(User::factory()->create(['is_active' => true]));
        Livewire::test(BooksPage::class)->assertForbidden();
    }
}
