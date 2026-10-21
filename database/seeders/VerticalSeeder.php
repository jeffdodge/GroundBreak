<?php

namespace Database\Seeders;

use App\Models\Service;
use App\Models\Vertical;
use Illuminate\Database\Seeder;

/**
 * The eight verticals the published board already carried, plus the three
 * services, expressed as data.
 *
 * The scoring rows below reproduce the project formula that used to live in a
 * Python file: 27 points of base, then value bands, months to mobilisation, a
 * site-type lookup, contact reachability, and build stage. Encoding it here is
 * what makes a ninth vertical something Brian can add and score himself.
 */
class VerticalSeeder extends Seeder
{
    public function run(): void
    {
        $services = $this->services();
        $all = array_values($services);

        foreach ($this->verticals() as $spec) {
            $components = $spec['components'] ?? [];
            unset($spec['components']);
            $serviceKeys = $spec['services'] ?? null;
            unset($spec['services']);

            $vertical = Vertical::updateOrCreate(['key' => $spec['key']], $spec);

            foreach ($components as $i => $component) {
                $vertical->scoreComponents()->updateOrCreate(
                    ['key' => $component['key']],
                    $component + ['sort_order' => ($i + 1) * 10],
                );
            }

            $vertical->services()->sync(
                $serviceKeys === null
                    ? $all
                    : array_map(fn (string $k): int => $services[$k], $serviceKeys),
            );
        }
    }

    /** @return array<string,int> service key to id */
    private function services(): array
    {
        $rows = [
            ['key' => 'nesa', 'label' => 'New England Security',
                'description' => 'Local guarding across MA, CT, RI, NH, VT and ME.',
                'sort_order' => 10],
            ['key' => 'nwss', 'label' => 'Nationwide Security Service',
                'description' => 'National guarding outside New England, single invoice.',
                'sort_order' => 20],
            ['key' => 'vgp', 'label' => 'Video Guard Pro',
                'description' => 'Remote video monitoring and mobile surveillance.',
                'sort_order' => 30],
        ];

        $out = [];

        foreach ($rows as $row) {
            $out[$row['key']] = Service::updateOrCreate(['key' => $row['key']], $row)->id;
        }

        return $out;
    }

