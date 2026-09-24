<?php

namespace Tests\Feature\Portfolio;

use App\Livewire\Demo\Files\FilesIndex;
use App\Livewire\Operator\Portfolio\BrandShow;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\OperatorFile;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

final class BrandFilesTabTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_from_brand_link_is_attached_and_listed_on_the_brand_files_tab(): void
    {
        Storage::fake('local');
        $this->seed(RoleAndPermissionSeeder::class);
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(Roles::ADMIN);
        $this->actingAs($user);
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Atlas Dental']);
        $other = Brand::factory()->create(['customer_id' => $brand->customer_id]);

        Livewire::withQueryParams(['scope' => 'brand', 'brand' => (string) $brand->id])->test(FilesIndex::class)
            ->assertSet('uploadScope', 'brand')
            ->set('upload', UploadedFile::fake()->create('sozlesme.pdf', 20, 'application/pdf'))
            ->call('uploadFile');
        OperatorFile::factory()->create(['user_id' => $user->id, 'brand_id' => (string) $other->id, 'scope_type' => 'brand', 'original_name' => 'baska.pdf']);

        $file = OperatorFile::query()->where('original_name', 'sozlesme.pdf')->sole();
        $this->assertSame([(string) $brand->id, (string) $brand->id, 'brand'], [$file->brand_id, $file->scope_id, $file->scope_type]);

        Livewire::withQueryParams(['tab' => 'files'])->test(BrandShow::class, ['brand' => (string) $brand->id])
            ->assertSee('Dosyalar')->assertSee('sozlesme.pdf')->assertDontSee('baska.pdf')->assertSee('Dosya yükle');
        $this->get(route('operator.brand.setup', ['brand' => $brand->id]))->assertOk()->assertSee('Açık Web Keşfi');
    }
}
