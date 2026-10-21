<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;

/**
 * One row on the board, on any of the eight lead tabs.
 *
 * `lead_key` is the stable identity and the thing a pipeline note hangs off.
 * It is derived from the tab, the state and the normalised name, so a lead
 * keeps its status when it climbs or falls the rankings. Treat it as
 * immutable once written: changing it silently reassigns somebody's note to
 * a different lead.
 */
class Lead extends Model
{
    protected $fillable = [
        'vertical_id', 'lead_key', 'name', 'city', 'state', 'score', 'tier', 'tier_label',
        'stage', 'category', 'subtype', 'org', 'owner_org',
        'value_usd', 'size_sqft', 'power_mw', 'rfp_deadline',
        'source_type', 'source_url', 'flags', 'is_active',
        'service_note', 'first_seen_at', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'value_usd' => 'integer',
            'size_sqft' => 'integer',
            'power_mw' => 'decimal:2',
            'rfp_deadline' => 'date',
            'is_active' => 'boolean',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function vertical(): BelongsTo
    {
        return $this->belongsTo(Vertical::class);
    }

    public function detail(): HasOne
    {
        return $this->hasOne(LeadDetail::class);
    }

    public function values(): HasMany
    {
        return $this->hasMany(LeadValue::class);
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class)->withPivot('is_confirmed');
    }

    /**
     * Read one custom field by its key. Loads the lead's values once and
     * keeps them, so scoring a lead does not fire a query per component.
     */
    public function customValue(string $key): mixed
    {
        $this->customValues ??= $this->values()
            ->with('fieldDefinition')
            ->get()
            ->mapWithKeys(fn (LeadValue $v): array => [$v->fieldDefinition->key => $v->value()]);

        return $this->customValues[$key] ?? null;
    }

    /** @var Collection<string, mixed>|null */
    private $customValues = null;

    public function contacts(): HasMany
    {
        return $this->hasMany(LeadContact::class)->orderBy('sort_order');
    }

    public function pipeline(): HasOne
    {
        return $this->hasOne(Pipeline::class);
    }

    public function pipelineEvents(): HasMany
    {
        return $this->hasMany(PipelineEvent::class)->latest('at');
    }

    public function stageRefusals(): HasMany
    {
        return $this->hasMany(StageRefusal::class)->latest('at');
    }

    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    #[Scope]
    protected function onVertical(Builder $query, string $key): Builder
    {
        return $query->whereHas('vertical', fn (Builder $q) => $q->where('key', $key));
    }

    /**
     * Filter by a custom field without a schema change — the payoff for
     * storing values in typed, indexed columns rather than a JSON blob.
     */
    #[Scope]
    protected function whereCustom(Builder $query, string $key, string $operator, mixed $value): Builder
    {
        return $query->whereHas('values', function (Builder $q) use ($key, $operator, $value): void {
            $q->whereHas('fieldDefinition', fn (Builder $f) => $f->where('key', $key))
                ->where(function (Builder $w) use ($operator, $value): void {
                    $w->where('value_text', $operator, $value)
                        ->orWhere('value_num', $operator, $value)
                        ->orWhere('value_date', $operator, $value);
                });
        });
    }

    /**
     * Open solicitations with a live response deadline. The Bids tab was never
     * its own dataset — it is this query.
     */
    #[Scope]
    protected function openBids(Builder $query): Builder
    {
        return $query->active()
            ->whereNotNull('rfp_deadline')
            ->whereDate('rfp_deadline', '>=', now()->toDateString());
    }

    /**
     * Leads carrying at least one published email address. Cheap now that
     * contacts are their own table; on the old board this was a full scan of
     * a 5.7MB blob in the browser.
     */
    #[Scope]
    protected function withPublishedEmail(Builder $query): Builder
    {
        return $query->whereHas('contacts', fn (Builder $q) => $q->where('email', '<>', ''));
    }

    /**
     * A bid closing this week outranks a better-scoring lead that can wait,
     * because a missed deadline cannot be recovered and a late call can.
     * Mirrors the published board's boost exactly: 40/30/20/10 points at
     * 7/14/30/more days out.
     */
    public function deadlineBoost(): int
    {
        if ($this->rfp_deadline === null) {
            return 0;
        }

        $days = now()->startOfDay()->diffInDays($this->rfp_deadline, false);

        return match (true) {
            $days < 0 => 0,
            $days <= 7 => 40,
            $days <= 14 => 30,
            $days <= 30 => 20,
            default => 10,
        };
    }

    public function sortScore(): int
    {
        return (int) $this->score + $this->deadlineBoost();
    }
}
