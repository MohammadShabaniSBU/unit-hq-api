<?php

declare(strict_types=1);

namespace App\Support\Filtering;

use App\Enums\AttributeEntityType;
use App\Enums\AttributeType;
use App\Models\AttributeDefinition;
use App\Models\AttributeValue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Walks a validated filter tree and applies native where / EAV whereExists clauses.
 */
final class FilterBuilder
{
    public function __construct(
        private readonly AttributeEntityType $entityType,
        private readonly string $table,
    ) {}

    public static function for(AttributeEntityType|string $entityType): self
    {
        $type = $entityType instanceof AttributeEntityType
            ? $entityType
            : AttributeEntityType::from($entityType);

        return new self($type, match ($type) {
            AttributeEntityType::Contact => 'contacts',
            AttributeEntityType::Deal => 'deals',
            AttributeEntityType::Offer => 'offers',
            AttributeEntityType::Reservation => 'reservations',
            AttributeEntityType::Unit => 'units',
            AttributeEntityType::Contract => 'contracts',
        });
    }

    /**
     * @param  array{op: string, conditions: list<array<string, mixed>>}  $filter
     */
    public function apply(Builder $query, array $filter): Builder
    {
        if (($filter['conditions'] ?? []) === []) {
            return $query;
        }

        $query->where(function (Builder $q) use ($filter): void {
            $this->applyGroupContents($q, $filter);
        });

        return $query;
    }

