<?php

namespace Tests\Feature;

use App\Models\Vertical;
use App\Services\LeadKey;
use Database\Seeders\VerticalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The key algorithm now reads its field names from the vertical row, so it
 * needs the database and lives as a feature test.
 *
 * It is also the most dangerous function in the application: a pipeline note
 * hangs off its output, so a change here silently reassigns somebody's note to
 * a different lead. These cases pin the behaviour the published board already
 * relies on.
 */
class LeadKeyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VerticalSeeder::class);
    }

    private function v(string $key): Vertical
    {
        return Vertical::where('key', $key)->sole();
    }

    public function test_it_builds_a_key_from_tab_state_and_normalised_name(): void
    {
        $this->assertSame(
            'proj_TX_100parkplace',
            LeadKey::for($this->v('projects'), ['project_name' => '100 Park Place', 'state' => 'TX']),
        );
    }

    public function test_it_reads_the_name_and_state_fields_each_tab_actually_uses(): void
    {
        $this->assertSame(
            'dc_PA_awshomercityenergycampus',
            LeadKey::for($this->v('datacenters'), ['facility_name' => 'AWS Homer City Energy Campus', 'state' => 'PA']),
        );

        // Company tabs keep their state under hq_state, not state.
        $this->assertSame(
            'retail_MA_marketbasket',
            LeadKey::for($this->v('retail'), ['company' => 'Market Basket', 'hq_state' => 'MA']),
        );
    }

    public function test_it_strips_punctuation_case_and_accents_consistently(): void
    {
        $this->assertSame(
            'guard_MA_oconnorsecurityllc',
            LeadKey::for($this->v('guards'), ['company' => "O'Connor Security, LLC", 'state' => 'ma']),
        );
    }

    public function test_a_row_with_no_state_is_keyed_xx_rather_than_dropped(): void
    {
        $this->assertSame(
            'proj_xx_somethingunplaced',
            LeadKey::for($this->v('projects'), ['project_name' => 'Something Unplaced']),
        );
    }

    public function test_a_row_with_no_usable_name_has_no_key(): void
    {
        $this->assertSame('', LeadKey::for($this->v('projects'), ['project_name' => '   ', 'state' => 'MA']));
        $this->assertSame('', LeadKey::for($this->v('projects'), ['project_name' => '—', 'state' => 'MA']));
    }

    public function test_the_name_is_capped_so_a_long_title_cannot_overflow_the_column(): void
    {
        $key = LeadKey::for($this->v('projects'), [
            'project_name' => str_repeat('verylongprojectname', 10),
            'state' => 'MA',
        ]);

        $this->assertLessThanOrEqual(120, strlen($key), 'must fit the lead_key column');
        $this->assertSame(48, strlen(substr($key, strlen('proj_MA_'))), 'the name part is capped at 48');
    }

    /**
     * The collision this exists for is real and was live on the published
     * board: two separate Stream Data Centers facilities, in Dallas and in
     * Plano, normalised to the same key and therefore shared one pipeline
     * status.
     */
    public function test_rows_that_normalise_alike_get_distinct_keys(): void
    {
        $dallas = ['facility_name' => 'Stream DFW I & II', 'state' => 'TX', 'city' => 'Dallas'];
        $plano = ['facility_name' => 'Stream DFW III', 'state' => 'TX', 'city' => 'Plano'];

        $base = LeadKey::for($this->v('datacenters'), $dallas);
        $this->assertSame($base, LeadKey::for($this->v('datacenters'), $plano), 'precondition: the base keys collide');

        $this->assertNotSame(
            LeadKey::disambiguate($this->v('datacenters'), $dallas, $base),
            LeadKey::disambiguate($this->v('datacenters'), $plano, $base),
        );
    }

    public function test_the_disambiguated_key_is_stable_across_runs(): void
    {
        $row = ['facility_name' => 'Stream DFW III', 'state' => 'TX', 'city' => 'Plano'];
        $base = LeadKey::for($this->v('datacenters'), $row);

        $this->assertSame(
            LeadKey::disambiguate($this->v('datacenters'), $row, $base),
            LeadKey::disambiguate($this->v('datacenters'), $row, $base),
            'the suffix must come from the row, never from its position in a list',
        );
    }

    public function test_it_falls_back_to_a_row_hash_when_there_is_no_city(): void
    {
        $a = ['facility_name' => 'Twin Site', 'state' => 'TX', 'address' => '1 First St'];
        $b = ['facility_name' => 'Twin Site', 'state' => 'TX', 'address' => '2 Second St'];
        $base = LeadKey::for($this->v('datacenters'), $a);

        $this->assertNotSame(
            LeadKey::disambiguate($this->v('datacenters'), $a, $base),
            LeadKey::disambiguate($this->v('datacenters'), $b, $base),
        );
    }
}
