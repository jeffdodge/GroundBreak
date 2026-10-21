<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feeds, job bookkeeping, and the realtime change feed.
 *
 * The job tables exist because of a concrete failure: on 9 October 2026 the
 * SAM.gov pull silently did not run, and the only reason anyone knew was a
 * push notification. A job that does not record its own runs is a job nobody
 * can trust, so every run writes a row here whether it succeeds or not.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Raw SAM.gov notices, stored before they become leads. Keeping the
        // raw row makes the hourly pull idempotent, lets a filter change be
        // re-applied to yesterday's data without re-fetching, and leaves a
        // trail when a notice's deadline or contact changes underneath you.
        Schema::create('sam_notices', function (Blueprint $t) {
            $t->string('notice_id', 64)->primary();
            $t->string('solicitation_number', 120)->default('');
            $t->string('title', 500)->default('');
            $t->string('naics', 16)->default('');
            $t->string('notice_type', 80)->default('');
            $t->date('posted_date')->nullable();
            $t->date('response_deadline')->nullable();
            $t->string('set_aside', 120)->default('');
            $t->text('agency_path')->nullable();
            $t->string('pop_city', 120)->default('');
            $t->char('pop_state', 2)->default('');
            $t->string('pop_zip', 16)->default('');
            $t->string('pop_country', 8)->default('');
            $t->string('office_city', 120)->default('');
            $t->char('office_state', 2)->default('');
            $t->string('poc_name', 160)->default('');
            $t->string('poc_email')->default('');
            $t->string('poc_phone', 64)->default('');
            $t->string('ui_link', 1000)->default('');
            $t->json('raw');

            /** Why the transform rejected it, when it did. A row dropped for
             *  "deadline passed" is information, not noise. */
            $t->string('rejected_reason', 160)->default('');
            $t->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $t->timestamp('first_seen_at')->useCurrent();
            $t->timestamp('last_seen_at')->useCurrent();

            $t->index('response_deadline', 'ix_sam_deadline');
            $t->index('pop_state', 'ix_sam_state');
        });

        Schema::create('job_runs', function (Blueprint $t) {
            $t->id();
            $t->string('job', 40);   // sam_pull | research | prune
            $t->enum('status', ['running', 'ok', 'partial', 'failed'])->default('running');
            $t->timestamp('started_at')->useCurrent();
            $t->dateTime('finished_at')->nullable();
            /** @var array{fetched?:int,added?:int,updated?:int,refused?:int} */
            $t->json('stats')->nullable();
            $t->text('error')->nullable();
            $t->decimal('cost_usd', 8, 4)->nullable();

            $t->index(['job', 'started_at'], 'ix_job_run');
        });

        // Rows the research writer tried to move backwards. Not an error log,
        // a findings log: one facility collecting several refusals means the
        // sources disagree about it and somebody should look.
        Schema::create('stage_refusals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $t->string('from_stage', 40);
            $t->string('to_stage', 40);
            $t->string('source_url', 1000)->default('');
            $t->foreignId('job_run_id')->nullable()->constrained('job_runs')->nullOnDelete();
            $t->timestamp('at')->useCurrent();

            $t->index(['lead_id', 'at'], 'ix_refusal_lead');
        });

        // Keys for the scheduled commands, so a job never authenticates as a
        // person. Scoped and revocable; only the SHA-256 is stored.
        Schema::create('api_keys', function (Blueprint $t) {
            $t->id();
            $t->string('label', 120);
            $t->char('key_hash', 64)->unique();
            $t->enum('scope', ['research_write', 'feed_write', 'read_only']);
            $t->boolean('is_active')->default(true);
            $t->dateTime('last_used_at')->nullable();
            $t->timestamps();
        });

        // The replay log behind the live board. Reverb pushes events to open
        // browsers, but a websocket that drops loses whatever it missed; a
        // reconnecting client asks for everything after the last sequence it
        // saw and catches up exactly.
        Schema::create('change_logs', function (Blueprint $t) {
            $t->id();   // the sequence number clients resume from
            $t->enum('entity', ['lead', 'pipeline', 'contact', 'job']);
            $t->unsignedBigInteger('entity_id');
            $t->string('lead_key', 120)->default('');
            $t->enum('op', ['insert', 'update', 'delete']);
            $t->foreignId('actor')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('at', 3)->useCurrent();
            $t->json('detail')->nullable();

            $t->index('at', 'ix_change_at');
            $t->index('lead_key', 'ix_change_lead');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('change_logs');
        Schema::dropIfExists('api_keys');
        Schema::dropIfExists('stage_refusals');
        Schema::dropIfExists('job_runs');
        Schema::dropIfExists('sam_notices');
    }
};
