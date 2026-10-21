<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadContact;
use App\Models\Vertical;
use App\Services\LeadScorer;
use Carbon\CarbonImmutable;
use Database\Seeders\VerticalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The data-driven scorer must reproduce the formula that used to be written by
 * hand, or every score on the board moves the day it is switched on.
 *
 * The expected numbers below are computed from the published formula: 27 base,
 * plus value bands, months to mobilisation, a site-type lookup, contact
 * reachability, build stage, and two points for a named general contractor.
 */
class LeadScorerTest extends TestCase
{
    use RefreshDatabase;

    private Vertical $projects;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VerticalSeeder::class);
        $this->projects = Vertical::where('key', 'projects')->sole();
    }

    private function scorer(): LeadScorer
    {
        // Pinned, because two components read "months from today".
        return new LeadScorer(CarbonImmutable::parse('2026-10-09'));
    }

    private function lead(array $attributes = []): Lead
    {
        return Lead::create(array_merge([
            'vertical_id' => $this->projects->id,
            'lead_key' => 'proj_TX_test'.uniqid(),
            'name' => 'Test',
            'state' => 'TX',
        ], $attributes));
    }

    /**
     * 100 Park Place, Houston: no published value, breaking ground this month,
     * an office tower, no contact, under construction, no GC named.
     * 27 + 2 + 5 + 2 + 0 + 1 + 0 = 37.
     */
    public function test_it_reproduces_the_published_score_for_a_thin_office_tower(): void
    {
        $lead = $this->lead([
            'category' => 'Office/Lab',
            'stage' => 'Under Construction',
            'value_usd' => null,
        ]);
        $lead->values()->delete();
        $this->setStart($lead, '2026-10');

        $result = $this->scorer()->score($lead->fresh());

        $this->assertSame(37, $result['score']);
        $this->assertSame('D', $result['tier']);
        $this->assertSame(2, $result['parts']['value']['points'], 'an unknown value scores 2, not 0');
        $this->assertSame(5, $result['parts']['timing']['points']);
        $this->assertSame(2, $result['parts']['site_type']['points']);
        $this->assertSame(0, $result['parts']['contact']['points']);
    }

    /**
     * Hylo Park Phase 3, North Las Vegas: $375M, breaking ground this month,
     * mixed-use, no contact, under construction, no GC named.
     * 27 + 11 + 5 + 6 + 0 + 1 + 0 = 50.
     */
    public function test_it_reproduces_the_published_score_for_a_large_mixed_use_job(): void
    {
        $lead = $this->lead([
            'category' => 'Mixed-Use',
            'stage' => 'Under Construction',
            'value_usd' => 375_000_000,
        ]);
        $this->setStart($lead, '2026-10');

        $this->assertSame(50, $this->scorer()->score($lead->fresh())['score']);
    }

    public function test_reachability_is_worth_more_than_anything_else_on_the_row(): void
    {
        $lead = $this->lead(['category' => 'Multifamily', 'stage' => 'Awarded', 'org' => 'Suffolk']);

        $bare = $this->scorer()->score($lead->fresh())['score'];

        LeadContact::create([
            'lead_id' => $lead->id, 'name' => 'Jane Roe',
            'email' => 'jane@example.test', 'phone' => '617-555-0100',
        ]);

        $withContact = $this->scorer()->score($lead->fresh()->load('contacts'))['score'];

        $this->assertSame(15, $withContact - $bare,
            'a named person with an email and a phone is worth 7 + 4 + 4');
    }

    public function test_a_cancelled_lead_scores_zero_whatever_else_is_true_of_it(): void
    {
        $lead = $this->lead([
            'category' => 'Multifamily', 'stage' => 'Cancelled/Withdrawn',
            'value_usd' => 2_000_000_000, 'org' => 'Suffolk',
        ]);

        $result = $this->scorer()->score($lead->fresh());

        $this->assertSame(0, $result['score']);
        $this->assertSame('X', $result['tier']);
    }

    public function test_tier_cuts_come_from_the_vertical_and_can_be_retuned(): void
    {
        $this->assertSame('A', $this->projects->tierFor(68));
        $this->assertSame('B', $this->projects->tierFor(67));

        $this->projects->update(['tier_cuts' => ['A' => 80, 'B' => 60, 'C' => 40]]);

        $this->assertSame('B', $this->projects->fresh()->tierFor(68),
            'retuning the cuts must not need a deploy');
    }

    /**
     * The actual requirement: a vertical nobody wrote code for can be scored.
     */
    public function test_a_brand_new_vertical_is_scored_from_its_own_rules(): void
    {
        $marinas = Vertical::create([
            'key' => 'marinas', 'label' => 'Marinas', 'key_prefix' => 'marina',
            'name_field' => 'company', 'city_field' => 'city', 'state_field' => 'state',
            'score_base' => 10, 'tier_cuts' => ['A' => 40, 'B' => 25, 'C' => 15],
        ]);

        $marinas->scoreComponents()->create([
            'key' => 'slips', 'label' => 'How many slips',
            'source_kind' => 'field', 'source_ref' => 'slip_count',
            'rule_type' => 'bands', 'max_points' => 30,
            'config' => ['bands' => [
                ['min' => 500, 'points' => 30],
                ['min' => 200, 'points' => 20],
                ['min' => 50, 'points' => 10],
            ], 'unknown' => 5, 'below' => 0],
        ]);

        $definition = $marinas->fieldDefinitions()->create([
            'key' => 'slip_count', 'label' => 'Slip count', 'type' => 'number',
            'is_filterable' => true,
        ]);

        $lead = Lead::create([
            'vertical_id' => $marinas->id, 'lead_key' => 'marina_MA_testharbour',
            'name' => 'Test Harbour', 'state' => 'MA',
        ]);
        $lead->values()->create(['field_definition_id' => $definition->id, 'value_num' => 240]);

        $result = $this->scorer()->score($lead->fresh());

        $this->assertSame(30, $result['score'], '10 base + 20 for 240 slips');
        $this->assertSame('B', $result['tier']);
        $this->assertSame('How many slips', $result['parts']['slips']['label']);
    }

    private function setStart(Lead $lead, string $month): void
    {
        $definition = $this->projects->fieldDefinitions()->firstOrCreate(
            ['key' => 'construction_start'],
            ['label' => 'Construction start', 'type' => 'text'],
        );
        $lead->values()->updateOrCreate(
            ['field_definition_id' => $definition->id],
            ['value_text' => $month],
        );
    }
}
