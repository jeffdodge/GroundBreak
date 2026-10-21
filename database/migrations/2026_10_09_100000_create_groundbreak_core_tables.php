<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Groundbreak — the lead board itself.
 *
 * Ported from a schema that was applied and loaded against a real MySQL-family
 * server before it got here, which is how two reserved-word bugs were caught:
 * the main table is `leads` (not `lead`) and the stage column is `stage_order`
 * (not `rank`), because LEAD() and RANK() are reserved window functions in
 * MySQL 8 and the DDL will not parse otherwise.
 *
 * The shape is a deliberate hybrid. Fields the board sorts, filters and
 * searches on are real indexed columns; the long tail of per-tab prose
 * (route_in, notes, security_notes, vendor portals) lives in a JSON payload on
 * lead_details. The old board kept all ten tabs as one 5.7MB JSON blob, which
 * meant every filter was a full scan in the browser.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------------
        // Build stages, ranked. Enforcing forward-only movement in the
        // database rather than in a prompt: a facility goes
        // Announced -> Under Construction -> Operational and never back.
        // ------------------------------------------------------------------
        Schema::create('stage_ranks', function (Blueprint $t) {
            $t->string('stage', 40)->primary();
            $t->unsignedTinyInteger('stage_order');
            $t->boolean('is_dead')->default(false);
        });

        DB::table('stage_ranks')->insert([
            ['stage' => '',                    'stage_order' => 0,  'is_dead' => false],
            ['stage' => 'Announced',           'stage_order' => 1,  'is_dead' => false],
            ['stage' => 'Permitting',          'stage_order' => 2,  'is_dead' => false],
            ['stage' => 'Bidding',             'stage_order' => 3,  'is_dead' => false],
            ['stage' => 'Awarded',             'stage_order' => 4,  'is_dead' => false],
            ['stage' => 'Under Construction',  'stage_order' => 5,  'is_dead' => false],
            ['stage' => 'Operational',         'stage_order' => 6,  'is_dead' => false],
            // Terminal, and deliberately OUTSIDE the ladder: a cancelled
            // proposal is not "further along" than an announced one. Set
            // explicitly, never as the result of a rank comparison — and an
            // Operational site is never cancelled, only a proposal or an
            // expansion.
            ['stage' => 'Cancelled/Withdrawn', 'stage_order' => 99, 'is_dead' => true],
        ]);

        // ------------------------------------------------------------------
        // The lead spine
        // ------------------------------------------------------------------
        Schema::create('leads', function (Blueprint $t) {
            $t->id();
            // Was an ENUM of eight tab names, which meant adding a line of
            // business required a migration. Now a row in `verticals`.
            $t->foreignId('vertical_id')->constrained()->cascadeOnDelete();

            // Stable identity, carried over verbatim from the published board
            // so pipeline statuses set there migrate onto the right rows:
            //   <prefix>_<STATE>_<name lowercased, non-alphanumerics stripped>
            $t->string('lead_key', 120)->unique();

            $t->string('name');
            $t->string('city', 120)->default('');
            $t->char('state', 2)->default('');

            $t->unsignedSmallInteger('score')->nullable();
            $t->char('tier', 1)->nullable();
            $t->string('tier_label', 40)->default('');   // "A — call this week"

            $t->string('stage', 40)->default('');        // projects/datacenters status
            $t->string('category', 255)->default('');    // project_type / facility_type / segment
            $t->string('subtype', 120)->default('');     // retail record_type
            $t->string('org', 500)->default('');         // general_contractor / operator
            $t->string('owner_org')->default('');        // owner_developer / parent
            /** The original free-text service line, kept verbatim because on
             *  184 rows it explains which brand covers which location. The
             *  actual services are rows in `services`, joined through
             *  `lead_service`. */
            $t->text('service_note')->nullable();

            $t->unsignedBigInteger('value_usd')->nullable();
            $t->unsignedBigInteger('size_sqft')->nullable();
            $t->decimal('power_mw', 10, 2)->nullable();
            $t->date('rfp_deadline')->nullable();

            $t->string('source_type', 40)->default('researched');
            $t->string('source_url', 1000)->default('');
            $t->string('flags', 1000)->default('');

            // A row the feed stops seeing is deactivated, never deleted, so a
            // pipeline note is never orphaned and a bad run can be backed out.
            $t->boolean('is_active')->default(true);
            $t->timestamp('first_seen_at')->useCurrent();
            $t->timestamp('last_seen_at')->useCurrent();
            $t->timestamps();

            $t->index(['vertical_id', 'is_active', 'score'], 'ix_lead_board');
            $t->index(['vertical_id', 'state'], 'ix_lead_state');
            $t->index(['vertical_id', 'tier'], 'ix_lead_tier');
            $t->index(['vertical_id', 'stage'], 'ix_lead_stage');
            $t->index(['vertical_id', 'category'], 'ix_lead_category');
            $t->index('rfp_deadline', 'ix_lead_deadline');
            $t->index('org', 'ix_lead_org');
        });

        // Search. Wrapped because InnoDB FULLTEXT is available on MySQL 5.6+
        // and MariaDB 10.0+, but a managed host can still refuse it — the API
        // falls back to LIKE, slower but correct at this size.
        try {
            DB::statement('ALTER TABLE leads ADD FULLTEXT ft_lead (name, city, org, owner_org)');
        } catch (Throwable $e) {
            // Non-fatal by design; logged by the migration output.
            echo "  note: FULLTEXT index not created ({$e->getMessage()}); search will use LIKE\n";
        }

        Schema::create('lead_details', function (Blueprint $t) {
            $t->unsignedBigInteger('lead_id')->primary();
            $t->json('payload');
            $t->foreign('lead_id')->references('id')->on('leads')->cascadeOnDelete();
        });

        // Contacts were three different shapes on the old board: flat
        // contact_* fields on projects, operator-level fields on data centres,
        // and an array on retail/events/travel. Normalising them here is what
        // finally makes "every lead with a published email" a real query.
        Schema::create('lead_contacts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $t->string('name', 160)->default('');
            $t->string('title', 500)->default('');
            $t->string('email')->default('');
            $t->string('phone', 160)->default('');
            // Why we believe it, in the board's own words. Never dropped: the
            // whole point is that a reader can tell a published address from
            // a guess.
            $t->text('confidence')->nullable();
            $t->enum('audit', ['', 'confirmed', 'stale', 'unconfirmed', 'wrong'])->default('');
            $t->boolean('is_mailbox')->default(false);   // rfp@ vs a named person
            $t->string('source_url', 1000)->default('');
            $t->unsignedSmallInteger('sort_order')->default(0);

            $t->index(['lead_id', 'sort_order'], 'ix_contact_lead');
            $t->index('email', 'ix_contact_email');
        });

        // ------------------------------------------------------------------
        // Pipeline — replaces the statuses and notes the published board kept
        // ------------------------------------------------------------------
        Schema::create('pipeline_statuses', function (Blueprint $t) {
            $t->string('code', 24)->primary();
            $t->string('label', 60);
            $t->enum('chip_class', ['plain', 'early', 'live', 'dead'])->default('plain');
            $t->unsignedTinyInteger('sort_order');
            $t->boolean('is_closed')->default(false);
        });

        DB::table('pipeline_statuses')->insert([
            ['code' => 'no_contact',  'label' => 'Not enough contact info', 'chip_class' => 'early', 'sort_order' => 1, 'is_closed' => false],
            ['code' => 'reaching',    'label' => 'Trying to reach',         'chip_class' => 'early', 'sort_order' => 2, 'is_closed' => false],
            ['code' => 'in_progress', 'label' => 'In progress',             'chip_class' => 'early', 'sort_order' => 3, 'is_closed' => false],
            ['code' => 'meeting',     'label' => 'Site walk / meeting set', 'chip_class' => 'live',  'sort_order' => 4, 'is_closed' => false],
            ['code' => 'proposal',    'label' => 'Proposal sent',           'chip_class' => 'live',  'sort_order' => 5, 'is_closed' => false],
            ['code' => 'won',         'label' => 'Won',                     'chip_class' => 'live',  'sort_order' => 6, 'is_closed' => true],
            ['code' => 'lost',        'label' => 'Lost',                    'chip_class' => 'dead',  'sort_order' => 7, 'is_closed' => true],
            ['code' => 'no_fit',      'label' => 'No fit — do not call',    'chip_class' => 'dead',  'sort_order' => 8, 'is_closed' => true],
            ['code' => 'revisit',     'label' => 'Revisit later',           'chip_class' => 'plain', 'sort_order' => 9, 'is_closed' => false],
        ]);

        Schema::create('pipelines', function (Blueprint $t) {
            $t->unsignedBigInteger('lead_id')->primary();
            $t->string('status', 24)->nullable();
            $t->mediumText('note')->nullable();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->index('status', 'ix_pipeline_status');
            $t->index('updated_at', 'ix_pipeline_updated');
            $t->foreign('lead_id')->references('id')->on('leads')->cascadeOnDelete();
            $t->foreign('status')->references('code')->on('pipeline_statuses')->nullOnDelete();
        });

        // Append-only history: who moved what, when, and what the note said at
        // the time. The thing a shared board most needs and the old one could
        // not give — six months from now, why did this lead go to Lost?
        Schema::create('pipeline_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $t->string('old_status', 24)->nullable();
            $t->string('new_status', 24)->nullable();
            $t->mediumText('note')->nullable();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('at')->useCurrent();

            $t->index(['lead_id', 'at'], 'ix_event_lead');
            $t->index('at', 'ix_event_at');
        });

        // ------------------------------------------------------------------
        // Reference tabs — state licensing rules. Not leads; no pipeline.
        // ------------------------------------------------------------------
        Schema::create('licensings', function (Blueprint $t) {
            $t->id();
            $t->enum('kind', ['cctv', 'guard']);
            $t->char('state', 2);
            $t->string('state_name', 60);
            $t->string('required', 255)->default('');
            // Descriptive, not identifiers: Alaska's issuing agency runs to
            // two named departments and 300-odd characters, and several
            // lookup URLs carry a parenthetical explaining what the search
            // covers. These are prose fields and typed as such.
            $t->text('agency')->nullable();
            $t->text('lookup_url')->nullable();
            $t->text('apply_url')->nullable();
            $t->json('payload');
            $t->timestamps();

            $t->unique(['kind', 'state'], 'uk_licensing');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licensings');
        Schema::dropIfExists('pipeline_events');
        Schema::dropIfExists('pipelines');
        Schema::dropIfExists('pipeline_statuses');
        Schema::dropIfExists('lead_contacts');
        Schema::dropIfExists('lead_details');
        Schema::dropIfExists('leads');
        Schema::dropIfExists('stage_ranks');
    }
};
