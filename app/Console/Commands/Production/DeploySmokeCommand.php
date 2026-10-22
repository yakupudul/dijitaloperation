<?php

namespace App\Console\Commands\Production;

use App\Models\Brand;
use App\Models\User;
use App\Services\Operations\ScreenChecker;
use App\Support\Roles;
use Illuminate\Console\Command;

/**
 * Audit T3/Y4 (2026-10-22): right after a deploy, auto-deploy.sh renders the main screens as an active admin. A screen
 * that answers 500 or throws, or a page without the phone viewport (the layout would not adapt to a phone), fails the
 * check (exit 1) and the release is rolled back to the previous one.
 */
final class DeploySmokeCommand extends Command
{
    protected $signature = 'moxdop:deploy:smoke';

    protected $description = 'Deploy sonrası ana ekranları yönetici olarak açar; bozuk ekran varsa hata verir (otomatik geri dönüş için).';

    /** @var array<string, string> route name => label */
    public const array SCREENS = [
        'operator.dashboard' => 'Komuta', 'operator.today' => 'Bugün özeti', 'operator.portfolio' => 'Portföy', 'operator.repair' => 'Onarım masası', 'operator.brands' => 'Markalar',
        'operator.websites' => 'Web siteleri', 'operator.content' => 'İçerik', 'operator.google-ads-desk' => 'Google Ads', 'operator.flow' => 'Akış',
    ];

    public function handle(ScreenChecker $checker): int
    {
        $admin = User::query()->where('is_active', true)->whereHas('roles', fn ($q) => $q->where('name', Roles::ADMIN))->orderBy('id')->first();
        if ($admin === null) {
            $this->line('Aktif yönetici yok; ekran kontrolü atlandı.');

            return self::SUCCESS;
        }
        config(['moxdop.security.require_admin_2fa' => false]);
        $screens = [];
        foreach (self::SCREENS as $route => $label) {
            if (app('router')->has($route)) {
                $screens[route($route, [], false)] = $label;
            }
        }
        $brand = rescue(fn () => Brand::query()->operational()->orderBy('id')->first(), null, false);
        if ($brand !== null && app('router')->has('operator.brand')) {
            $screens[route('operator.brand', $brand, false)] = 'Marka · '.$brand->name;
        }
        $broken = [];
        foreach ($screens as $path => $label) {
            $check = $checker->check($path, $admin);
            $html = $checker->lastHtml();
            if ($check['status'] >= 500 || $check['error'] !== null) {
                $broken[] = $label.' ('.$check['status'].($check['error'] !== null ? ': '.mb_substr((string) $check['error'], 0, 120) : '').')';
            } elseif ($check['status'] === 200 && ! str_contains($html, 'name="viewport"')) {
                $broken[] = $label.' (telefon görünümü yok)';
            }
        }
        if ($broken !== []) {
            $this->line('Bozuk ekran: '.implode('; ', $broken));

            return self::FAILURE;
        }
        $this->line(count($screens).' ana ekran açıldı.');

        return self::SUCCESS;
    }
}
