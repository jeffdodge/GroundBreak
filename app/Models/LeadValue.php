<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One custom field value on one lead, stored in a typed column so it can be
 * filtered and sorted without a schema change.
 */
class LeadValue extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'lead_id', 'field_definition_id', 'value_text',
        'value_num', 'value_date', 'value_bool',
    ];

    protected function casts(): array
    {
        return [
            'value_num' => 'decimal:4',
            'value_date' => 'date',
            'value_bool' => 'boolean',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function fieldDefinition(): BelongsTo
    {
        return $this->belongsTo(FieldDefinition::class);
    }

    /** Whichever typed column actually holds this value. */
    public function value(): mixed
    {
        return $this->value_text
            ?? $this->value_num
            ?? $this->value_date
            ?? $this->value_bool;
    }
}
