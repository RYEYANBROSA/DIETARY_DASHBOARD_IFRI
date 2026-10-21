<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One fetch of a survey table from the live server - see App\Support\SurveySync. */
class SurveySyncRun extends Model
{
    protected $table = 'survey_syncs';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['watermark' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