    /**
     * @param  list<array{field: string, dir?: string}>  $sorts
     */
    public function applySort(Builder $query, array $sorts): Builder
    {
        if ($sorts === []) {
            return $query->latest("{$this->table}.created_at");
        }

        $customSortApplied = false;

        foreach ($sorts as $sort) {
            $field = $sort['field'];
            $dir = strtolower((string) ($sort['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

            if (str_starts_with($field, 'attr:')) {
                if ($customSortApplied) {
                    continue;
                }

                $definitionId = AttributeFieldResolver::parseDefinitionId($field);

                if ($definitionId === null) {
                    throw new InvalidArgumentException("Invalid sort field [{$field}].");
                }

                $this->applyCustomAttributeSort($query, $definitionId, $dir);
                $customSortApplied = true;

                continue;
            }

            $native = FilterableFields::find($this->entityType, $field);

            if ($native === null || $native->column === null) {
                throw new InvalidArgumentException("Unknown sort field [{$field}].");
            }

            $query->orderBy("{$this->table}.{$native->column}", $dir);
        }

        return $query;
    }

    /**
     * @param  array{op: string, conditions: list<array<string, mixed>>}  $group
     */
    private function applyGroupContents(Builder $query, array $group): void
    {
        $isOr = strtolower((string) ($group['op'] ?? 'and')) === 'or';
        $first = true;

        foreach ($group['conditions'] ?? [] as $condition) {
            $boolean = $first ? 'and' : ($isOr ? 'or' : 'and');
            $first = false;

            if (isset($condition['field'])) {
                $this->applyCondition($query, $condition, $boolean);

                continue;
            }

            $method = $boolean === 'or' ? 'orWhere' : 'where';
            $query->{$method}(function (Builder $nested) use ($condition): void {
                $this->applyGroupContents($nested, $condition);
            });
        }
    }

    /**
     * @param  array{field: string, op: string, value: mixed}  $condition
     */
    private function applyCondition(Builder $query, array $condition, string $boolean): void
    {
        $field = $condition['field'];
        $op = $condition['op'];
        $value = $condition['value'] ?? null;

        $definitionId = AttributeFieldResolver::parseDefinitionId($field);

        if ($definitionId !== null) {
            $definition = AttributeFieldResolver::resolve($this->entityType, $definitionId)
                ?? AttributeDefinition::query()->find($definitionId);

            if ($definition === null) {
                throw new InvalidArgumentException("Unknown attribute field [{$field}].");
            }

            $this->applyAttributeCondition($query, $definition, $op, $value, $boolean);

            return;
        }

        $native = FilterableFields::assertExists($this->entityType, $field);
        $this->applyNativeCondition($query, $native, $op, $value, $boolean);
    }

    private function applyNativeCondition(
        Builder $query,
        FilterableField $field,
        string $op,
        mixed $value,
        string $boolean,
    ): void {
        $column = "{$this->table}.{$field->column}";
        $method = $boolean === 'or' ? 'orWhere' : 'where';
        $inMethod = $boolean === 'or' ? 'orWhereIn' : 'whereIn';
        $betweenMethod = $boolean === 'or' ? 'orWhereBetween' : 'whereBetween';

        match ($op) {
            'eq' => $query->{$method}($column, '=', $value),
            'neq' => $query->{$method}(function (Builder $q) use ($column, $value): void {
                $q->where($column, '!=', $value)->orWhereNull($column);
            }),
            'gt' => $query->{$method}($column, '>', $value),
            'gte' => $query->{$method}($column, '>=', $value),
            'lt' => $query->{$method}($column, '<', $value),
            'lte' => $query->{$method}($column, '<=', $value),
            'before' => $query->{$method}($column, '<', $value),
            'after' => $query->{$method}($column, '>', $value),
            'contains' => $query->{$method}($column, 'like', '%'.$value.'%'),
            'in' => $query->{$inMethod}($column, $value),
            'between' => $query->{$betweenMethod}($column, $value),
            'is_empty' => $query->{$method}(function (Builder $q) use ($column): void {
                $q->whereNull($column)->orWhere($column, '');
            }),
            default => throw new InvalidArgumentException("Unsupported native operator [{$op}]."),
        };
    }

    private function applyAttributeCondition(
        Builder $query,
        AttributeDefinition $definition,
        string $op,
        mixed $value,
        string $boolean,
    ): void {
        if ($op === 'is_empty') {
            $this->applyExists($query, $definition, $boolean, exists: false);

            return;
        }

        if ($definition->type === AttributeType::Multiselect) {
            $this->applyMultiselectCondition($query, $definition, $op, $value, $boolean);

            return;
        }

        if ($op === 'neq') {
            // Missing row counts as not-equal.
            $this->applyExists($query, $definition, $boolean, exists: false, constrain: function (QueryBuilder $sub) use ($definition, $value): void {
                $this->constrainTypedValue($sub, $definition, 'eq', $value);
            });

            return;
        }

        if ($op === 'between') {
            $this->applyExists($query, $definition, $boolean, exists: true, constrain: function (QueryBuilder $sub) use ($definition, $value): void {
                $column = $this->typedColumn($definition->type);
                $sub->whereBetween("av.{$column}", $value);
            });

            return;
        }

        if ($op === 'in') {
            $this->applyExists($query, $definition, $boolean, exists: true, constrain: function (QueryBuilder $sub) use ($definition, $value): void {
                $column = $this->typedColumn($definition->type);
                $sub->whereIn("av.{$column}", $value);
            });

            return;
        }

        $this->applyExists($query, $definition, $boolean, exists: true, constrain: function (QueryBuilder $sub) use ($definition, $op, $value): void {
            $this->constrainTypedValue($sub, $definition, $op, $value);
        });
    }

    /**
     * @param  list<int>|int  $value
     */
    private function applyMultiselectCondition(
        Builder $query,
        AttributeDefinition $definition,
        string $op,
        mixed $value,
        string $boolean,
    ): void {
        $optionIds = array_map('intval', (array) $value);

        if ($op === 'all_of') {
            $method = $boolean === 'or' ? 'orWhere' : 'where';
            $query->{$method}(function (Builder $q) use ($definition, $optionIds): void {
                foreach ($optionIds as $optionId) {
                    $this->applyExists($q, $definition, 'and', exists: true, constrain: function (QueryBuilder $sub) use ($optionId): void {
                        $sub->join('attribute_value_options as avo', 'avo.attribute_value_id', '=', 'av.id')
                            ->where('avo.attribute_option_id', $optionId);
                    });
                }
            });

            return;
        }

        if ($op === 'none_of') {
            $this->applyExists($query, $definition, $boolean, exists: false, constrain: function (QueryBuilder $sub) use ($optionIds): void {
                $sub->join('attribute_value_options as avo', 'avo.attribute_value_id', '=', 'av.id')
                    ->whereIn('avo.attribute_option_id', $optionIds);
            });

            return;
        }

        // any_of
        $this->applyExists($query, $definition, $boolean, exists: true, constrain: function (QueryBuilder $sub) use ($optionIds): void {
            $sub->join('attribute_value_options as avo', 'avo.attribute_value_id', '=', 'av.id')
                ->whereIn('avo.attribute_option_id', $optionIds);
        });
    }

    /**
     * @param  callable(QueryBuilder): void|null  $constrain
     */
    private function applyExists(
        Builder $query,
        AttributeDefinition $definition,
        string $boolean,
        bool $exists,
        ?callable $constrain = null,
    ): void {
        $method = match (true) {
            $exists && $boolean === 'or' => 'orWhereExists',
            $exists => 'whereExists',
            $boolean === 'or' => 'orWhereNotExists',
            default => 'whereNotExists',
        };

        $table = $this->table;
        $definitionId = $definition->id;

        $query->{$method}(function (QueryBuilder $sub) use ($table, $definitionId, $constrain): void {
            $sub->select(DB::raw(1))
                ->from('attribute_values as av')
                ->whereColumn('av.entity_id', "{$table}.id")
                ->where('av.definition_id', $definitionId);

            if ($constrain !== null) {
                $constrain($sub);
            }
        });
    }

    private function constrainTypedValue(
        QueryBuilder $sub,
        AttributeDefinition $definition,
        string $op,
        mixed $value,
    ): void {
        $column = "av.".$this->typedColumn($definition->type);

        $sqlOp = match ($op) {
            'eq' => '=',
            'neq' => '!=',
            'gt', 'after' => '>',
            'gte' => '>=',
            'lt', 'before' => '<',
            'lte' => '<=',
            'contains' => 'like',
            default => throw new InvalidArgumentException("Unsupported attribute operator [{$op}]."),
        };

        if ($op === 'contains') {
            $sub->where($column, 'like', '%'.$value.'%');

            return;
        }

        $sub->where($column, $sqlOp, $value);
    }

    private function typedColumn(AttributeType $type): string
    {
        return match ($type) {
            AttributeType::Text => 'value_text',
            AttributeType::Number => 'value_number',
            AttributeType::Date => 'value_date',
            AttributeType::Boolean => 'value_boolean',
            AttributeType::Select => 'value_option_id',
            AttributeType::Multiselect => 'value_text',
        };
    }

    private function applyCustomAttributeSort(Builder $query, int $definitionId, string $dir): void
    {
        $definition = AttributeFieldResolver::resolve($this->entityType, $definitionId)
            ?? AttributeDefinition::query()->find($definitionId);

        if ($definition === null) {
            throw new InvalidArgumentException("Unknown attribute sort field [attr:{$definitionId}].");
        }

        if ($definition->type === AttributeType::Multiselect) {
            throw new InvalidArgumentException('Sorting by multiselect attributes is not supported.');
        }

        $column = $this->typedColumn($definition->type);
        $alias = 'sort_attr_'.$definitionId;

        $sub = AttributeValue::query()
            ->where('definition_id', $definitionId)
            ->select(['entity_id', DB::raw("{$column} as sort_value")]);

        $query->leftJoinSub($sub, $alias, "{$alias}.entity_id", '=', "{$this->table}.id")
            ->orderBy("{$alias}.sort_value", $dir)
            ->select("{$this->table}.*");
    }
}
