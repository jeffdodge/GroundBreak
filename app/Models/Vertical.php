<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One kind of business we sell security to — construction projects, data
 * centres, retail headquarters, or whatever gets added next.
 *
 * Everything that used to be hardcoded per tab lives on this row: which source
 * field holds the name, how the lead is scored, and what the nightly research
 * job should go looking for. Adding a line of business is inserting one of
 * these and a handful of field definitions, not a deploy.
 */
class Vertical extends Model
{
    protected $fillable = [
        'key', 'label', 'label_singular', 'key_prefix', 'intro', 'sort_order',
        'is_active', 'name_field', 'city_field', 'state_field',
        'research_terms', 'research_sources', 'research_rules',
        'research_exclusions', 'research_cadence', 'score_base', 'tier_cuts',
        'source_map',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'score_base' => 'integer',
            'tier_cuts' => 'array',
            'source_map' => 'array',
        ];
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function fieldDefinitions(): HasMany
    {
        return $this->hasMany(FieldDefinition::class)->orderBy('sort_order');
    }

    public function scoreComponents(): HasMany
    {
        return $this->hasMany(ScoreComponent::class)->orderBy('sort_order');
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class);
    }

    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }

    /** Verticals the nightly research job should visit today. */
    #[Scope]
    protected function dueForResearch(Builder $query, string $weekday): Builder
    {
        return $query->active()
            ->whereIn('research_cadence', ['daily', strtolower($weekday)]);
    }

    /**
     * The A/B/C/D cuts, defaulting to the thresholds the published board used.
     *
     * @return array{A:int,B:int,C:int}
     */
    public function tierCuts(): array
    {
        return $this->tier_cuts ?? ['A' => 68, 'B' => 55, 'C' => 42];
    }

    public function tierFor(int $score): string
    {
        $cuts = $this->tierCuts();

        return match (true) {
            $score >= $cuts['A'] => 'A',
            $score >= $cuts['B'] => 'B',
            $score >= $cuts['C'] => 'C',
            default => 'D',
        };
    }
}
