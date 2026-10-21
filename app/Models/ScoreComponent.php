<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One input to a vertical's priority score, expressed as data.
 *
 * The published board's project formula was 27 points of base plus five
 * hand-written components — value bands, months to mobilisation, a site-type
 * lookup, contact reachability, and build stage. Expressing that as rows means
 * a new vertical can be scored the day it is created, and a weight can be
 * retuned without a deploy.
 */
class ScoreComponent extends Model
{
    protected $fillable = [
        'vertical_id', 'key', 'label', 'source_kind', 'source_ref',
        'rule_type', 'config', 'max_points', 'sort_order',
    ];

    protected function casts(): array
    {
        return ['config' => 'array', 'max_points' => 'integer', 'sort_order' => 'integer'];
    }

    public function vertical(): BelongsTo
    {
        return $this->belongsTo(Vertical::class);
    }
}