    /**
     * The value ladder, shared by the verticals that track a contract value.
     *
     * An unknown value scores 2 rather than 0 on purpose: "not published" is
     * not the same as "small", and the board has never punished a row for what
     * the research failed to find.
     *
     * @return array<string,mixed>
     */
    private function valueComponent(): array
    {
        return [
            'key' => 'value', 'label' => 'How big the job is',
            'source_kind' => 'column', 'source_ref' => 'value_usd',
            'rule_type' => 'bands', 'max_points' => 15,
            'config' => ['bands' => [
                ['min' => 1_000_000_000, 'points' => 15],
                ['min' => 500_000_000, 'points' => 13],
                ['min' => 250_000_000, 'points' => 11],
                ['min' => 100_000_000, 'points' => 8],
                ['min' => 50_000_000, 'points' => 7],
                ['min' => 25_000_000, 'points' => 4],
            ], 'unknown' => 2, 'below' => 0],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function verticals(): array
    {
        return [
            [
                'key' => 'projects', 'label' => 'Projects', 'label_singular' => 'Project',
                'key_prefix' => 'proj', 'sort_order' => 10,
                'name_field' => 'project_name', 'city_field' => 'city', 'state_field' => 'state',
                'source_map' => ['name' => 'project_name', 'city' => 'city', 'state' => 'state', 'score' => 'priority_score', 'tier_label' => 'priority_tier', 'stage' => 'status', 'category' => 'project_type', 'org' => 'general_contractor', 'owner_org' => 'owner_developer', 'service_note' => 'brand_fit', 'value_usd' => 'value_usd', 'size_sqft' => 'square_footage', 'rfp_deadline' => 'rfp_deadline', 'source_type' => 'source_type', 'source_url' => 'source_url', 'flags' => 'flags'],
                'intro' => 'Every tracked project, ranked by how worth a call it is right now.',
                'score_base' => 27, 'tier_cuts' => ['A' => 68, 'B' => 55, 'C' => 42],
                'research_cadence' => 'daily',
                'research_terms' => '"breaks ground", "begins construction", "selected as construction manager", "awarded contract to build"',
                'research_sources' => "Construction Dive\nENR\nREBusinessOnline\nBisnow\nACP New England Construction (acppubs.com/NEC)",
                'research_rules' => "Value at or above \$75M, OR floor area at or above 200,000 sq ft, OR a marquee project: stadium, airport terminal, hospital tower, data centre, semiconductor/battery/EV plant, port, transit megaproject.\nOnly record a value you actually saw stated.\nIf the general contractor is not publicly named, leave it empty and flag the row 'No GC named yet' — that is valuable, not a gap.",
                'research_exclusions' => 'Anything already on the board under a matching name and state. Projects outside the United States.',
                'components' => [
                    $this->valueComponent(),
                    [
                        'key' => 'timing', 'label' => 'How close to mobilisation',
                        // A custom field, not a shared column: only some
                        // verticals have a mobilisation date at all.
                        'source_kind' => 'field', 'source_ref' => 'construction_start',
                        'rule_type' => 'months', 'max_points' => 5,
                        'config' => ['bands' => [
                            ['from' => 0, 'to' => 9, 'points' => 5],
                            ['from' => 10, 'to' => 9999, 'points' => 3],
                            ['from' => -6, 'to' => -1, 'points' => 2],
                            ['from' => -12, 'to' => -7, 'points' => 1],
                            ['from' => -9999, 'to' => -13, 'points' => 0],
                        ], 'unknown' => 3],
                    ],
                    [
                        'key' => 'site_type', 'label' => 'How attractive the site is to theft',
                        'source_kind' => 'column', 'source_ref' => 'category',
                        'rule_type' => 'map', 'max_points' => 9,
                        'config' => ['values' => [
                            'Multifamily' => 9, 'Data Center' => 7, 'Mixed-Use' => 6,
                            'Warehouse/Industrial' => 6, 'Manufacturing' => 5,
                            'Public/Government' => 5, 'Education' => 4, 'Healthcare' => 3,
                            'Sports/Entertainment' => 3, 'Office/Lab' => 2, 'Hospitality' => 0,
                        ], 'default' => 5],
                    ],
                    [
                        'key' => 'contact', 'label' => 'How reachable the decision-maker is',
                        'source_kind' => 'computed', 'source_ref' => 'contactability',
                        'rule_type' => 'presence', 'max_points' => 18,
                        'config' => ['points' => [
                            'contact_name' => 7, 'contact_email' => 4,
                            'contact_phone' => 4, 'vendor_portal' => 3,
                        ]],
                    ],
                    [
                        'key' => 'stage', 'label' => 'How actionable the stage is',
                        'source_kind' => 'column', 'source_ref' => 'stage',
                        'rule_type' => 'map', 'max_points' => 11,
                        'config' => ['values' => [
                            'Awarded' => 11, 'Bidding' => 7, 'Permitting' => 5,
                            'Under Construction' => 1, 'Announced' => 1,
                            'Cancelled/Withdrawn' => 0,
                        ], 'default' => 1],
                    ],
                    [
                        'key' => 'gc_named', 'label' => 'General contractor named',
                        'source_kind' => 'computed', 'source_ref' => 'gc',
                        'rule_type' => 'presence', 'max_points' => 2,
                        'config' => ['points' => ['org' => 2]],
                    ],
                ],
            ],
            [
                'key' => 'datacenters', 'label' => 'Data Centers', 'label_singular' => 'Data Center',
                'key_prefix' => 'dc', 'sort_order' => 20,
                'name_field' => 'facility_name', 'city_field' => 'city', 'state_field' => 'state',
                'source_map' => ['name' => 'facility_name', 'city' => 'city', 'state' => 'state', 'score' => 'priority_score', 'tier_label' => 'priority_tier', 'stage' => 'status', 'category' => 'facility_type', 'org' => 'operator', 'owner_org' => 'parent_company', 'service_note' => 'brand_fit', 'size_sqft' => 'size_sqft', 'power_mw' => 'power_mw', 'source_url' => 'source_url', 'flags' => 'flags'],
                'intro' => 'Every US data center we could source, scored the same way projects are.',
                'score_base' => 20, 'tier_cuts' => ['A' => 63, 'B' => 50, 'C' => 42],
                'research_cadence' => 'daily',
                'research_terms' => 'new campus announcement, groundbreaking, now operational, cancelled, rejected by planning board, withdrawn, paused, moratorium',
                'research_sources' => "Data Center Dynamics\nData Center Frontier\ndatacentercensus.com/changes\nbaxtel.com/news\nsuedatacenters.org\nlocal business journals",
                'research_rules' => "Match to an existing row on facility name plus state, else operator plus city plus state.\nFORWARD ONLY: a facility moves Announced -> Under Construction -> Operational and never backwards. Refuse any backwards claim and report the count.\nAn OPERATIONAL facility is never marked cancelled — only a proposal or an expansion is. When a development agreement ends at a live site, keep the status and add an 'Expansion cancelled' flag with a dated note.\nPaused, rejected or withdrawn: keep the current stage, add an 'On hold' flag and a dated note.",
                'components' => [
                    [
                        'key' => 'build_stage', 'label' => 'Build stage',
                        'source_kind' => 'column', 'source_ref' => 'stage',
                        'rule_type' => 'map', 'max_points' => 30,
                        'config' => ['values' => [
                            'Under Construction' => 30, 'Announced' => 18,
                            'Operational' => 10, 'Cancelled/Withdrawn' => 0,
                        ], 'default' => 10],
                    ],
                    [
                        'key' => 'scale', 'label' => 'Site scale in megawatts',
                        'source_kind' => 'column', 'source_ref' => 'power_mw',
                        'rule_type' => 'bands', 'max_points' => 20,
                        'config' => ['bands' => [
                            ['min' => 1000, 'points' => 20], ['min' => 500, 'points' => 17],
                            ['min' => 200, 'points' => 14], ['min' => 100, 'points' => 11],
                            ['min' => 50, 'points' => 8], ['min' => 1, 'points' => 5],
                        ], 'unknown' => 6, 'below' => 0],
                    ],
                    [
                        'key' => 'facility_type', 'label' => 'Facility type',
                        'source_kind' => 'column', 'source_ref' => 'category',
                        'rule_type' => 'map', 'max_points' => 12,
                        'config' => ['values' => [
                            'Hyperscale campus' => 12, 'Wholesale colocation' => 10,
                            'AI / compute campus' => 11, 'Carrier hotel' => 7,
                            'Enterprise' => 6, 'Retail colocation' => 4,
                        ], 'default' => 6],
                    ],
                    [
                        'key' => 'contact', 'label' => 'How reachable the operator is',
                        'source_kind' => 'computed', 'source_ref' => 'contactability',
                        'rule_type' => 'presence', 'max_points' => 14,
                        'config' => ['points' => [
                            'contact_phone' => 6, 'contact_email' => 5, 'address' => 3,
                        ]],
                    ],
                ],
            ],
            [
                'key' => 'retail', 'label' => 'Retail HQ', 'label_singular' => 'Retail company',
                'key_prefix' => 'retail', 'sort_order' => 30,
                'name_field' => 'company', 'city_field' => 'hq_city', 'state_field' => 'hq_state',
                'source_map' => ['name' => 'company', 'city' => 'hq_city', 'state' => 'hq_state', 'score' => 'score', 'tier_label' => 'tier', 'category' => 'segment', 'subtype' => 'record_type', 'owner_org' => 'parent_company', 'service_note' => 'brand_fit', 'source_url' => 'source_url', 'flags' => 'flags'],
                'intro' => 'Retail chains, shopping-center operators and retail distribution networks, ranked by how worth a call the headquarters is.',
                'score_base' => 30, 'tier_cuts' => ['A' => 85, 'B' => 75, 'C' => 60],
                'research_cadence' => 'monday',
                'research_terms' => 'acquisition, bankruptcy, store closures, headquarters relocation, new VP of asset protection, director of corporate security',
                'research_sources' => "company newsrooms\nRILA and NRF PROTECT speaker rosters\nretail trade press",
                'research_rules' => "A bounded pass: about 25 to 40 lookups, not a re-research.\nNever pattern-guess an email or a phone number — every contact must be read off a published page. If nobody is published, say so on the row and give the title to ask for instead.\nWhere a named contact has provably left, remove the person and say so in the notes rather than leaving a stale name.\nAdd at most 10 genuinely new companies per pass.",
                'components' => [
                    [
                        'key' => 'contact', 'label' => 'How reachable the buyer is',
                        'source_kind' => 'computed', 'source_ref' => 'contactability',
                        'rule_type' => 'presence', 'max_points' => 20,
                        'config' => ['points' => [
                            'contact_name' => 10, 'contact_email' => 6, 'contact_phone' => 4,
                        ]],
                    ],
                ],
            ],
            [
                'key' => 'events', 'label' => 'Event Marketing', 'label_singular' => 'Event company',
                'key_prefix' => 'event', 'sort_order' => 40,
                'name_field' => 'company', 'city_field' => 'hq_city', 'state_field' => 'hq_state',
                'source_map' => ['name' => 'company', 'city' => 'hq_city', 'state' => 'hq_state', 'score' => 'score', 'tier_label' => 'tier', 'category' => 'segment', 'owner_org' => 'parent_company', 'service_note' => 'brand_fit', 'source_url' => 'source_url', 'flags' => 'flags'],
                'intro' => 'Companies that market, produce and promote events — where event security is actually bought.',
                'score_base' => 30, 'tier_cuts' => ['A' => 85, 'B' => 75, 'C' => 60],
                'research_cadence' => 'tuesday',
                'research_terms' => 'acquisition, closure, new festival, new venue contract, director of event operations, head of production',
                'research_sources' => "company sites\nfestival and promoter trade press\nIAVM and trade show press",
                'research_rules' => "A bounded pass of about 25 to 40 lookups.\nAt festivals the promoter buys crowd management directly, often from a national incumbent — note the incumbent where it is published.\nAt trade shows the organiser often does NOT buy security: the general service contractor or the venue does. Record which.\nNever pattern-guess a contact.",
                'components' => [
                    [
                        'key' => 'contact', 'label' => 'How reachable the buyer is',
                        'source_kind' => 'computed', 'source_ref' => 'contactability',
                        'rule_type' => 'presence', 'max_points' => 20,
                        'config' => ['points' => [
                            'contact_name' => 10, 'contact_email' => 6, 'contact_phone' => 4,
                        ]],
                    ],
                ],
            ],
            [
                'key' => 'travel', 'label' => 'Student Travel', 'label_singular' => 'Travel operator',
                'key_prefix' => 'travel', 'sort_order' => 50,
                'name_field' => 'company', 'city_field' => 'hq_city', 'state_field' => 'hq_state',
                'source_map' => ['name' => 'company', 'city' => 'hq_city', 'state' => 'hq_state', 'score' => 'score', 'tier_label' => 'tier', 'category' => 'segment', 'owner_org' => 'parent_company', 'service_note' => 'brand_fit', 'source_url' => 'source_url', 'flags' => 'flags'],
                'intro' => 'Tour operators and agencies that book student groups — the buyers of overnight hotel-corridor posts.',
                'score_base' => 30, 'tier_cuts' => ['A' => 85, 'B' => 75, 'C' => 60],
                'research_cadence' => 'wednesday',
                'research_terms' => 'student tour operator, night security, overnight chaperone policy, hotel corridor security, SYTA supplier',
                'research_sources' => "operator websites and trip handbooks\nSYTA\nstudent travel trade press",
                'research_rules' => "A bounded pass of about 25 to 40 lookups.\nFlag any operator that PUBLISHES buying overnight security — that is the strongest signal on this vertical.\nDo not claim SYTA or any hotel brand mandates overnight security; no such requirement was found published.\nNever pattern-guess a contact.",
                'components' => [
                    [
                        'key' => 'contact', 'label' => 'How reachable the buyer is',
                        'source_kind' => 'computed', 'source_ref' => 'contactability',
                        'rule_type' => 'presence', 'max_points' => 20,
                        'config' => ['points' => [
                            'contact_name' => 10, 'contact_email' => 6, 'contact_phone' => 4,
                        ]],
                    ],
                ],
            ],
            [
                'key' => 'contractors', 'label' => 'Contractors', 'label_singular' => 'Contractor',
                'key_prefix' => 'gc', 'sort_order' => 60,
                'name_field' => 'company', 'city_field' => 'hq_city', 'state_field' => 'hq_state',
                'source_map' => ['name' => 'company', 'city' => 'hq_city', 'state' => 'hq_state', 'category' => 'specialties', 'source_url' => 'source_url'],
                'intro' => 'General contractors and construction managers, with the prequalification portal you have to clear first.',
                'score_base' => 0, 'research_cadence' => 'thursday',
                'research_terms' => 'ENR ranking, new regional office, acquisition, prequalification portal change',
                'research_sources' => "ENR\ncompany sites",
                'research_rules' => 'A bounded pass. Record the subcontractor prequalification route, which is what actually gates a bid.',
                'services' => ['nesa', 'nwss'],
            ],
            [
                'key' => 'installers', 'label' => 'Installers', 'label_singular' => 'Installer',
                'key_prefix' => 'inst', 'sort_order' => 70,
                'name_field' => 'company', 'city_field' => 'city', 'state_field' => 'state',
                'source_map' => ['name' => 'company', 'city' => 'city', 'state' => 'state', 'category' => 'license_type', 'source_url' => 'source_url'],
                'intro' => 'Licensed low-voltage and electrical firms for Video Guard Pro deployments — the install bench, state by state.',
                'score_base' => 0, 'research_cadence' => 'friday',
                'research_terms' => 'acquisition, closure, licence revocation, new branch, low voltage licence',
                'research_sources' => "Security Systems News\nSecurityInfoWatch\nstate licence lookups",
                'research_rules' => "A bounded pass. Every contact must be read off a published page — never pattern-guessed.\nRecord the licence type and number where published.",
                'services' => ['vgp'],
            ],
            [
                'key' => 'guards', 'label' => 'Guard Partners', 'label_singular' => 'Guard company',
                'key_prefix' => 'guard', 'sort_order' => 80,
                'name_field' => 'company', 'city_field' => 'city', 'state_field' => 'state',
                'source_map' => ['name' => 'company', 'city' => 'city', 'state' => 'state', 'category' => 'license_type', 'source_url' => 'source_url'],
                'intro' => 'Licensed guard companies to subcontract Nationwide posts to when a job falls outside New England.',
                'score_base' => 0, 'research_cadence' => 'friday',
                'research_terms' => 'acquisition, merger, ceases operations, licence revocation, new market',
                'research_sources' => "Security Systems News\nSecurityInfoWatch\nstate licensing boards",
                'research_rules' => "A bounded pass. Confirm the licence number against the state board where one is published.\nNever pattern-guess a contact.",
                'services' => ['nwss'],
            ],
        ];
    }
}
