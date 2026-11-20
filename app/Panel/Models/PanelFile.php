<?php

namespace App\Panel\Models;

use App\Panel\Support\BelongsToBrand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A file attached to a person; kept on the private disk and served only through the brand-checked download. */
class PanelFile extends Model
{
    use BelongsToBrand;

    protected $fillable = ['person_id', 'panel_user_id', 'name', 'path', 'mime', 'size'];

    public function person(): BelongsTo
    {
        return $this->belongsTo(PanelPerson::class, 'person_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(PanelUser::class, 'panel_user_id');
    }
}
