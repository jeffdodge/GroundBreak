<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\ScoreComponent;
use App\Models\Vertical;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Turns a vertical's score_components rows into a number between 0 and 100.
 *
 * This replaces a hand-written Python formula per lead type. The rules are
 * data, so Brian can retune a weight or score a brand-new vertical without a
 * developer — which was the point of the exercise.
 *
 * Four rule types cover every component the published board used:
 *
 *   bands    numeric thresholds, highest match wins (contract value)
 *   map      an exact value to a point count (site type, build stage)
 *   presence points for each named input that is filled in (reachability)
 *   months   how far a date is from today, in months (mobilisation timing)
 */
final class LeadScorer
{
    /** @var array<int, Collection<int, ScoreComponent>> */
    private array $componentCache = [];

    public function __construct(private readonly ?CarbonImmutable $today = null) {}

    /**
     * Score a lead and return the total with its working shown, so the board
     * can keep explaining itself the way it always has ("value 11/15 ·
     * timing 5/5 · contact 0/18").
     *
     * @return array{score:int, tier:string, parts:array<string, array{points:int, max:int, label:string}>}
     */
    public function score(Lead $lead): array
    {
        $vertical = $lead->vertical;

        // A cancelled or withdrawn lead scores zero whatever else is true of
        // it. It stays on the board so it is not re-researched as fresh.
        if ($this->isDead($lead)) {
            return ['score' => 0, 'tier' => 'X', 'parts' => []];
        }

        $parts = [];
        $total = $vertical->score_base;

        foreach ($this->componentsFor($vertical) as $component) {
            $points = $this->pointsFor($component, $lead);
            $total += $points;
            $parts[$component->key] = [
                'points' => $points,
                'max' => $component->max_points,
                'label' => $component->label,
            ];
        }

        $score = max(0, min(100, $total));

        return ['score' => $score, 'tier' => $vertical->tierFor($score), 'parts' => $parts];
    }

    private function isDead(Lead $lead): bool
    {
        return preg_match('/cancel|withdraw/i', (string) $lead->stage) === 1;
    }

    /** @return Collection<int, ScoreComponent> */
    private function componentsFor(Vertical $vertical)
    {
        return $this->componentCache[$vertical->id] ??= $vertical->scoreComponents;
    }

    private function pointsFor(ScoreComponent $component, Lead $lead): int
    {
        $config = $component->config;

        return match ($component->rule_type) {
            'bands' => $this->bands($config, $this->input($component, $lead)),
            'map' => $this->map($config, $this->input($component, $lead)),
            'presence' => $this->presence($config, $lead),
            'months' => $this->months($config, $this->input($component, $lead)),
            default => 0,
        };
    }

    /** The raw input value a component reads. */
    private function input(ScoreComponent $component, Lead $lead): mixed
    {
        return match ($component->source_kind) {
            'column' => $lead->{$component->source_ref} ?? null,
            'field' => $lead->customValue($component->source_ref),
            default => null,
        };
    }

    /**
     * Numeric thresholds, highest match first. An absent value takes
     * `unknown` rather than zero, because "not published" is not the same as
     * "small" — the board has always scored a thin row mid-band instead of
     * punishing it for what we failed to find.
     *
     * @param  array{bands?:list<array{min:float,points:int}>, unknown?:int, below?:int}  $config
     */
    private function bands(array $config, mixed $value): int
    {
        $number = $this->toNumber($value);

        if ($number === null) {
            return (int) ($config['unknown'] ?? 0);
        }

        foreach ($config['bands'] ?? [] as $band) {
            if ($number >= (float) $band['min']) {
                return (int) $band['points'];
            }
        }

        return (int) ($config['below'] ?? 0);
    }

    /** @param  array{values?:array<string,int>, default?:int}  $config */
    private function map(array $config, mixed $value): int
    {
        $key = trim((string) $value);

        return (int) ($config['values'][$key] ?? $config['default'] ?? 0);
    }

    /**
     * Points for each named input that is actually filled in. Used for
     * reachability: a named person with an email outranks a switchboard.
     *
     * @param  array{points?:array<string,int>}  $config
     */
    private function presence(array $config, Lead $lead): int
    {
        $total = 0;

        foreach ($config['points'] ?? [] as $what => $points) {
            if ($this->has($lead, $what)) {
                $total += (int) $points;
            }
        }

        return $total;
    }

    /** Named facts a presence rule can ask about. */
    private function has(Lead $lead, string $what): bool
    {
        return match ($what) {
            'contact_name' => $lead->contacts->contains(fn ($c): bool => trim($c->name) !== ''),
            'contact_email' => $lead->contacts->contains(fn ($c): bool => trim($c->email) !== ''),
            'contact_phone' => $lead->contacts->contains(fn ($c): bool => trim($c->phone) !== ''),
            'org' => trim((string) $lead->org) !== '',
            'owner_org' => trim((string) $lead->owner_org) !== '',
            'address' => trim((string) $lead->customValue('address')) !== '',
            'vendor_portal' => trim((string) $lead->customValue('gc_vendor_portal')) !== ''
                || trim((string) $lead->customValue('vendor_portal_url')) !== ''
                || trim((string) $lead->customValue('vendor_portal')) !== '',
            default => trim((string) ($lead->{$what} ?? $lead->customValue($what))) !== '',
        };
    }

    /**
     * How far a date sits from today, in months, scored in bands. Negative
     * means it has already happened.
     *
     * @param  array{bands?:list<array{from:int,to:int,points:int}>, unknown?:int}  $config
     */
    private function months(array $config, mixed $value): int
    {
        $month = $this->toMonth($value);

        if ($month === null) {
            return (int) ($config['unknown'] ?? 0);
        }

        foreach ($config['bands'] ?? [] as $band) {
            if ($month >= (int) $band['from'] && $month <= (int) $band['to']) {
                return (int) $band['points'];
            }
        }

        return 0;
    }

    private function toNumber(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $digits = preg_replace('/[^0-9.]/', '', (string) $value) ?? '';

        return ($digits === '' || $digits === '.' || (float) $digits <= 0) ? null : (float) $digits;
    }

    /** "2026-05" or "2026-05-01" to a count of months from today. */
    private function toMonth(mixed $value): ?int
    {
        if (preg_match('/^\s*(\d{4})-(\d{1,2})/', (string) $value, $m) !== 1) {
            return null;
        }

        $month = (int) $m[2];

        if ($month < 1 || $month > 12) {
            return null;
        }

        $today = $this->today ?? CarbonImmutable::now();

        return ((int) $m[1] - $today->year) * 12 + ($month - $today->month);
    }
}
