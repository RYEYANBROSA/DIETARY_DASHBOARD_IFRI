<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One change to one SWS record - see the sws history migration. */
class SwsChange extends Model
{
    protected $fillable = ['sws_id', 'user_id', 'action', 'source', 'sws_activity_id', 'changes', 'member'];

    protected $casts = ['changes' => 'array'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The upload this change came from, when source is "upload". */
    public function upload(): BelongsTo
    {
        return $this->belongsTo(SwsActivity::class, 'sws_activity_id');
    }
}
