<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A federal solicitation as SAM.gov published it, stored before any filtering.
 *
 * Keeping the raw notice makes the pull idempotent, lets a filter change be
 * re-applied to data already fetched, and leaves a trail when a deadline or a
 * contracting officer changes underneath us. `rejected_reason` records why a
 * notice did not become a lead, because a notice dropped for "deadline
 * passed" is information rather than noise.
 */
class SamNotice extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'notice_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'raw' => 'array',
            'posted_date' => 'date',
            'response_deadline' => 'date',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
