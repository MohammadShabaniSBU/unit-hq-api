<?php

declare(strict_types=1);

namespace App\Support\Marketing;

use App\Models\UnitClass;

/**
 * Enquiry field config stored on signup_forms.enquiry_fields.
 */
final class EnquiryFields
{
    /** @var list<string> */
    public const KEYS = ['desired_size', 'unit_class_id', 'expected_move_in', 'message'];

    /**
     * @param  array<string, mixed>|null  $input
     * @return array<string, array<string, mixed>>
     */
    public static function sanitize(?array $input): array
    {
        $input ??= [];
        $out = [];

        foreach (self::KEYS as $key) {
            $row = is_array($input[$key] ?? null) ? $input[$key] : [];
            $field = [
                'enabled' => (bool) ($row['enabled'] ?? false),
                'required' => (bool) ($row['required'] ?? false),
            ];

            if ($key === 'unit_class_id') {
                $options = [];
                foreach (is_array($row['options'] ?? null) ? $row['options'] : [] as $id) {
                    if (is_numeric($id)) {
                        $options[] = (int) $id;
                    }
                }
                $field['options'] = array_values(array_unique($options));
            }

            $out[$key] = $field;
        }

        return $out;
    }

    public static function enabled(?array $stored, string $key): bool
    {
        $row = is_array($stored) && is_array($stored[$key] ?? null) ? $stored[$key] : [];

        return (bool) ($row['enabled'] ?? false);
    }

    public static function required(?array $stored, string $key): bool
    {
        return self::enabled($stored, $key) && (bool) (is_array($stored[$key] ?? null) ? ($stored[$key]['required'] ?? false) : false);
    }

    /**
     * @return list<int>
     */
    public static function unitClassOptions(?array $stored): array
    {
        $row = is_array($stored) && is_array($stored['unit_class_id'] ?? null) ? $stored['unit_class_id'] : [];
        $options = [];
        foreach (is_array($row['options'] ?? null) ? $row['options'] : [] as $id) {
            if (is_numeric($id)) {
                $options[] = (int) $id;
            }
        }

        return array_values(array_unique($options));
    }

    /**
     * Public form payload. Unit class options resolve to id + label.
     *
     * @param  array<string, mixed>|null  $stored
     * @return array<string, array<string, mixed>>
     */
    public static function forPublic(?array $stored): array
    {
        $clean = self::sanitize($stored);
        $ids = $clean['unit_class_id']['options'];
        $labels = $ids === []
            ? collect()
            : UnitClass::query()->whereIn('id', $ids)->pluck('label', 'id');

        $options = [];
        foreach ($ids as $id) {
            $label = $labels->get($id) ?? $labels->get((string) $id);
            if (! is_string($label) || $label === '') {
                continue;
            }
            $options[] = ['id' => $id, 'label' => $label];
        }

        $clean['unit_class_id']['options'] = $options;

        return $clean;
    }
}
