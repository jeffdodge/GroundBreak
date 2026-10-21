<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a lead stands with us: one status and one running note per lead.
 *
 * Deliberately separate from the lead itself, because the lead is refreshed
 * from the outside world every day and this is the part that is ours. The
 * overnight job rewrites leads freely and never touches this table.
 */
class Pipeline extends Model
{
    protected $primaryKey = 'lead_id';

    public $incrementing = false;

    protected $fillable = ['lead_id', 'status', 'note', 'updated_by'];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(PipelineStatus::class, 'status', 'code');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
