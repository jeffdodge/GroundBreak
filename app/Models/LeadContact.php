<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A way to reach a lead. Three shapes on the published board collapse into
 * this one table: the flat contact_* fields on projects, the operator-level
 * switchboards on data centres, and the contact arrays on retail, events and
 * student travel.
 *
 * `confidence` carries the board's own words about why an address is believed,
 * and it is never dropped — distinguishing a published address from a pattern
 * guess is the point of the whole contact layer.
 */
class LeadContact extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'lead_id', 'name', 'title', 'email', 'phone', 'confidence',
        'audit', 'is_mailbox', 'source_url', 'sort_order',
    ];

    protected function casts(): array
    {
        return ['is_mailbox' => 'boolean', 'sort_order' => 'integer'];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** A named human, as opposed to a department mailbox or a switchboard. */
    #[Scope]
    protected function namedPerson(Builder $query): Builder
    {
        return $query->where('name', '<>', '');
    }
}
