<?php

namespace App\Panel\Support;

/**
 * The permission catalog of the central roles. Two scopes: what a MoxDOP user may open, and what a brand's user may
 * do on the client panel. Exporting is not in the catalog: only a brand's manager exports.
 */
final class PanelPermissions
{
    public const string SCOPE_MOXDOP = 'moxdop';

    public const string SCOPE_PANEL = 'panel';

    /** @return array<string, string> */
    public static function scopes(): array
    {
        return [self::SCOPE_MOXDOP => 'MoxDOP', self::SCOPE_PANEL => 'Müşteri paneli'];
    }

    /**
     * @return array<string, array<string, string>> group label => [key => label]
     */
    public static function catalog(string $scope): array
    {
        if ($scope === self::SCOPE_MOXDOP) {
            return [
                'Menüler' => [
                    'today' => 'Bugün, Onay kutusu, Takvim',
                    'brands' => 'Markalar ve marka işleri (onarım, içerik, kanallar)',
                    'agency' => 'Moximu (satış, pazarlama, sosyal medya)',
                    'system' => 'Sistem (bağlantılar ve ayarlar)',
                ],
                'Müşteri paneli' => [
                    'client_panel' => 'Markaların panel kullanıcılarını yönetir',
                ],
            ];
        }

        return [
            'Menüler' => [
                'menu.home' => 'Ana sayfa',
                'menu.leads' => 'Adaylar',
                'menu.inbox' => 'Gelen kutusu',
                'menu.performance' => 'Performans',
                'menu.support' => 'Destek',
                'menu.users' => 'Kullanıcılar (kullanıcı ekler, rol verir)',
            ],
            'Adaylar' => [
                'leads.all' => 'Tüm adayları görür (kapalıysa yalnız kendine atananları)',
                'leads.phone' => 'Telefon ve e-postayı açık görür',
                'leads.create' => 'Aday ve çağrı kaydı ekler',
                'leads.edit' => 'Aday bilgilerini düzenler',
                'leads.stage' => 'Aşamayı değiştirir',
                'leads.assign' => 'Adayı bir kullanıcıya atar',
            ],
            'Kayıtlar' => [
                'notes.write' => 'Not yazar',
                'reminders.write' => 'Hatırlatma kurar ve kapatır',
                'files.view' => 'Dosyaları görür',
                'files.upload' => 'Dosya yükler',
            ],
        ];
    }

    /** @return list<string> */
    public static function keys(string $scope): array
    {
        return array_merge(...array_map('array_keys', array_values(self::catalog($scope))));
    }

    /**
     * @param  array<int, mixed>  $permissions
     * @return list<string>
     */
    public static function sanitize(string $scope, array $permissions): array
    {
        return array_values(array_intersect(self::keys($scope), array_map('strval', $permissions)));
    }

    /**
     * The roles every install starts with; an admin edits or adds to them on Ayarlar › Roller.
     *
     * @return list<array{scope: string, name: string, description: string, permissions: list<string>}>
     */
    public static function defaults(): array
    {
        return [
            ['scope' => self::SCOPE_PANEL, 'name' => 'Yönetici', 'description' => 'Markanın sahibi ya da yöneticisi: her şeyi görür, kullanıcı ekler.', 'permissions' => self::keys(self::SCOPE_PANEL)],
            ['scope' => self::SCOPE_PANEL, 'name' => 'Satış', 'description' => 'Adaylarla ilgilenir: arar, not yazar, aşamayı ilerletir.', 'permissions' => ['menu.home', 'menu.leads', 'menu.inbox', 'menu.support', 'leads.all', 'leads.phone', 'leads.create', 'leads.edit', 'leads.stage', 'notes.write', 'reminders.write', 'files.view', 'files.upload']],
            ['scope' => self::SCOPE_PANEL, 'name' => 'İzleyici', 'description' => 'Yalnız görür, değiştirmez; telefonlar gizli.', 'permissions' => ['menu.home', 'menu.leads', 'menu.performance', 'leads.all']],
            ['scope' => self::SCOPE_MOXDOP, 'name' => 'Ekip üyesi', 'description' => 'Marka işlerini yürütür; satış ve sistem ayarları kapalı.', 'permissions' => ['today', 'brands']],
        ];
    }
}
