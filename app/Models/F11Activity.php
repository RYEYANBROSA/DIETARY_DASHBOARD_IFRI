<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One entry in the F11 audit trail - see the f11_activities migration. */
class F11Activity extends Model
{
    protected $fillable = ['user_id', 'action', 'f11_id', 'subject', 'area', 'inserted', 'updated'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
