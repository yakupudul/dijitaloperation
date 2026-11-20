<?php

namespace App\Panel\Models;

use App\Panel\Support\BelongsToBrand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One time a person asked for something (a form, a call, a message). A person may have many. */
class PanelRequest extends Model
{
    use BelongsToBrand;

    protected $fillable = ['person_id', 'source', 'interest', 'message', 'meta', 'received_at'];

    protected function casts(): array
    {
        return ['message' => 'encrypted', 'meta' => 'array', 'received_at' => 'datetime'];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(PanelPerson::class, 'person_id');
    }
}
