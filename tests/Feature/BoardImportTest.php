<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadContact;
use App\Models\Licensing;
use App\Models\Vertical;
use Database\Seeders\VerticalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The import that moves the published board into this database.
 *
 * Written against a small fixture rather than the real 5.7MB export so the
 * suite stays fast, but the shapes are the real ones: the three different
 * contact layouts, the prose-contaminated fields, and the key collision.
 */
class BoardImportTest extends TestCase
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

    private function fixture(array $data): string
    {
        $path = tempnam(sys_get_temp_dir(), 'gb').'.json';
        file_put_contents($path, json_encode($data));

        return $path;
    }

    public function test_it_imports_each_tab_into_leads_with_its_detail_payload(): void
    {
        $path = $this->fixture([
            'projects' => [[
                'project_name' => '100 Park Place', 'city' => 'Houston', 'state' => 'TX',
                'priority_score' => '37', 'priority_tier' => 'D — watch',
                'status' => 'Under Construction', 'project_type' => 'Office/Lab',
                'general_contractor' => '', 'owner_developer' => 'Stonelake Capital Partners',
                'square_footage' => '250000', 'route_in' => 'Ask who holds the GC contract.',
                'notes' => 'Roughly 250,000 sq ft office tower.',
            ]],
            'guards' => [[
                'company' => 'PalAmerican Security', 'city' => 'Boston', 'state' => 'MA',
                'phone' => '(508) 893-9900', 'email' => 'massachusettsinfo@palamerican.com',
                'license_number' => 'LW0352A', 'armed' => 'True',
            ]],
        ]);

        $this->artisan('groundbreak:import', ['file' => $path])->assertSuccessful();

        $project = Lead::where('lead_key', 'proj_TX_100parkplace')->sole();
        $this->assertSame('projects', $project->vertical->key);
        $this->assertSame('Houston', $project->city);
        $this->assertSame(37, $project->score);
        $this->assertSame('D', $project->tier);
        $this->assertSame('Under Construction', $project->stage);
        $this->assertSame(250000, $project->size_sqft);

        // Nothing is dropped: fields with no column land in the payload.
        $this->assertSame('Ask who holds the GC contract.', $project->detail->payload['route_in']);
        $this->assertArrayHasKey('notes', $project->detail->payload);

        $guard = Lead::where('lead_key', 'guard_MA_palamericansecurity')->sole();
        $this->assertSame('LW0352A', $guard->detail->payload['license_number']);
    }

    public function test_it_normalises_all_three_contact_shapes_into_one_table(): void
    {
        $path = $this->fixture([
            // An array of contacts, plus a switchboard hidden in a scalar field.
            'retail' => [[
                'company' => 'Example Retail', 'hq_state' => 'MA', 'hq_city' => 'Quincy',
                'hq_phone' => '617-555-0100', 'website' => 'https://example.test',
                'contacts' => [
                    ['name' => 'Jane Roe', 'title' => 'VP Asset Protection',
                        'email' => 'jane@example.test', 'confidence' => 'Published on their own site.',
                        'audit' => 'confirmed'],
                    ['name' => '', 'title' => 'Vendor mailbox', 'email' => 'rfp@example.test'],
                ],
            ]],
            // Flat contact_* fields.
            'projects' => [[
                'project_name' => 'Some Tower', 'state' => 'MA',
                'contact_name' => 'John Doe', 'contact_title' => 'Project Executive',
                'contact_phone' => '617-555-0200',
            ]],
            // Operator-level fields.
            'datacenters' => [[
                'facility_name' => 'Example DC', 'state' => 'VA', 'operator' => 'Example Operator',
                'main_phone' => '703-555-0300', 'sales_email' => 'sales@example.test',
            ]],
        ]);

        $this->artisan('groundbreak:import', ['file' => $path])->assertSuccessful();

        $retail = Lead::onVertical('retail')->sole();
        $this->assertCount(3, $retail->contacts, 'two published contacts plus the switchboard');
        $named = $retail->contacts()->namedPerson()->sole();
        $this->assertSame('Jane Roe', $named->name);
        $this->assertSame('confirmed', $named->audit);
        $this->assertFalse($named->is_mailbox);
        $this->assertSame('Published on their own site.', $named->confidence,
            'the confidence line is never dropped');

        $this->assertTrue(
            LeadContact::where('title', 'Corporate switchboard')->where('phone', '617-555-0100')->exists(),
            'a number hidden in a scalar field becomes a queryable contact',
        );

        $this->assertSame('John Doe', Lead::onVertical('projects')->sole()->contacts->first()->name);
        $this->assertCount(2, Lead::onVertical('datacenters')->sole()->contacts);
    }

    public function test_rows_that_normalise_to_the_same_key_are_both_kept(): void
    {
        $path = $this->fixture(['datacenters' => [
            ['facility_name' => 'Stream DFW I & II', 'state' => 'TX', 'city' => 'Dallas'],
            ['facility_name' => 'Stream DFW III', 'state' => 'TX', 'city' => 'Plano'],
        ]]);

        $this->artisan('groundbreak:import', ['file' => $path])->assertSuccessful();

        $this->assertSame(2, Lead::count(), 'neither facility may be swallowed by the other');
        $this->assertTrue(Lead::where('lead_key', 'dc_TX_streamdfwiii__dallas')->exists());
        $this->assertTrue(Lead::where('lead_key', 'dc_TX_streamdfwiii__plano')->exists());
    }

    public function test_running_it_twice_changes_nothing(): void
    {
        $path = $this->fixture(['projects' => [
            ['project_name' => 'Steady Job', 'state' => 'MA', 'priority_score' => '60'],
        ]]);

        $this->artisan('groundbreak:import', ['file' => $path])->assertSuccessful();
        $first = Lead::sole();

        $this->artisan('groundbreak:import', ['file' => $path])->assertSuccessful();

        $this->assertSame(1, Lead::count());
        $this->assertEquals($first->updated_at, Lead::sole()->updated_at,
            'an unchanged row must not be rewritten');
    }

    public function test_a_row_with_no_usable_name_is_skipped_and_reported(): void
    {
        $path = $this->fixture(['projects' => [
            ['project_name' => '', 'state' => 'MA'],
            ['project_name' => 'Real Job', 'state' => 'MA'],
        ]]);

        $this->artisan('groundbreak:import', ['file' => $path])
            ->expectsOutputToContain('skipped a projects row with no usable name')
            ->assertSuccessful();

        $this->assertSame(1, Lead::count());
    }

    public function test_the_licensing_tabs_import_as_reference_data_not_leads(): void
    {
        $path = $this->fixture([
            'cctv' => [['state' => 'MA', 'state_name' => 'Massachusetts',
                'cctv_license_required' => 'Yes - state level',
                'issuing_agency' => 'Example Board', 'lookup_url' => 'https://example.test/lookup']],
            'guardlic' => [['state' => 'MA', 'state_name' => 'Massachusetts',
                'guard_license_required' => 'Yes', 'issuing_agency' => 'State Police']],
        ]);

        $this->artisan('groundbreak:import', ['file' => $path])->assertSuccessful();

        $this->assertSame(0, Lead::count(), 'licensing rules are not leads and carry no pipeline');
        $this->assertSame(2, Licensing::count());
        $this->assertSame('Yes - state level', Licensing::where('kind', 'cctv')->sole()->required);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $path = $this->fixture(['projects' => [['project_name' => 'Ghost', 'state' => 'MA']]]);

        $this->artisan('groundbreak:import', ['file' => $path, '--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, Lead::count());
    }

    public function test_a_missing_file_fails_cleanly(): void
    {
        $this->artisan('groundbreak:import', ['file' => '/no/such/export.json'])->assertFailed();
    }
}
