<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The build-stage ladder, and the reason a facility cannot move backwards.
 *
 * A site goes Announced -> Under Construction -> Operational and never the
 * other way: a live building does not un-build itself, and a secondary source
 * claiming otherwise is wrong. Cancelled/Withdrawn sits outside the ladder
 * entirely (order 99, is_dead) because a cancelled proposal is not "further
 * along" than an announced one — it is set explicitly, never by comparison.
 */
class StageRank extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'stage';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['stage', 'stage_order', 'is_dead'];

    protected function casts(): array
    {
        return ['stage_order' => 'integer', 'is_dead' => 'boolean'];
    }

    /**
     * Whether a feed may move a lead from one stage to another.
     *
     * Returns false for any backwards move and for any move INTO a terminal
     * stage, so cancelling a site is always a deliberate act rather than
     * something a scraper can do by accident.
     */
    public static function allowsMove(string $from, string $to): bool
    {
        $ranks = static::pluck('stage_order', 'stage');

        if (! $ranks->has($from) || ! $ranks->has($to)) {
            return false;
        }

        $dead = static::where('is_dead', true)->pluck('stage')->all();

        if (in_array($to, $dead, true)) {
            return false;
        }

        return $ranks[$to] > $ranks[$from];
    }
}
