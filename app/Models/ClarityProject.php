<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A website's Microsoft Clarity project: the Data Export API token (encrypted, never shown again) and the last pull.
 *
 * @property int $website_asset_id
 * @property ?string $project_id
 * @property string $api_token
 * @property bool $enabled
 */
class ClarityProject extends Model
{
    protected $fillable = ['website_asset_id', 'project_id', 'api_token', 'enabled', 'last_pulled_at', 'last_status', 'last_error'];

    protected $hidden = ['api_token'];

    protected function casts(): array
    {
        return [
            'api_token' => 'encrypted',
            'enabled' => 'boolean',
            'last_pulled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<DigitalAsset, $this> */
    public function website(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class, 'website_asset_id');
    }

    public function dashboardUrl(): ?string
    {
        return filled($this->project_id) ? 'https://clarity.microsoft.com/projects/view/'.rawurlencode((string) $this->project_id).'/dashboard' : null;
    }
}
