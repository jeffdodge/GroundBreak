<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A backwards stage claim that was refused. A findings log, not an error log:
 * one facility collecting several refusals means its sources disagree and a
 * person should look at it.
 */
class StageRefusal extends Model
{
    public $timestamps = false;

    protected $fillable = ['lead_id', 'from_stage', 'to_stage', 'source_url', 'job_run_id', 'at'];

    protected function casts(): array
    {
        return ['at' => 'datetime'];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function jobRun(): BelongsTo
    {
        return $this->belongsTo(JobRun::class);
    }
}
