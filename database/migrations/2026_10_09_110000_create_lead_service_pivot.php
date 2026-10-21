<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which services a lead is a candidate for.
 *
 * Replaces a free-text column that had drifted into 197 distinct spellings of
 * three services — "NWSS + Video Guard Pro", "NWSS (national)", "NESA / NWSS",
 * an em dash on 49 rows, and 184 one-off sentences. A lead now points at
 * service rows, so "every Video Guard Pro lead" is a join rather than a
 * substring search over prose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_service', function (Blueprint $t) {
            $t->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $t->foreignId('service_id')->constrained()->cascadeOnDelete();
            /** True when a human chose this, false when the importer or the
             *  research job inferred it. Lets a rep's judgement outrank a
             *  feed's guess. */
            $t->boolean('is_confirmed')->default(false);
            $t->primary(['lead_id', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_service');
    }
};
