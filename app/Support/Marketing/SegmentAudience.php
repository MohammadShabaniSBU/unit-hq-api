<?php

declare(strict_types=1);

namespace App\Support\Marketing;

use App\Enums\AttributeEntityType;
use App\Models\Contact;
use App\Models\Employee;
use App\Support\Auth\Permission;
use App\Support\Filtering\FilterBuilder;
use App\Support\Filtering\FilterTreeValidator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Live evaluation of a contact filter tree, scoped to the employee.
 */
final class SegmentAudience
{
    /**
     * @param  array<string, mixed>  $filter
     * @return array{op: string, conditions: list<array<string, mixed>>}
     */
    public static function validate(array $filter): array
    {
        try {
            $validated = (new FilterTreeValidator(AttributeEntityType::Contact))->validate($filter);
        } catch (ValidationException $exception) {
            throw SegmentInvalid::fromValidation($exception);
        }

        if ($validated === null || $validated['conditions'] === []) {
            throw ValidationException::withMessages([
                'filter' => [__('errors.segments.filter_required')],
            ]);
        }

        return $validated;
    }

    /**
     * @param  array{op: string, conditions: list<array<string, mixed>>}  $filter
     * @return Builder<Contact>
     */
    public static function query(Employee $employee, array $filter): Builder
    {
        $query = Contact::query()->visibleTo($employee, Permission::ContactView);

        return FilterBuilder::for(AttributeEntityType::Contact)->apply($query, $filter);
    }

    /**
     * @param  array<string, mixed>  $filter
     */
    public static function count(Employee $employee, array $filter): int
    {
        return self::query($employee, self::validate($filter))->count();
    }

    /**
     * @param  array<string, mixed>  $filter
     * @return array{count: int, sample: list<array{id: int, first_name: string, last_name: string, email: string|null, status: string|null}>}
     */
    public static function preview(Employee $employee, array $filter): array
    {
        $query = self::query($employee, self::validate($filter));

        $sample = (clone $query)
            ->orderBy('contacts.id')
            ->limit(20)
            ->get(['contacts.id', 'contacts.first_name', 'contacts.last_name', 'contacts.email', 'contacts.status']);

        return [
            'count' => (clone $query)->count(),
            'sample' => $sample->map(fn (Contact $contact): array => [
                'id' => $contact->id,
                'first_name' => $contact->first_name,
                'last_name' => $contact->last_name,
                'email' => $contact->email,
                'status' => $contact->status?->value,
            ])->all(),
        ];
    }
}
