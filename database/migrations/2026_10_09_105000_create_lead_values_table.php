<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Values for the custom fields a vertical defines.
 *
 * Separate from the metadata migration only because it has a foreign key to
 * `leads`, which is created after it.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Values live in typed columns rather than one JSON blob, so a field
        // Brian marks filterable becomes filterable with no migration and no
        // deploy. That is the whole difference between real self-service and a
        // settings screen with a developer standing behind it.
        Schema::create('lead_values', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $t->foreignId('field_definition_id')->constrained()->cascadeOnDelete();
            $t->text('value_text')->nullable();
            $t->decimal('value_num', 20, 4)->nullable();
            $t->date('value_date')->nullable();
            $t->boolean('value_bool')->nullable();

            $t->unique(['lead_id', 'field_definition_id'], 'uk_lead_field');
            // Filtering and sorting by a custom field: field first, then the
            // typed value, so each index serves "this field, in this range".
            $t->index(['field_definition_id', 'value_num'], 'ix_value_num');
            $t->index(['field_definition_id', 'value_date'], 'ix_value_date');
            $t->index(['field_definition_id', 'value_bool'], 'ix_value_bool');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_values');
    }
};
