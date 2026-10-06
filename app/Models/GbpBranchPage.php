<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The branch page prepared for one Business Profile (ADR-079): AI text from the profile's facts, read and edited by the
 * operator, sent to the brand's WordPress as a draft page with local-business markup (BranchPages).
 */
class GbpBranchPage extends Model
{
    public const string READY = 'ready';

    public const string SENT = 'sent';

    public const string FAILED = 'failed';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['content' => 'array', 'issues' => 'array'];
    }

    /** @return BelongsTo<DigitalAsset, $this> */
    public function digitalAsset(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class);
    }

    /** @return BelongsTo<ExternalWriteAction, $this> */
    public function draftAction(): BelongsTo
    {
        return $this->belongsTo(ExternalWriteAction::class, 'draft_action_id');
    }
}
