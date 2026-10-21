<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadContact;
use App\Models\LeadDetail;
use App\Models\StageRank;
use App\Models\StageRefusal;
use Illuminate\Support\Facades\DB;

/**
 * The single way a lead enters or changes in the database.
 *
 * Both the one-time import and the nightly research writer go through here, so
 * the rules that protect the board cannot be bypassed by whichever code path
 * happens to be running:
 *
 *   - A build stage only ever moves forward. A backwards claim is refused and
 *     recorded, never written.
 *   - A row the feed stops seeing is deactivated, never deleted, so a pipeline
 *     note is never orphaned.
 *   - A blank incoming value never overwrites a value already on the row. The
 *     feed is allowed to add what it knows and not to erase what it doesn't.
 */
final class LeadUpserter
{
    /** @var array{created:int,updated:int,refused:int,unchanged:int,clamped:int} */
    public array $stats = ['created' => 0, 'updated' => 0, 'refused' => 0,
        'unchanged' => 0, 'clamped' => 0];

    /**
     * Column limits for the text fields the sources are known to overflow.
     *
     * Several board fields carry prose rather than the short value their name
     * implies — one `general_contractor` is a 421-character list of six
     * conditionally-awarded joint ventures, and more than one `gc_office_phone`
     * is a paragraph explaining which regional line to call instead. Rather
     * than let one such row abort an unattended nightly run, over-long values
     * are clamped and counted, so the run completes and the count tells you to
     * look.
     *
     * @var array<string, array<string,int>>
     */
    private const LIMITS = [
        'leads' => ['name' => 255, 'city' => 120, 'state' => 2, 'tier_label' => 40,
            'stage' => 40, 'category' => 255, 'subtype' => 120, 'org' => 500,
            'owner_org' => 255, 'brand_fit' => 500, 'source_type' => 40,
            'source_url' => 1000, 'flags' => 1000],
        'contacts' => ['name' => 160, 'title' => 500, 'email' => 255,
            'phone' => 160, 'audit' => 16, 'source_url' => 1000],
    ];

    public function __construct(private readonly ?int $jobRunId = null) {}

    /**
     * Trim a value to its column width, counting every clamp.
     */
    private function clamp(string $table, string $column, mixed $value): mixed
    {
        $limit = self::LIMITS[$table][$column] ?? null;

        if ($limit === null || ! is_string($value) || mb_strlen($value) <= $limit) {
            return $value;
        }

        $this->stats['clamped']++;

        return mb_substr($value, 0, $limit);
    }

    /**
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>
     */
    private function clampAll(string $table, array $attributes): array
    {
        foreach ($attributes as $column => $value) {
            $attributes[$column] = $this->clamp($table, $column, $value);
        }

        return $attributes;
    }

    /**
     * @param  array<string,mixed>  $attributes  columns for the leads table
     * @param  array<string,mixed>  $detail  the per-tab payload
     * @param  list<array<string,mixed>>  $contacts
     */
    public function upsert(array $attributes, array $detail, array $contacts = []): Lead
    {
        $attributes = $this->clampAll('leads', $attributes);

        return DB::transaction(function () use ($attributes, $detail, $contacts): Lead {
            $existing = Lead::where('lead_key', $attributes['lead_key'])->first();

            if ($existing === null) {
                $lead = Lead::create($attributes + [
                    'first_seen_at' => now(),
                    'last_seen_at' => now(),
                ]);
                $this->stats['created']++;
            } else {
                $lead = $this->applyToExisting($existing, $attributes);
            }

            LeadDetail::updateOrCreate(
                ['lead_id' => $lead->id],
                ['payload' => $detail],
            );

            if ($contacts !== []) {
                $this->syncContacts($lead, $contacts);
            }

            return $lead;
        });
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    private function applyToExisting(Lead $lead, array $attributes): Lead
    {
        $incomingStage = (string) ($attributes['stage'] ?? '');
        unset($attributes['stage']);

        // A blank from the feed is "I don't know", not "it's empty now".
        foreach ($attributes as $column => $value) {
            if ($column === 'lead_key' || $column === 'vertical_id') {
                continue;
            }

            if ($value === null || $value === '') {
                continue;
            }

            $lead->{$column} = $value;
        }

        if ($incomingStage !== '' && $incomingStage !== $lead->stage) {
            if (StageRank::allowsMove($lead->stage, $incomingStage)) {
                $lead->stage = $incomingStage;
            } else {
                StageRefusal::create([
                    'lead_id' => $lead->id,
                    'from_stage' => $lead->stage,
                    'to_stage' => $incomingStage,
                    'source_url' => (string) ($attributes['source_url'] ?? ''),
                    'job_run_id' => $this->jobRunId,
                    'at' => now(),
                ]);
                $this->stats['refused']++;
            }
        }

        $lead->last_seen_at = now();
        $lead->is_active = true;

        $dirty = $lead->getDirty();
        // last_seen_at moves on every pass and is not a change worth counting.
        unset($dirty['last_seen_at']);

        $lead->save();
        $dirty === [] ? $this->stats['unchanged']++ : $this->stats['updated']++;

        return $lead;
    }

    /**
     * Contacts are replaced wholesale per lead, because a contact that has
     * disappeared from a published page should disappear here too — a stale
     * name on a row is worse than no name, which is why the board removes a
     * person who has provably left rather than leaving them in place.
     *
     * @param  list<array<string,mixed>>  $contacts
     */
    private function syncContacts(Lead $lead, array $contacts): void
    {
        $lead->contacts()->delete();

        foreach ($contacts as $i => $contact) {
            LeadContact::create($this->clampAll('contacts', [
                'lead_id' => $lead->id,
                'name' => (string) ($contact['name'] ?? ''),
                'title' => (string) ($contact['title'] ?? ''),
                'email' => (string) ($contact['email'] ?? ''),
                'phone' => (string) ($contact['phone'] ?? ''),
                'confidence' => $contact['confidence'] ?? null,
                'audit' => (string) ($contact['audit'] ?? ''),
                'is_mailbox' => (bool) ($contact['is_mailbox'] ?? false),
                'source_url' => (string) ($contact['source_url'] ?? ''),
                'sort_order' => (int) ($contact['sort_order'] ?? $i),
            ]));
        }
    }

    /**
     * Mark every lead on a tab that this run did not see as inactive. Used by
     * the feed, never by the research writer: a research pass covers a slice
     * of a tab, not all of it, and must not retire everything it didn't visit.
     *
     * @param  list<string>  $seenKeys
     */
    public function deactivateMissing(string $verticalKey, array $seenKeys): int
    {
        return Lead::onVertical($verticalKey)->active()
            ->whereNotIn('lead_key', $seenKeys)
            ->update(['is_active' => false]);
    }
}
