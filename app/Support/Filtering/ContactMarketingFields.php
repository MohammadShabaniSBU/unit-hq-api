<?php

declare(strict_types=1);

namespace App\Support\Filtering;

use App\Enums\ContractStatus;
use App\Enums\DealStatus;
use App\Models\Site;
use App\Models\SubscriptionList;
use App\Models\UnitClass;
use App\Support\Communications\Channel;
use App\Support\Marketing\ConsentState;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Contact filter fields that read related rows. Each predicate is a whereExists
 * (or a scalar business-site expression), so a contact with many deals or
 * contracts still counts once.
 */
final class ContactMarketingFields
{
    /** @return list<FilterableField> */
    public static function all(): array
    {
        return [
            self::siteId(),
            self::hasActiveContract(),
            self::contractUnitClass(),
            self::contractDate('contract_started_at', 'Contract started', 'start_date'),
            self::contractDate('contract_ended_at', 'Contract ended', 'end_date'),
            self::isDelinquent(),
            self::dealStatus(),
            self::dealLostAt(),
            self::dealDesiredUnitClass(),
            self::hasChannel(),
            self::consent(),
        ];
    }

    private static function siteId(): FilterableField
    {
        return new FilterableField(
            key: 'site_id',
            label: 'Site',
            type: 'select',
            operators: FilterOperators::forType('select'),
            column: null,
            apply: function (Builder $query, string $op, mixed $value, string $boolean): void {
                self::applyBusinessSite($query, $op, $value, $boolean);
            },
            optionsLoader: fn (): array => Site::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Site $site): array => ['value' => $site->id, 'label' => $site->name])
                ->all(),
        );
    }

    private static function hasActiveContract(): FilterableField
    {
        return self::booleanField(
            'has_active_contract',
            'Active contract',
            function (Builder $query, string $op, mixed $value, string $boolean): void {
                $exists = $op !== 'is_empty' && filter_var($value, FILTER_VALIDATE_BOOLEAN);
                self::existsOn($query, $boolean, $exists, function (QueryBuilder $sub): void {
                    $sub->from('contracts')
                        ->whereColumn('contracts.contact_id', 'contacts.id')
                        ->whereIn('contracts.status', [
                            ContractStatus::Active->value,
                            ContractStatus::NoticeGiven->value,
                        ]);
                });
            },
        );
    }

    private static function contractUnitClass(): FilterableField
    {
        return new FilterableField(
            key: 'contract_unit_class_id',
            label: 'Active contract unit class',
            type: 'select',
            operators: FilterOperators::forType('select'),
            column: null,
            apply: function (Builder $query, string $op, mixed $value, string $boolean): void {
                self::applyActiveUnitClass($query, $op, $value, $boolean);
            },
            optionsLoader: fn (): array => UnitClass::query()
                ->orderBy('label')
                ->get(['id', 'label'])
                ->map(fn (UnitClass $class): array => ['value' => $class->id, 'label' => $class->label])
                ->all(),
        );
    }

    private static function contractDate(string $key, string $label, string $column): FilterableField
    {
        return new FilterableField(
            key: $key,
            label: $label,
            type: 'date',
            operators: FilterOperators::forType('date'),
            column: null,
            apply: function (Builder $query, string $op, mixed $value, string $boolean) use ($column): void {
                self::applyContractDate($query, $column, $op, $value, $boolean);
            },
        );
    }

    private static function isDelinquent(): FilterableField
    {
        return self::booleanField(
            'is_delinquent',
            'Delinquent',
            function (Builder $query, string $op, mixed $value, string $boolean): void {
                $exists = $op !== 'is_empty' && filter_var($value, FILTER_VALIDATE_BOOLEAN);
                self::existsOn($query, $boolean, $exists, function (QueryBuilder $sub): void {
                    $sub->from('delinquencies')
                        ->join('contracts', 'contracts.id', '=', 'delinquencies.contract_id')
                        ->whereColumn('contracts.contact_id', 'contacts.id')
                        ->whereNull('delinquencies.cured_on');
                });
            },
        );
    }

    private static function dealStatus(): FilterableField
    {
        return new FilterableField(
            key: 'deal_status',
            label: 'Deal status',
            type: 'select',
            operators: FilterOperators::forType('select'),
            options: [
                ['value' => 'open', 'label' => 'Open'],
                ['value' => 'won', 'label' => 'Won'],
                ['value' => 'lost', 'label' => 'Lost'],
            ],
            column: null,
            apply: function (Builder $query, string $op, mixed $value, string $boolean): void {
                self::applyDealStatus($query, $op, $value, $boolean);
            },
        );
    }

    private static function dealLostAt(): FilterableField
    {
        return new FilterableField(
            key: 'deal_lost_at',
            label: 'Deal lost',
            type: 'date',
            operators: FilterOperators::forType('date'),
            column: null,
            apply: function (Builder $query, string $op, mixed $value, string $boolean): void {
                self::applyDealLostAt($query, $op, $value, $boolean);
            },
        );
    }

    private static function dealDesiredUnitClass(): FilterableField
    {
        return new FilterableField(
            key: 'deal_desired_unit_class_id',
            label: 'Desired unit class',
            type: 'select',
            operators: FilterOperators::forType('select'),
            column: null,
            apply: function (Builder $query, string $op, mixed $value, string $boolean): void {
                self::applyDesiredUnitClass($query, $op, $value, $boolean);
            },
            optionsLoader: fn (): array => UnitClass::query()
                ->orderBy('label')
                ->get(['id', 'label'])
                ->map(fn (UnitClass $class): array => ['value' => $class->id, 'label' => $class->label])
                ->all(),
        );
    }

    private static function hasChannel(): FilterableField
    {
        return new FilterableField(
            key: 'has_channel',
            label: 'Has channel',
            type: 'select',
            operators: FilterOperators::forType('select'),
            options: [
                ['value' => Channel::Email->value, 'label' => 'Email'],
                ['value' => Channel::Sms->value, 'label' => 'SMS'],
                ['value' => Channel::Whatsapp->value, 'label' => 'WhatsApp'],
            ],
            column: null,
            apply: function (Builder $query, string $op, mixed $value, string $boolean): void {
                self::applyHasChannel($query, $op, $value, $boolean);
            },
        );
    }

    private static function consent(): FilterableField
    {
        return new FilterableField(
            key: 'consent',
            label: 'Consent granted',
            type: 'consent',
            operators: ['eq'],
            column: null,
            apply: function (Builder $query, string $op, mixed $value, string $boolean): void {
                if ($op !== 'eq' || ! is_array($value)) {
                    throw new InvalidArgumentException('Consent filter requires eq and a list/channel value.');
                }

                $granted = ConsentState::grantedQuery(
                    Channel::from((string) $value['channel']),
                    (int) $value['list_id'],
                );
                $method = $boolean === 'or' ? 'orWhereIn' : 'whereIn';
                $query->{$method}('contacts.id', $granted);
            },
            optionsLoader: fn (): array => SubscriptionList::query()
                ->whereNull('archived_at')
                ->orderBy('id')
                ->get()
                ->map(function (SubscriptionList $list): array {
                    $labels = $list->labels;

                    return [
                        'value' => $list->id,
                        'label' => $labels['en'] ?? $list->key,
                    ];
                })
                ->all(),
            channels: [
                ['value' => Channel::Email->value, 'label' => 'Email'],
                ['value' => Channel::Sms->value, 'label' => 'SMS'],
                ['value' => Channel::Whatsapp->value, 'label' => 'WhatsApp'],
            ],
        );
    }

    /**
     * @param  Closure(Builder, string, mixed, string): void  $apply
     */
    private static function booleanField(string $key, string $label, Closure $apply): FilterableField
    {
        return new FilterableField(
            key: $key,
            label: $label,
            type: 'boolean',
            operators: FilterOperators::forType('boolean'),
            options: [
                ['value' => true, 'label' => 'Yes'],
                ['value' => false, 'label' => 'No'],
            ],
            column: null,
            apply: $apply,
        );
    }

    private static function applyBusinessSite(Builder $query, string $op, mixed $value, string $boolean): void
    {
        $expr = '('.self::businessSiteExpression().')';
        $method = $boolean === 'or' ? 'orWhere' : 'where';

        if ($op === 'is_empty') {
            $query->{$method}(fn (Builder $inner): Builder => $inner->whereRaw("{$expr} IS NULL"));

            return;
        }

        $ids = array_map(intval(...), $op === 'in' ? (array) $value : [$value]);

        if ($op === 'in') {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $query->{$method}(fn (Builder $inner): Builder => $inner->whereRaw("{$expr} IN ({$placeholders})", $ids));

            return;
        }

        if ($op === 'neq') {
            $query->{$method}(function (Builder $inner) use ($expr, $ids): void {
                $inner->whereRaw("{$expr} IS NULL")->orWhereRaw("{$expr} != ?", [$ids[0]]);
            });

            return;
        }

        $query->{$method}(fn (Builder $inner): Builder => $inner->whereRaw("{$expr} = ?", [$ids[0]]));
    }

    /**
     * Same priority as SubjectSite::contactBusinessSite: latest deal with a site,
     * else latest reservation, else latest contract occupancy, else open hold,
     * else the lowest contact_sites id.
     */
    public static function businessSiteExpression(): string
    {
        return <<<'SQL'
COALESCE(
    (SELECT deals.site_id FROM deals WHERE deals.contact_id = contacts.id AND deals.site_id IS NOT NULL ORDER BY deals.id DESC LIMIT 1),
    (SELECT units.site_id FROM reservations INNER JOIN units ON units.id = reservations.unit_id WHERE reservations.contact_id = contacts.id ORDER BY reservations.id DESC LIMIT 1),
    (SELECT units.site_id FROM contracts
        INNER JOIN unit_occupancies ON unit_occupancies.contract_id = contracts.id
        INNER JOIN units ON units.id = unit_occupancies.unit_id
        WHERE contracts.contact_id = contacts.id
          AND contracts.id = (SELECT c2.id FROM contracts AS c2 WHERE c2.contact_id = contacts.id ORDER BY c2.id DESC LIMIT 1)
          AND unit_occupancies.id = (
              SELECT uo2.id FROM unit_occupancies AS uo2
              WHERE uo2.contract_id = contracts.id
              ORDER BY CASE WHEN uo2.ended_on IS NULL THEN 0 ELSE 1 END, uo2.started_on DESC, uo2.id DESC
              LIMIT 1
          )
        LIMIT 1),
    (SELECT units.site_id FROM unit_holds
        INNER JOIN contracts AS hold_contracts ON hold_contracts.id = unit_holds.contract_id
        INNER JOIN units ON units.id = unit_holds.unit_id
        WHERE hold_contracts.contact_id = contacts.id AND unit_holds.released_at IS NULL
        ORDER BY unit_holds.id DESC
        LIMIT 1),
    (SELECT contact_sites.site_id FROM contact_sites WHERE contact_sites.contact_id = contacts.id ORDER BY contact_sites.site_id ASC LIMIT 1)
)
SQL;
    }

    private static function applyActiveUnitClass(Builder $query, string $op, mixed $value, string $boolean): void
    {
        if ($op === 'is_empty') {
            self::existsOn($query, $boolean, false, function (QueryBuilder $sub): void {
                self::activeOccupancy($sub);
            });

            return;
        }

        $ids = array_map(intval(...), $op === 'in' ? (array) $value : [$value]);
        $exists = $op !== 'neq';

        self::existsOn($query, $boolean, $exists, function (QueryBuilder $sub) use ($ids, $op): void {
            self::activeOccupancy($sub);
            if ($op === 'in') {
                $sub->whereIn('units.unit_class_id', $ids);
            } else {
                $sub->where('units.unit_class_id', $ids[0]);
            }
        });
    }

    private static function activeOccupancy(QueryBuilder $sub): void
    {
        $sub->from('contracts')
            ->join('unit_occupancies', 'unit_occupancies.contract_id', '=', 'contracts.id')
            ->join('units', 'units.id', '=', 'unit_occupancies.unit_id')
            ->whereColumn('contracts.contact_id', 'contacts.id')
            ->whereIn('contracts.status', [
                ContractStatus::Active->value,
                ContractStatus::NoticeGiven->value,
            ])
            ->whereNull('unit_occupancies.ended_on');
    }

    private static function applyContractDate(
        Builder $query,
        string $column,
        string $op,
        mixed $value,
        string $boolean,
    ): void {
        if ($op === 'is_empty') {
            self::existsOn($query, $boolean, false, function (QueryBuilder $sub) use ($column): void {
                $sub->from('contracts')
                    ->whereColumn('contracts.contact_id', 'contacts.id')
                    ->whereNotNull('contracts.'.$column);
            });

            return;
        }

        self::existsOn($query, $boolean, true, function (QueryBuilder $sub) use ($column, $op, $value): void {
            $sub->from('contracts')->whereColumn('contracts.contact_id', 'contacts.id');
            $qualified = 'contracts.'.$column;

            match ($op) {
                'eq' => $sub->where($qualified, '=', $value),
                'before' => $sub->where($qualified, '<', $value),
                'after' => $sub->where($qualified, '>', $value),
                'between' => $sub->whereBetween($qualified, $value),
                default => throw new InvalidArgumentException("Unsupported contract date operator [{$op}]."),
            };
        });
    }

    private static function applyDealStatus(Builder $query, string $op, mixed $value, string $boolean): void
    {
        if ($op === 'is_empty') {
            self::existsOn($query, $boolean, false, function (QueryBuilder $sub): void {
                $sub->from('deals')->whereColumn('deals.contact_id', 'contacts.id');
            });

            return;
        }

        $statuses = self::expandDealBuckets($op === 'in' ? $value : [$value]);
        $exists = $op !== 'neq';

        self::existsOn($query, $boolean, $exists, function (QueryBuilder $sub) use ($statuses): void {
            $sub->from('deals')
                ->whereColumn('deals.contact_id', 'contacts.id')
                ->whereIn('deals.status', $statuses);
        });
    }

    /**
     * Lost moment is the deal.lost activity when the panel recorded one.
     * A row created already lost falls back to deals.updated_at.
     */
    private static function applyDealLostAt(Builder $query, string $op, mixed $value, string $boolean): void
    {
        $lostOn = self::lostOnExpression();

        if ($op === 'is_empty') {
            self::existsOn($query, $boolean, false, function (QueryBuilder $sub): void {
                $sub->from('deals')
                    ->whereColumn('deals.contact_id', 'contacts.id')
                    ->where('deals.status', DealStatus::ClosedLost->value);
            });

            return;
        }

        self::existsOn($query, $boolean, true, function (QueryBuilder $sub) use ($lostOn, $op, $value): void {
            $sub->from('deals')
                ->whereColumn('deals.contact_id', 'contacts.id')
                ->where('deals.status', DealStatus::ClosedLost->value);

            match ($op) {
                'eq' => $sub->whereRaw("{$lostOn} = ?", [$value]),
                'before' => $sub->whereRaw("{$lostOn} < ?", [$value]),
                'after' => $sub->whereRaw("{$lostOn} > ?", [$value]),
                'between' => $sub->whereRaw("{$lostOn} BETWEEN ? AND ?", is_array($value) ? $value : [$value, $value]),
                default => throw new InvalidArgumentException("Unsupported deal lost operator [{$op}]."),
            };
        });
    }

    private static function lostOnExpression(): string
    {
        $activity = self::dateCast('lost_activity.created_at');
        $updated = self::dateCast('deals.updated_at');

        return <<<SQL
COALESCE(
    (SELECT {$activity} FROM activity_log AS lost_activity
        WHERE lost_activity.subject_type = 'deal'
          AND lost_activity.subject_id = deals.id
          AND lost_activity.description = 'deal.lost'
        ORDER BY lost_activity.id DESC
        LIMIT 1),
    {$updated}
)
SQL;
    }

    private static function dateCast(string $expression): string
    {
        return DB::getDriverName() === 'pgsql'
            ? "({$expression})::date"
            : "date({$expression})";
    }

    private static function applyDesiredUnitClass(Builder $query, string $op, mixed $value, string $boolean): void
    {
        if ($op === 'is_empty') {
            self::existsOn($query, $boolean, false, function (QueryBuilder $sub): void {
                $sub->from('deals')
                    ->whereColumn('deals.contact_id', 'contacts.id')
                    ->whereNotNull('deals.desired_unit_class_id');
            });

            return;
        }

        $ids = array_map(intval(...), $op === 'in' ? (array) $value : [$value]);
        $exists = $op !== 'neq';

        self::existsOn($query, $boolean, $exists, function (QueryBuilder $sub) use ($ids, $op): void {
            $sub->from('deals')
                ->whereColumn('deals.contact_id', 'contacts.id')
                ->whereNotNull('deals.desired_unit_class_id');
            if ($op === 'in') {
                $sub->whereIn('deals.desired_unit_class_id', $ids);
            } else {
                $sub->where('deals.desired_unit_class_id', $ids[0]);
            }
        });
    }

    private static function applyHasChannel(Builder $query, string $op, mixed $value, string $boolean): void
    {
        if ($op === 'is_empty') {
            $method = $boolean === 'or' ? 'orWhere' : 'where';
            $query->{$method}(function (Builder $inner): void {
                self::whereChannel($inner, Channel::Email->value, 'and', false);
                self::whereChannel($inner, Channel::Sms->value, 'and', false);
                self::whereChannel($inner, Channel::Whatsapp->value, 'and', false);
            });

            return;
        }

        if ($op === 'in') {
            $method = $boolean === 'or' ? 'orWhere' : 'where';
            $channels = array_map(strval(...), (array) $value);
            $query->{$method}(function (Builder $inner) use ($channels): void {
                $first = true;
                foreach ($channels as $channel) {
                    self::whereChannel($inner, $channel, $first ? 'and' : 'or', true);
                    $first = false;
                }
            });

            return;
        }

        self::whereChannel($query, (string) $value, $boolean, $op !== 'neq');
    }

    private static function whereChannel(Builder $query, string $channel, string $boolean, bool $exists): void
    {
        $method = match (true) {
            $exists && $boolean === 'or' => 'orWhere',
            $exists => 'where',
            $boolean === 'or' => 'orWhereNot',
            default => 'whereNot',
        };

        $query->{$method}(function (Builder $inner) use ($channel): void {
            if ($channel === Channel::Email->value) {
                $inner->where(function (Builder $email): void {
                    $email->where(function (Builder $primary): void {
                        $primary->whereNotNull('contacts.email')
                            ->where('contacts.email', '!=', '')
                            ->whereNotExists(function (QueryBuilder $sub): void {
                                self::activeSuppression($sub, Channel::Email->value, 'contacts.email');
                            });
                    })->orWhereExists(function (QueryBuilder $sub): void {
                        self::usableChannelRow($sub, Channel::Email->value);
                    });
                });

                return;
            }

            $inner->whereExists(function (QueryBuilder $sub) use ($channel): void {
                self::usableChannelRow($sub, $channel);
            });
        });
    }

    private static function usableChannelRow(QueryBuilder $sub, string $channel): void
    {
        $sub->selectRaw('1')
            ->from('contact_channels')
            ->whereColumn('contact_channels.contact_id', 'contacts.id')
            ->where('contact_channels.type', $channel)
            ->where('contact_channels.value', '!=', '')
            ->whereNotExists(function (QueryBuilder $suppressed) use ($channel): void {
                self::activeSuppression($suppressed, $channel, 'contact_channels.value');
            });
    }

    private static function activeSuppression(QueryBuilder $sub, string $channel, string $addressColumn): void
    {
        $sub->selectRaw('1')
            ->from('channel_suppressions')
            ->where('channel_suppressions.channel', $channel)
            ->whereNull('channel_suppressions.lifted_at')
            ->whereRaw('channel_suppressions.address = lower(trim('.$addressColumn.'))');
    }

    /**
     * @param  list<mixed>  $buckets
     * @return list<string>
     */
    private static function expandDealBuckets(array $buckets): array
    {
        $statuses = [];

        foreach ($buckets as $bucket) {
            foreach (self::dealBucket((string) $bucket) as $status) {
                $statuses[$status] = $status;
            }
        }

        return array_values($statuses);
    }

    /** @return list<string> */
    private static function dealBucket(string $bucket): array
    {
        return match ($bucket) {
            'won' => [DealStatus::ClosedWon->value],
            'lost' => [DealStatus::ClosedLost->value],
            default => array_values(array_filter(
                array_map(fn (DealStatus $status): string => $status->value, DealStatus::cases()),
                fn (string $status): bool => ! in_array($status, [
                    DealStatus::ClosedWon->value,
                    DealStatus::ClosedLost->value,
                ], true),
            )),
        };
    }

    /**
     * @param  Closure(QueryBuilder): void  $constrain
     */
    private static function existsOn(Builder $query, string $boolean, bool $exists, Closure $constrain): void
    {
        $method = match (true) {
            $exists && $boolean === 'or' => 'orWhereExists',
            $exists => 'whereExists',
            $boolean === 'or' => 'orWhereNotExists',
            default => 'whereNotExists',
        };

        $query->{$method}(function (QueryBuilder $sub) use ($constrain): void {
            $sub->selectRaw('1');
            $constrain($sub);
        });
    }
}
