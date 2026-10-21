<?php

namespace App\Services;

use App\Models\FieldDefinition;
use App\Models\Lead;
use App\Models\LeadValue;
use App\Models\Service;
use App\Models\Vertical;
use Illuminate\Support\Str;

/**
 * Loads raw rows into a vertical, taking every decision from the database.
 *
 * Nothing here knows the eight original lead types. The vertical row supplies
 * which incoming field holds the name, the city and the state; its source_map
 * says which shared column each field feeds; and anything left over becomes a
 * custom field definition on first sight. So importing a brand-new kind of
 * business works the day Brian creates the vertical, with no code change.
 */
final class BoardImporter
{
    /** @var array{leads:int,fields_created:int,values:int,services:int} */
    public array $stats = ['leads' => 0, 'fields_created' => 0, 'values' => 0, 'services' => 0];

    /** @var array<string, FieldDefinition> keyed "verticalId:fieldKey" */
    private array $fieldCache = [];

    /** @var array<string,int>|null service key to id */
    private ?array $serviceIds = null;

    /** Shared columns an import may write. Anything else becomes a field. */
    private const COLUMNS = [
        'name', 'city', 'state', 'score', 'tier_label', 'stage', 'category',
        'subtype', 'org', 'owner_org', 'service_note', 'value_usd', 'size_sqft',
        'power_mw', 'rfp_deadline', 'source_type', 'source_url', 'flags',
    ];

    private const NUMERIC = ['score', 'value_usd', 'size_sqft', 'power_mw'];

    public function __construct(
        private readonly LeadUpserter $upserter,
        private readonly ContactExtractor $contacts,
    ) {}

    /**
     * @param  array<string,mixed>  $row  a raw source row
     */
    public function import(Vertical $vertical, array $row, string $leadKey): Lead
    {
        [$attributes, $custom] = $this->split($vertical, $row);
        $attributes['vertical_id'] = $vertical->id;
        $attributes['lead_key'] = $leadKey;

        $lead = $this->upserter->upsert(
            $attributes,
            $custom,
            $this->contacts->from($vertical, $row),
        );

        $this->syncCustomFields($vertical, $lead, $custom);
        $this->syncServices($lead, (string) ($attributes['service_note'] ?? ''));
        $this->stats['leads']++;

        return $lead;
    }

    /**
     * Separate the shared columns from everything else.
     *
     * @param  array<string,mixed>  $row
     * @return array{array<string,mixed>, array<string,mixed>}
     */
    private function split(Vertical $vertical, array $row): array
    {
        $map = $vertical->source_map ?? [];
        $attributes = [];

        foreach ($map as $column => $source) {
            if (! in_array($column, self::COLUMNS, true)) {
                continue;
            }

            $raw = $row[$source] ?? '';

            $attributes[$column] = match (true) {
                in_array($column, self::NUMERIC, true) => $this->number($raw),
                $column === 'rfp_deadline' => $this->date($raw),
                default => trim((string) $raw),
            };
        }

        [$attributes['tier'], $attributes['tier_label']] =
            $this->tier((string) ($attributes['tier_label'] ?? ''));

        if (($attributes['source_type'] ?? '') === '') {
            $attributes['source_type'] = 'researched';
        }

        // Whatever the map did not claim is a custom field.
        $custom = array_diff_key($row, array_flip(array_values($map)) + ['contacts' => true]);

        return [$attributes, $custom];
    }

    /**
     * Create a field definition the first time a key is seen, then store the
     * value in its typed column.
     *
     * @param  array<string,mixed>  $custom
     */
    private function syncCustomFields(Vertical $vertical, Lead $lead, array $custom): void
    {
        foreach ($custom as $key => $value) {
            if ($value === null || $value === '' || is_array($value)) {
                continue;
            }

            $definition = $this->fieldFor($vertical, (string) $key, $value);
            $column = $definition->valueColumn();

            LeadValue::updateOrCreate(
                ['lead_id' => $lead->id, 'field_definition_id' => $definition->id],
                [$column => $this->castFor($definition->type, $value)],
            );
            $this->stats['values']++;
        }
    }

