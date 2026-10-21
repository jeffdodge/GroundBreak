<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The per-tab fields nothing filters on — route_in, notes, security_notes,
 * vendor portals, the "why it scores" paragraph. Kept whole and verbatim so
 * no field from the published board is lost in translation, and kept out of
 * the leads table so a board query does not drag prose along with it.
 */
class LeadDetail extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'lead_id';

    public $incrementing = false;

    protected $fillable = ['lead_id', 'payload'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
