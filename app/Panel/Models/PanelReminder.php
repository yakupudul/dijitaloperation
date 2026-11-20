<?php

namespace App\Panel\Models;

use App\Panel\Support\BelongsToBrand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** "Call back on Thursday": a dated job about a person, for one user of the brand. */
class PanelReminder extends Model
{
    use BelongsToBrand;

    protected $fillable = ['person_id', 'panel_user_id', 'created_by', 'title', 'due_at', 'done_at'];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'done_at' => 'datetime'];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(PanelPerson::class, 'person_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(PanelUser::class, 'panel_user_id');
    }
}
