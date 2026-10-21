<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A custom field on a vertical. Defining one of these is how a new column
 * appears on the board without a migration.
 */
class FieldDefinition extends Model
{
    public const TYPES = ['text', 'longtext', 'number', 'money', 'date',
        'url', 'email', 'phone', 'select', 'boolean'];

    protected $fillable = [
        'vertical_id', 'key', 'label', 'type', 'help', 'is_in_list',
        'is_filterable', 'is_required', 'options', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_in_list' => 'boolean',
            'is_filterable' => 'boolean',
            'is_required' => 'boolean',
            'options' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function vertical(): BelongsTo
    {
        return $this->belongsTo(Vertical::class);
    }

    public function values(): HasMany
    {
        return $this->hasMany(LeadValue::class);
    }

    #[Scope]
    protected function filterable(Builder $query): Builder
    {
        return $query->where('is_filterable', true);
    }

    #[Scope]
    protected function inList(Builder $query): Builder
    {
        return $query->where('is_in_list', true);
    }

    /** Which lead_values column holds this field's value. */
    public function valueColumn(): string
    {
        return match ($this->type) {
            'number', 'money' => 'value_num',
            'date' => 'value_date',
            'boolean' => 'value_bool',
            default => 'value_text',
        };
    }
}
