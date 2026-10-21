<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The replay log behind the live board.
 *
 * Reverb pushes changes to open browsers, but a websocket that drops loses
 * whatever it missed. A reconnecting client asks for everything after the
 * last `id` it saw and catches up exactly, which is the difference between a
 * board that looks live and one that is actually correct after a train tunnel.
 */
class ChangeLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['entity', 'entity_id', 'lead_key', 'op', 'actor', 'at', 'detail'];

    protected function casts(): array
    {
        return ['detail' => 'array', 'at' => 'datetime'];
    }

    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor');
    }
}
