<?php

namespace App\Console\Commands;

use App\Models\Licensing;
use App\Models\Vertical;
use App\Services\BoardImporter;
use App\Services\LeadKey;
use App\Services\LeadUpserter;
use Illuminate\Console\Command;

/**
 * Loads an exported board into the database.
 *
 * Takes the keys it finds in the file and matches them against the verticals
 * table, so it imports whatever verticals exist rather than a list baked into
 * this class. Idempotent: run it twice and the second pass reports everything
 * unchanged.
 */
class ImportBoard extends Command
{
    protected $signature = 'groundbreak:import
                            {file : path to the exported board as JSON}
                            {--dry-run : parse and report without writing}';

    protected $description = 'Import an exported Groundbreak board into the database';

    public function handle(BoardImporter $importer, LeadUpserter $upserter): int
    {
        $path = $this->argument('file');

        if (! is_file($path)) {
            $this->error("No such file: {$path}");

            return self::FAILURE;
        }

        $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $verticals = Vertical::all()->keyBy('key');

        if ($verticals->isEmpty()) {
            $this->error('No verticals are defined. Run: php artisan db:seed --class=VerticalSeeder');

            return self::FAILURE;
        }

        // Two passes over the keys, so a collision suffix never depends on
        // which row happened to be read first.
        $staged = [];
        $baseCounts = [];

        foreach ($verticals as $key => $vertical) {
            foreach ($data[$key] ?? [] as $row) {
                $base = LeadKey::for($vertical, $row);

                if ($base === '') {
                    $this->warn("  skipped a {$key} row with no usable name");

                    continue;
                }

                $staged[] = [$vertical, $row, $base];
                $baseCounts[$base] = ($baseCounts[$base] ?? 0) + 1;
            }
        }

        $counts = [];

        foreach ($staged as [$vertical, $row, $base]) {
            $leadKey = ($baseCounts[$base] ?? 0) > 1
                ? LeadKey::disambiguate($vertical, $row, $base)
                : $base;

            if (! $this->option('dry-run')) {
                $importer->import($vertical, $row, $leadKey);
            }

            $counts[$vertical->key] = ($counts[$vertical->key] ?? 0) + 1;
        }

        if (! $this->option('dry-run')) {
            $this->importLicensing($data);
        }

        $this->table(
            ['vertical', 'in file', 'imported'],
            $verticals->map(fn (Vertical $v): array => [
                $v->key, count($data[$v->key] ?? []), $counts[$v->key] ?? 0,
            ])->values()->all(),
        );

        $this->newLine();
        $this->line(sprintf(
            '  leads %d · created %d · updated %d · unchanged %d',
            $importer->stats['leads'], $upserter->stats['created'],
            $upserter->stats['updated'], $upserter->stats['unchanged'],
        ));
        $this->line(sprintf(
            '  custom fields discovered %d · values stored %d · service links %d',
            $importer->stats['fields_created'], $importer->stats['values'],
            $importer->stats['services'],
        ));
        $this->line(sprintf(
            '  stage claims refused %d · over-long values clamped %d',
            $upserter->stats['refused'], $upserter->stats['clamped'],
        ));

        $collisions = array_filter($baseCounts, fn (int $n): bool => $n > 1);

        if ($collisions !== []) {
            $this->newLine();
            $this->warn(sprintf('  %d key collision group(s), all rows kept with city-suffixed keys:',
                count($collisions)));

            foreach (array_keys($collisions) as $base) {
                $this->line("    {$base}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Licensing rules are reference data, not leads: they answer "may we
     * legally work here", so they carry no vertical and no pipeline.
     *
     * @param  array<string,mixed>  $data
     */
    private function importLicensing(array $data): void
    {
        foreach ([['cctv', 'cctv', 'cctv_license_required'],
            ['guard', 'guardlic', 'guard_license_required']] as [$kind, $source, $requiredField]) {
            foreach ($data[$source] ?? [] as $row) {
                Licensing::updateOrCreate(
                    ['kind' => $kind, 'state' => (string) ($row['state'] ?? '')],
                    [
                        'state_name' => (string) ($row['state_name'] ?? ''),
                        'required' => (string) ($row[$requiredField] ?? ''),
                        'agency' => (string) ($row['issuing_agency'] ?? ''),
                        'lookup_url' => (string) ($row['lookup_url'] ?? ''),
                        'apply_url' => (string) ($row['apply_url'] ?? ''),
                        'payload' => $row,
                    ],
                );
            }
        }
    }
}
