<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The pipeline stages a rep can set, and how each one renders. */
class PipelineStatus extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['code', 'label', 'chip_class', 'sort_order', 'is_closed'];

    protected function casts(): array
    {
        return ['is_closed' => 'boolean', 'sort_order' => 'integer'];
    }
}
