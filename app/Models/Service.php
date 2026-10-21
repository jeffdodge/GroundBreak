<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Something we sell: local guarding, national guarding, remote video.
 *
 * A managed list, because the free-text field it replaces had drifted into 197
 * distinct values for three services. Adding a fourth service is a row here.
 */
class Service extends Model
{
    protected $fillable = ['key', 'label', 'description', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function leads(): BelongsToMany
    {
        return $this->belongsToMany(Lead::class)->withPivot('is_confirmed');
    }

    public function verticals(): BelongsToMany
    {
        return $this->belongsToMany(Vertical::class);
    }

    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }
}
