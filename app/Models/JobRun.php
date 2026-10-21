<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One run of one scheduled job.
 *
 * This table exists because of a specific failure: the SAM.gov pull silently
 * did not happen on 9 October 2026 and nothing in the system recorded that.
 * Every run now leaves a row whether it succeeds or not, so "did the feed run
 * this morning?" is a query rather than a guess.
 */
class JobRun extends Model
{
    public $timestamps = false;

    protected $fillable = ['job', 'status', 'started_at', 'finished_at', 'stats', 'error', 'cost_usd'];

    protected function casts(): array
    {
        return [
            'stats' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'cost_usd' => 'decimal:4',
        ];
    }
}
