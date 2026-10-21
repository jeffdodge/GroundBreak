<?php

namespace App\Services;

use App\Models\Vertical;

/**
 * Pulls contacts out of a raw source row.
 *
 * The published board stored them three different ways — an array on the
 * company verticals, flat contact_* fields on projects, operator-level
 * switchboards on data centres — and buried more in scalar fields where no
 * query could reach them. All of it normalises to one shape here.
 *
 * The shapes are recognised by the fields present, not by the vertical's name,
 * so a new vertical whose source happens to use `contacts` or `contact_email`
 * is handled without being added to a list.
 */
final class ContactExtractor
{
    /**
     * Extra numbers worth keeping as contacts, as source field => title.
     * These were scalar columns on the old board; as contact rows they become
     * searchable.
     */
    private const SWITCHBOARDS = [
        'hq_phone' => 'Corporate switchboard',
        'main_phone' => 'Operator main line',
        'sales_phone' => 'Operator sales line',
        'gc_office_phone' => 'GC office',
        'phone' => 'Company line',
    ];

    private const MAILBOXES = [
        'sales_email' => 'Sales mailbox',
        'email' => 'Company mailbox',
    ];

    /**
     * @param  array<string,mixed>  $row
     * @return list<array<string,mixed>>
     */
    public function from(Vertical $vertical, array $row): array
    {
        $out = [];
        $order = 0;

        // An array of published contacts, as the company verticals carry.
        foreach ($row['contacts'] ?? [] as $contact) {
            $name = $this->get($contact, 'name');
            $email = $this->get($contact, 'email');
            $phone = $this->get($contact, 'phone');

            if ($name === '' && $email === '' && $phone === '') {
                continue;
            }

            $out[] = [
                'name' => $name,
                'title' => $this->get($contact, 'title'),
                'email' => $email,
                'phone' => $phone,
                'confidence' => $contact['confidence'] ?? null,
                'audit' => $this->get($contact, 'audit'),
                'is_mailbox' => $name === '',
                'source_url' => $this->get($contact, 'source_url'),
                'sort_order' => $order++,
            ];
        }

        // Flat contact_* fields, as projects carry.
        if ($this->get($row, 'contact_name') !== ''
            || $this->get($row, 'contact_email') !== ''
            || $this->get($row, 'contact_phone') !== '') {
            $out[] = [
                'name' => $this->get($row, 'contact_name'),
                'title' => $this->get($row, 'contact_title'),
                'email' => $this->get($row, 'contact_email'),
                'phone' => $this->get($row, 'contact_phone'),
                'confidence' => $row['contact_confidence'] ?? null,
                'audit' => '',
                'is_mailbox' => $this->get($row, 'contact_name') === '',
                'source_url' => $this->get($row, 'source_url'),
                'sort_order' => $order++,
            ];
        }

        foreach (self::SWITCHBOARDS as $field => $title) {
            $phone = $this->get($row, $field);

            if ($phone === '') {
                continue;
            }

            $out[] = [
                'name' => '', 'title' => $title, 'email' => '', 'phone' => $phone,
                'confidence' => null, 'audit' => '', 'is_mailbox' => true,
                'source_url' => $this->get($row, 'website') ?: $this->get($row, 'contact_url'),
                'sort_order' => 900 + $order++,
            ];
        }

        foreach (self::MAILBOXES as $field => $title) {
            $email = $this->get($row, $field);

            if ($email === '' || ! str_contains($email, '@')) {
                continue;
            }

            $out[] = [
                'name' => '', 'title' => $title, 'email' => $email, 'phone' => '',
                'confidence' => null, 'audit' => '', 'is_mailbox' => true,
                'source_url' => $this->get($row, 'website') ?: $this->get($row, 'contact_url'),
                'sort_order' => 950 + $order++,
            ];
        }

        return $out;
    }

    /** @param  array<string,mixed>  $row */
    private function get(array $row, string $key): string
    {
        $value = $row[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
