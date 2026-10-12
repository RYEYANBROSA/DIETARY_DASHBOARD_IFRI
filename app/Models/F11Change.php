<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One change to one F11 member - see the f11_changes migration. */
class F11Change extends Model
{
    protected $fillable = ['f11_id', 'user_id', 'action', 'source', 'f11_activity_id', 'changes', 'member'];

    protected $casts = ['changes' => 'array'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The upload this change came from, when source is "upload". */
    public function upload(): BelongsTo
    {
        return $this->belongsTo(F11Activity::class, 'f11_activity_id');
    }
}