    private function fieldFor(Vertical $vertical, string $key, mixed $sample): FieldDefinition
    {
        $cacheKey = $vertical->id.':'.$key;

        if (isset($this->fieldCache[$cacheKey])) {
            return $this->fieldCache[$cacheKey];
        }

        $existing = FieldDefinition::where('vertical_id', $vertical->id)
            ->where('key', $key)->first();

        if ($existing === null) {
            $existing = FieldDefinition::create([
                'vertical_id' => $vertical->id,
                'key' => $key,
                'label' => Str::headline($key),
                'type' => $this->inferType($key, $sample),
                'sort_order' => 100,
            ]);
            $this->stats['fields_created']++;
        }

        return $this->fieldCache[$cacheKey] = $existing;
    }

    /**
     * A first guess at a field's type from its name and a sample value. Brian
     * can change it in the UI afterwards; guessing wrong costs an edit, not a
     * migration, which is the point of storing values by type rather than
     * by column.
     */
    private function inferType(string $key, mixed $sample): string
    {
        $text = (string) $sample;

        return match (true) {
            str_contains($key, 'email') => 'email',
            str_contains($key, 'phone') => 'phone',
            str_contains($key, 'url') || str_contains($key, 'website')
                || str_contains($key, 'portal') => 'url',
            $text === 'True' || $text === 'False' => 'boolean',
            preg_match('/^\d{4}-\d{2}(-\d{2})?$/', $text) === 1 => 'date',
            str_contains($key, '_usd') || str_contains($key, 'value') => 'money',
            is_numeric($text) && ! str_starts_with($text, '0') => 'number',
            mb_strlen($text) > 160 => 'longtext',
            default => 'text',
        };
    }

    private function castFor(string $type, mixed $value): mixed
    {
        $text = trim((string) $value);

        return match ($type) {
            'boolean' => $text === 'True' || $text === '1' || strtolower($text) === 'true',
            'number', 'money' => $this->number($text),
            'date' => $this->date($text),
            default => $text,
        };
    }

    /**
     * Attach the services a lead is a candidate for, reading them out of the
     * prose the published board used.
     *
     * That column had drifted to 197 distinct spellings of three services, so
     * the text is matched rather than parsed, and the original sentence stays
     * on the lead in service_note because on 184 rows it explains which brand
     * covers which location. Nothing inferred here is marked confirmed — a
     * rep ticking the box is what makes it certain.
     */
    private function syncServices(Lead $lead, string $note): void
    {
        if ($note === '' || $note === '—') {
            return;
        }

        $this->serviceIds ??= Service::pluck('id', 'key')->all();
        $matched = [];

        if (preg_match('/new england security|\bnesa\b/i', $note) === 1) {
            $matched[] = $this->serviceIds['nesa'];
        }

        if (preg_match('/nationwide|\bnwss\b/i', $note) === 1) {
            $matched[] = $this->serviceIds['nwss'];
        }

        if (preg_match('/video guard|\bvgp\b/i', $note) === 1) {
            $matched[] = $this->serviceIds['vgp'];
        }

        if ($matched === []) {
            return;
        }

        $lead->services()->syncWithoutDetaching(
            array_fill_keys($matched, ['is_confirmed' => false]),
        );
        $this->stats['services'] += count($matched);
    }

    /** @return array{?string, string} */
    private function tier(string $label): array
    {
        if ($label === '') {
            return [null, ''];
        }

        if (preg_match('/dead/i', $label) === 1) {
            return ['X', $label];
        }

        return [preg_match('/^\s*([ABCD])\b/', $label, $m) === 1 ? $m[1] : null, $label];
    }

    private function number(mixed $raw): ?float
    {
        $digits = preg_replace('/[^0-9.]/', '', (string) $raw) ?? '';

        return ($digits === '' || $digits === '.' || (float) $digits <= 0) ? null : (float) $digits;
    }

    private function date(mixed $raw): ?string
    {
        return preg_match('/^\s*(\d{4}-\d{2}-\d{2})/', (string) $raw, $m) === 1 ? $m[1] : null;
    }
}
