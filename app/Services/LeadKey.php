<?php

namespace App\Services;

use App\Models\Vertical;

/**
 * The stable identity of a lead.
 *
 * A pipeline note hangs off this string, so it is derived only from things
 * that do not drift: the vertical, the state, and the normalised name. If this
 * algorithm changes, every saved status silently reattaches to a different
 * lead — so it is isolated here and covered by tests, and it matches the keys
 * the published board already uses.
 *
 * Which source field holds the name, the city and the state comes from the
 * vertical row rather than a hardcoded table, so a new line of business needs
 * no change here.
 */
final class LeadKey
{
    /** Lowercase, strip everything that is not a letter or digit, cap at 48. */
    public static function normalise(?string $value): string
    {
        return substr(preg_replace('/[^a-z0-9]+/', '', strtolower((string) $value)) ?? '', 0, 48);
    }

    /**
     * @param  array<string,mixed>  $row
     */
    public static function for(Vertical $vertical, array $row): string
    {
        $name = self::normalise(self::field($row, $vertical->name_field));

        if ($name === '') {
            return '';
        }

        $state = preg_replace('/[^A-Z]/', '',
            strtoupper(self::field($row, $vertical->state_field))) ?? '';

        return sprintf('%s_%s_%s', $vertical->key_prefix, $state !== '' ? $state : 'xx', $name);
    }

    /**
     * A collision-proof variant, used only when two rows produce the same base
     * key. This is not hypothetical: "Stream DFW I & II" and "Stream DFW III"
     * both normalise to "streamdfwiii" — two real facilities in Dallas and
     * Plano that shared one key, and so one pipeline status, on the published
     * board.
     *
     * The suffix comes from the row, never from its position in a list, so the
     * key is identical on every run even after the board re-sorts.
     *
     * @param  array<string,mixed>  $row
     */
    public static function disambiguate(Vertical $vertical, array $row, string $base): string
    {
        $city = substr(self::normalise(self::field($row, $vertical->city_field)), 0, 14);

        if ($city !== '') {
            return $base.'__'.$city;
        }

        $seed = implode('|', array_map(
            fn (string $f): string => self::field($row, $f),
            ['address', 'operator', 'owner_developer', 'website'],
        ));

        return $base.'__'.substr(sha1($seed), 0, 6);
    }

    /** @param  array<string,mixed>  $row */
    private static function field(array $row, string $key): string
    {
        $value = $row[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
