<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * State licensing rules for guard companies and for CCTV/alarm installation.
 * Reference data, not leads: these rows answer "may we legally work here"
 * rather than "should we call them", so they carry no pipeline.
 */
class Licensing extends Model
{
    protected $fillable = [
        'kind', 'state', 'state_name', 'required', 'agency',
        'lookup_url', 'apply_url', 'payload',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
