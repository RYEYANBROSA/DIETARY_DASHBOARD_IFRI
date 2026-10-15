<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One entry in the SWS activity feed - see the sws history migration. */
class SwsActivity extends Model
{
    protected $fillable = ['user_id', 'action', 'sws_id', 'subject', 'area', 'inserted', 'updated'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
