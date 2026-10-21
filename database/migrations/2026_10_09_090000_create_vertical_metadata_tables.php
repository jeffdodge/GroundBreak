<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The metadata layer: what makes a new line of business something Brian can
 * add from a form rather than something that needs a developer.
 *
 * The first cut of this schema had the eight lead types as a MySQL ENUM on
 * leads.tab, with six PHP constant maps that knew each one by name. Adding
 * "marinas" meant a migration and a deploy. These tables move all of that into
 * data: a vertical, its fields, how it is scored, and what the nightly
 * research job should go looking for.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------------
        // A vertical: one kind of business we sell security to. Was a tab.
        // ------------------------------------------------------------------
        Schema::create('verticals', function (Blueprint $t) {
            $t->id();
            $t->string('key', 40)->unique();            // 'projects', 'marinas'
            $t->string('label', 60);                    // "Data Centers"
            $t->string('label_singular', 60)->default('');
            /** Prefix for every lead_key on this vertical. Immutable once
             *  leads exist: it is half of a lead's identity. */
            $t->string('key_prefix', 12)->unique();
            $t->text('intro')->nullable();              // the line above the board
            $t->unsignedSmallInteger('sort_order')->default(100);
            $t->boolean('is_active')->default(true);

            // Which source field supplies the lead's name, city and state when
            // a feed or an import hands over a raw row. Was hardcoded per tab.
            $t->string('name_field', 60)->default('name');
            $t->string('city_field', 60)->default('city');
            $t->string('state_field', 60)->default('state');

            /**
             * Which incoming field feeds each shared column, as
             * {column: source_field}. Anything an import supplies that is NOT
             * listed here becomes a custom field definition automatically, so
             * a new vertical's fields appear without anyone enumerating them
             * in code.
             *
             * @var array<string,string>
             */
            $t->json('source_map')->nullable();

            // The research brief, in Brian's words. Goes straight into the
            // nightly Claude prompt, which is what makes a new vertical start
            // filling itself rather than sitting empty.
            $t->text('research_terms')->nullable();     // phrases that find these
            $t->text('research_sources')->nullable();   // sites worth reading
            $t->text('research_rules')->nullable();     // what qualifies as a lead
            $t->text('research_exclusions')->nullable();
            /** blank = not researched automatically; 'daily'; or a weekday for
             *  the rotating directory passes. */
            $t->string('research_cadence', 20)->default('');

            // Scoring. A base every live lead starts from, and the tier cuts.
            $t->unsignedSmallInteger('score_base')->default(0);
            /** @var array{A:int,B:int,C:int} */
            $t->json('tier_cuts')->nullable();
            $t->timestamps();
        });

        // ------------------------------------------------------------------
        // Custom fields, per vertical
        // ------------------------------------------------------------------
        Schema::create('field_definitions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vertical_id')->constrained()->cascadeOnDelete();
            $t->string('key', 60);
            $t->string('label', 120);
            $t->enum('type', ['text', 'longtext', 'number', 'money', 'date',
                'url', 'email', 'phone', 'select', 'boolean'])->default('text');
            $t->text('help')->nullable();               // shown under the input
            $t->boolean('is_in_list')->default(false);  // a column on the row
            $t->boolean('is_filterable')->default(false);
            $t->boolean('is_required')->default(false);
            /** @var list<string> */
            $t->json('options')->nullable();            // for type=select
            $t->unsignedSmallInteger('sort_order')->default(100);
            $t->timestamps();

            $t->unique(['vertical_id', 'key']);
            $t->index(['vertical_id', 'sort_order']);
        });

        // ------------------------------------------------------------------
        // Services we sell. Previously a free-text field that had drifted to
        // 197 distinct spellings of three services, including 184 one-offs
        // and a literal em dash.
        // ------------------------------------------------------------------
        Schema::create('services', function (Blueprint $t) {
            $t->id();
            $t->string('key', 40)->unique();
            $t->string('label', 80);
            $t->text('description')->nullable();
            $t->boolean('is_active')->default(true);
            $t->unsignedSmallInteger('sort_order')->default(100);
            $t->timestamps();
        });

        // Which services are worth offering on which vertical, so a new
        // vertical can say "guarding and cameras, not overnight hotel posts".
        Schema::create('service_vertical', function (Blueprint $t) {
            $t->foreignId('service_id')->constrained()->cascadeOnDelete();
            $t->foreignId('vertical_id')->constrained()->cascadeOnDelete();
            $t->primary(['service_id', 'vertical_id']);
        });

        // ------------------------------------------------------------------
        // Scoring, as editable data
        // ------------------------------------------------------------------
        // One component of a vertical's score. Together with verticals.score_base
        // and verticals.tier_cuts these reproduce the formulas that were
        // previously written by hand in Python, and let a new vertical be
        // scored the day it is created.
        Schema::create('score_components', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vertical_id')->constrained()->cascadeOnDelete();
            $t->string('key', 40);
            $t->string('label', 120);

            /** Where the input comes from: a column on leads, a custom field,
             *  or something computed (how reachable the contacts are). */
            $t->enum('source_kind', ['column', 'field', 'computed'])->default('column');
            $t->string('source_ref', 60);     // column name, field key, or computed key

            /** How the input becomes points.
             *  bands    — numeric thresholds, highest match wins
             *  map      — exact value to points
             *  presence — points when the value is filled
             *  months   — months from today, for a start date */
            $t->enum('rule_type', ['bands', 'map', 'presence', 'months'])->default('map');
            /** @var array<string,mixed> */
            $t->json('config');
            $t->unsignedSmallInteger('max_points')->default(0);
            $t->unsignedSmallInteger('sort_order')->default(100);
            $t->timestamps();

            $t->unique(['vertical_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('score_components');
        Schema::dropIfExists('service_vertical');
        Schema::dropIfExists('services');
        Schema::dropIfExists('field_definitions');
        Schema::dropIfExists('verticals');
    }
};
