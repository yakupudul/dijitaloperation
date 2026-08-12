<?php

namespace App\Support;

/**
 * MoxDOP operator (TailAdmin) sidebar menu definition.
 *
 * Portfolio pages live in the TailAdmin operator app; Operations and deep CRUD
 * link out to the Filament back-office at /admin.
 */
final class OperatorMenu
{
    /**
     * @return list<array{title: string, items: list<array<string, mixed>>}>
     */
    public static function groups(): array
    {
        return [
            [
                'title' => 'Menu',
                'items' => [
                    ['name' => 'Dashboard', 'icon' => 'grid', 'path' => '/app'],
                ],
            ],
            [
                'title' => 'Portfolio',
                'items' => [
                    ['name' => 'Customers', 'icon' => 'users', 'path' => '/app/customers'],
                    ['name' => 'Brands', 'icon' => 'box', 'path' => '/app/brands'],
                    ['name' => 'Digital Assets', 'icon' => 'globe', 'path' => '/app/digital-assets'],
                ],
            ],
            [
                'title' => 'Operations',
                'items' => [
                    ['name' => 'Activity', 'icon' => 'pulse', 'path' => '/admin/runs', 'external' => true],
                    ['name' => 'Findings', 'icon' => 'flag', 'path' => '/admin/findings', 'external' => true],
                    ['name' => 'Recommendations', 'icon' => 'lightbulb', 'path' => '/admin/recommendations', 'external' => true],
                    ['name' => 'Tasks', 'icon' => 'check', 'path' => '/admin/tasks', 'external' => true],
                ],
            ],
            [
                'title' => 'System',
                'items' => [
                    ['name' => 'Meta Integration', 'icon' => 'plug', 'path' => '/app/meta'],
                    ['name' => 'Settings / Admin', 'icon' => 'settings', 'path' => '/admin', 'external' => true],
                ],
            ],
        ];
    }

    /**
     * Minimal inline SVG icon set (24x24, currentColor) adapted for the operator menu.
     */
    public static function icon(string $key): string
    {
        $icons = [
            'grid' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
            'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
            'box' => '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="M3.27 6.96 12 12.01l8.73-5.05"/><path d="M12 22.08V12"/>',
            'globe' => '<circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
            'pulse' => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
            'flag' => '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/>',
            'lightbulb' => '<path d="M9 18h6"/><path d="M10 22h4"/><path d="M15.09 14c.18-.98.65-1.74 1.41-2.5A4.65 4.65 0 0 0 18 8 6 6 0 0 0 6 8c0 1 .23 2.23 1.5 3.5.76.76 1.23 1.52 1.41 2.5"/>',
            'check' => '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
            'plug' => '<path d="M12 22v-5"/><path d="M9 8V2"/><path d="M15 8V2"/><path d="M18 8v3a6 6 0 0 1-6 6 6 6 0 0 1-6-6V8z"/>',
            'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
        ];

        $inner = $icons[$key] ?? $icons['grid'];

        return '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg">'.$inner.'</svg>';
    }
}
