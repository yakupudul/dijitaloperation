<?php

namespace App\Panel\Models;

use App\Models\Brand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** The panel switch of a brand and the secret address its site form posts to. */
class PanelBrandSetting extends Model
{
    /** What the agency can switch off for one brand: key => [label, the permissions it closes for everyone there]. */
    public const array MODULES = [
        'inbox' => ['Gelen kutusu', ['menu.inbox', 'inbox.web_form', 'inbox.call', 'inbox.whatsapp', 'inbox.instagram', 'inbox.meta_form']],
        'performance' => ['Performans', ['menu.performance', 'performance.team']],
        'import' => ['Excel\'den yükleme', ['import.leads']],
        'users' => ['Kendi kullanıcılarını yönetme', ['menu.users']],
    ];

    /** Closed for everyone while a brand is read only (a brand that is leaving still sees and exports its records). */
    public const array WRITES = ['leads.create', 'leads.edit', 'leads.stage', 'leads.assign', 'notes.write', 'reminders.write', 'files.upload', 'import.leads'];

    protected $fillable = ['brand_id', 'is_enabled', 'form_token', 'modules', 'is_read_only'];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'modules' => 'array', 'is_read_only' => 'boolean', 'leaving_at' => 'datetime'];
    }

    public function moduleOn(string $module): bool
    {
        return (bool) data_get($this->modules, $module, true);
    }

    /** Whether the brand as a whole may use a permission, before any user's role is asked. */
    public function permits(string $permission): bool
    {
        if ($this->is_read_only && in_array($permission, self::WRITES, true)) {
            return false;
        }
        foreach (self::MODULES as $module => [, $permissions]) {
            if (in_array($permission, $permissions, true) && ! $this->moduleOn($module)) {
                return false;
            }
        }

        return true;
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public static function forBrand(int $brandId): self
    {
        return self::query()->firstOrCreate(['brand_id' => $brandId], ['is_enabled' => true, 'form_token' => Str::random(48)]);
    }
}
