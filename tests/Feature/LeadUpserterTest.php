<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\StageRefusal;
use App\Models\Vertical;
use App\Services\LeadUpserter;
use Database\Seeders\VerticalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The guarantees that protect the board from its own feeds.
 *
 * Every one of these was previously a rule written in a prompt that a human or
 * a model had to remember. They are tested here because the nightly job runs
 * unattended, and a rule nobody can enforce is a rule that eventually breaks.
 */
class LeadUpserterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VerticalSeeder::class);
    }

    private function verticalId(string $key): int
    {
        return Vertical::where('key', $key)->value('id');
    }

    private function seedLead(array $overrides = []): Lead
    {
        return Lead::create(array_merge([
            'vertical_id' => $this->verticalId('datacenters'),
            'lead_key' => 'dc_ND_testsite',
            'name' => 'Test Site',
            'state' => 'ND',
            'stage' => 'Operational',
            'score' => 70,
        ], $overrides));
    }

    public function test_a_new_lead_is_created(): void
    {
        $upserter = new LeadUpserter;

        $lead = $upserter->upsert(
            ['vertical_id' => $this->verticalId('projects'), 'lead_key' => 'proj_MA_newjob', 'name' => 'New Job', 'state' => 'MA'],
            ['route_in' => 'Call the GC.'],
        );

        $this->assertSame(1, $upserter->stats['created']);
        $this->assertSame('New Job', $lead->fresh()->name);
        $this->assertSame('Call the GC.', $lead->detail->payload['route_in']);
    }

    public function test_a_facility_may_move_forward_through_the_build_stages(): void
    {
        $this->seedLead(['stage' => 'Announced']);
        $upserter = new LeadUpserter;

        $upserter->upsert(
            ['vertical_id' => $this->verticalId('datacenters'), 'lead_key' => 'dc_ND_testsite', 'stage' => 'Under Construction'],
            [],
        );

        $this->assertSame('Under Construction', Lead::where('lead_key', 'dc_ND_testsite')->value('stage'));
        $this->assertSame(0, $upserter->stats['refused']);
    }

    /**
     * The rule that matters most. A live building does not un-build itself, so
     * a secondary source claiming an operational site went back to
     * construction is wrong, and the claim is refused rather than written.
     */
    public function test_a_backwards_stage_claim_is_refused_and_recorded(): void
    {
        $lead = $this->seedLead(['stage' => 'Operational']);
        $upserter = new LeadUpserter;

        $upserter->upsert([
            'vertical_id' => $this->verticalId('datacenters'),
            'lead_key' => 'dc_ND_testsite',
            'stage' => 'Under Construction',
            'source_url' => 'https://example.test/stale-article',
        ], []);

        $this->assertSame('Operational', $lead->fresh()->stage, 'the stage must not move backwards');
        $this->assertSame(1, $upserter->stats['refused']);

        $refusal = StageRefusal::where('lead_id', $lead->id)->sole();
        $this->assertSame('Operational', $refusal->from_stage);
        $this->assertSame('Under Construction', $refusal->to_stage);
        $this->assertSame('https://example.test/stale-article', $refusal->source_url);
    }

    /**
     * An operating facility is never marked cancelled by a feed — only a
     * proposal or an expansion is, and that is a deliberate act. Cancellation
     * sits outside the stage ladder for exactly this reason.
     */
    public function test_a_feed_cannot_cancel_a_lead_by_stage_comparison(): void
    {
        $lead = $this->seedLead(['stage' => 'Operational']);
        $upserter = new LeadUpserter;

        $upserter->upsert(
            ['vertical_id' => $this->verticalId('datacenters'), 'lead_key' => 'dc_ND_testsite', 'stage' => 'Cancelled/Withdrawn'],
            [],
        );

        $this->assertSame('Operational', $lead->fresh()->stage);
        $this->assertSame(1, $upserter->stats['refused']);
    }

    public function test_an_announced_proposal_also_cannot_be_cancelled_by_the_feed(): void
    {
        $lead = $this->seedLead(['stage' => 'Announced']);
        $upserter = new LeadUpserter;

        $upserter->upsert(
            ['vertical_id' => $this->verticalId('datacenters'), 'lead_key' => 'dc_ND_testsite', 'stage' => 'Cancelled/Withdrawn'],
            [],
        );

        $this->assertSame('Announced', $lead->fresh()->stage,
            'cancelling is a deliberate act, never a side effect of a feed pass');
    }

    /**
     * A blank from a feed means "I did not find this", not "this is empty
     * now". The published board's whole contact layer depends on the
     * difference.
     */
    public function test_a_blank_incoming_value_does_not_erase_what_is_already_known(): void
    {
        $lead = $this->seedLead(['org' => 'Suffolk Construction', 'score' => 70]);

        (new LeadUpserter)->upsert([
            'vertical_id' => $this->verticalId('datacenters'),
            'lead_key' => 'dc_ND_testsite',
            'org' => '',
            'score' => 74,
        ], []);

        $lead->refresh();
        $this->assertSame('Suffolk Construction', $lead->org, 'a blank must not overwrite a known value');
        $this->assertSame(74, $lead->score, 'a real value still updates');
    }

    public function test_an_over_long_value_is_clamped_rather_than_aborting_the_run(): void
    {
        $upserter = new LeadUpserter;

        $upserter->upsert([
            'vertical_id' => $this->verticalId('projects'),
            'lead_key' => 'proj_MA_verbose',
            'name' => 'Verbose',
            'org' => str_repeat('a', 900),
        ], []);

        $this->assertSame(1, $upserter->stats['clamped']);
        $this->assertSame(500, strlen(Lead::where('lead_key', 'proj_MA_verbose')->value('org')));
    }

    public function test_contacts_are_replaced_so_a_departed_person_disappears(): void
    {
        $lead = $this->seedLead();
        $upserter = new LeadUpserter;

        $upserter->upsert(['vertical_id' => $this->verticalId('datacenters'), 'lead_key' => 'dc_ND_testsite'], [], [
            ['name' => 'Dana Teague', 'title' => 'VP Operations'],
        ]);
        $this->assertSame(1, $lead->contacts()->count());

        $upserter->upsert(['vertical_id' => $this->verticalId('datacenters'), 'lead_key' => 'dc_ND_testsite'], [], [
            ['name' => '', 'title' => 'Operator main line', 'phone' => '555-0100', 'is_mailbox' => true],
        ]);

        $contacts = $lead->fresh()->contacts;
        $this->assertCount(1, $contacts);
        $this->assertSame('Operator main line', $contacts->first()->title);
        $this->assertSame(0, $lead->contacts()->namedPerson()->count(),
            'a name that has gone from the source must go from the board');
    }

    /**
     * A row the feed stops seeing is deactivated, never deleted, so a pipeline
     * note is never orphaned and a bad run can be backed out with one update.
     */
    public function test_leads_the_feed_no_longer_sees_are_deactivated_not_deleted(): void
    {
        $kept = $this->seedLead(['lead_key' => 'dc_ND_kept']);
        $gone = $this->seedLead(['lead_key' => 'dc_ND_gone']);

        $deactivated = (new LeadUpserter)->deactivateMissing('datacenters', ['dc_ND_kept']);

        $this->assertSame(1, $deactivated);
        $this->assertTrue($kept->fresh()->is_active);
        $this->assertFalse($gone->fresh()->is_active);
        $this->assertNotNull($gone->fresh(), 'the row must survive so its pipeline note is not orphaned');
    }

    public function test_a_second_identical_pass_reports_no_change(): void
    {
        $attributes = ['vertical_id' => $this->verticalId('projects'), 'lead_key' => 'proj_MA_steady', 'name' => 'Steady', 'state' => 'MA'];

        $first = new LeadUpserter;
        $first->upsert($attributes, ['notes' => 'same']);
        $this->assertSame(1, $first->stats['created']);

        $second = new LeadUpserter;
        $second->upsert($attributes, ['notes' => 'same']);

        $this->assertSame(0, $second->stats['created']);
        $this->assertSame(0, $second->stats['updated']);
        $this->assertSame(1, $second->stats['unchanged']);
    }
}
