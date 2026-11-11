<?php

namespace App\Models;

use App\Services\Operations\SettingChanges;
use App\Support\Operator\AgencySettingCatalog;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'agency_name',
    'portal_name',
    'locale',
    'timezone',
    'display_currency',
    'week_starts_on',
    'analytical_date_range',
    'logo_path',
    'favicon_path',
    'mail_enabled',
    'mail_from_name',
    'mail_from_address',
    'mail_host',
    'mail_port',
    'mail_username',
    'mail_encryption',
    'mail_password',
    'push_ntfy_url',
    'push_ntfy_token',
    'push_telegram_bot_token',
    'push_telegram_chat_id',
    'push_min_severity',
    'away_until',
    'bing_webmaster_api_key',
    'theme_accent',
    'theme_second',
    'backup_remote_url',
    'backup_remote_username',
    'backup_remote_password',
])]
#[Hidden([
    'social_meta_app_secret',
    'ai_openai_admin_key',
    'mail_password',
    'push_ntfy_token',
    'push_telegram_bot_token',
    'bing_webmaster_api_key',
    'backup_remote_password',
])]
class AgencySetting extends Model
{
    protected static function booted(): void
    {
        // A row saved without a language takes the installation's (the column's own default is a blind 'en').
        // Ayar değişiklik kaydı: every changed column is written down with its value before (SettingChanges).
        static::updating(fn (self $model) => SettingChanges::agencySaving($model));
        static::creating(function (self $model): void {
            if (! filled($model->locale)) {
                $model->locale = AgencySettingCatalog::appDefaultLocale();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'agency_name' => 'string',
            'portal_name' => 'string',
            'locale' => 'string',
            'timezone' => 'string',
            'display_currency' => 'string',
            'week_starts_on' => 'string',
            'analytical_date_range' => 'string',
            'mail_enabled' => 'boolean',
            'away_until' => 'date',
            'mail_port' => 'integer',
            'mail_password' => 'encrypted',
            'push_ntfy_token' => 'encrypted',
            'lead_form_token' => 'encrypted',
            'push_telegram_bot_token' => 'encrypted',
            'bing_webmaster_api_key' => 'encrypted',
            'backup_remote_password' => 'encrypted',
            'social_meta_app_secret' => 'encrypted',
            'ai_openai_free_quota' => 'boolean',
            'ai_openai_admin_key' => 'encrypted',
            'ai_workers' => 'array',
            'google_workspace' => 'array',
            'ai_openai_audit' => 'array',
            'lead_kinds' => 'array',
            'marketing_targets' => 'array',
        ];
    }
}
