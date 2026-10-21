<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only pipeline history. Six months from now this is what answers
 * "why did this lead go to Lost, and who decided that?" — a question the
 * published board could not answer at all.
 */
class PipelineEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['lead_id', 'old_status', 'new_status', 'note', 'user_id', 'at'];

    protected function casts(): array
    {
        return ['at' => 'datetime'];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
