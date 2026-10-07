<?php

namespace App\Livewire\Operator\Portfolio;

use App\Models\BrandExpert;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Marka › Ayarlar › Uzmanlar: the brand's experts and which one articles are published under ("Yazar"). The WordPress
 * user name or e-mail makes the draft go out under that author; the site's SEO plugin then prints their Person schema.
 */
final class BrandExperts extends Component
{
    #[Locked]
    public int $brandId;

    public string $name = '';

    public string $title = '';

    public string $wpAuthor = '';

    public string $profileUrl = '';

    public function mount(int $brandId): void
    {
        $this->brandId = $brandId;
    }

    public function add(): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
        $data = $this->validate([
            'name' => ['required', 'string', 'max:160'],
            'title' => ['nullable', 'string', 'max:160'],
            'wpAuthor' => ['nullable', 'string', 'max:160'],
            'profileUrl' => ['nullable', 'url', 'max:500'],
        ], [], ['name' => 'ad', 'title' => 'unvan', 'wpAuthor' => 'WordPress kullanıcısı', 'profileUrl' => 'profil sayfası']);
        $first = ! BrandExpert::query()->where('brand_id', $this->brandId)->exists();
        BrandExpert::query()->create(['brand_id' => $this->brandId, 'name' => trim($data['name']), 'title' => trim((string) $data['title']) ?: null,
            'wp_author' => trim((string) $data['wpAuthor']) ?: null, 'profile_url' => trim((string) $data['profileUrl']) ?: null, 'is_default' => $first]);
        $this->reset('name', 'title', 'wpAuthor', 'profileUrl');
    }

    public function makeDefault(int $id): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
        $expert = BrandExpert::query()->where('brand_id', $this->brandId)->findOrFail($id);
        BrandExpert::query()->where('brand_id', $this->brandId)->update(['is_default' => false]);
        $expert->forceFill(['is_default' => true, 'source' => 'operator'])->save();
    }

    public function remove(int $id): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
        BrandExpert::query()->where('brand_id', $this->brandId)->whereKey($id)->delete();
    }

    public function render(): View
    {
        return view('livewire.operator.portfolio.brand-experts', [
            'experts' => BrandExpert::query()->where('brand_id', $this->brandId)->orderByDesc('is_default')->orderBy('name')->get(),
            'isAdmin' => (bool) auth()->user()?->hasRole(Roles::ADMIN),
        ]);
    }
}
