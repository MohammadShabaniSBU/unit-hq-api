<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\AccessEvent;
use App\Models\AccessGrant;
use App\Models\AccessPoint;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\ContractNotice;
use App\Models\Deal;
use App\Models\Delinquency;
use App\Models\EsignEnvelope;
use App\Models\Invoice;
use App\Models\MessageThread;
use App\Models\Offer;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClassRate;
use App\Models\UnitHold;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * SQL site-path constraints mirroring {@see SubjectSite}, for list visibility.
 * Uses whereExists / whereIn — never joins that multiply rows.
 */
final class SitePath
{
    public static function hasSitePath(string $modelClass): bool
    {
        return match ($modelClass) {
            Site::class,
            Unit::class,
            UnitClassRate::class,
            AccessPoint::class,
            AccessGrant::class,
            AccessEvent::class,
            UnitHold::class,
            Reservation::class,
            Contract::class,
            Invoice::class,
            Payment::class,
            Delinquency::class,
            ContractNotice::class,
            EsignEnvelope::class,
            Offer::class,
            Deal::class,
            Contact::class,
            MessageThread::class => true,
            default => false,
        };
    }

    /**
     * Constrain $q to rows whose resolved site is in $siteIds.
     *
     * @param  Builder<Model>  $q
     * @param  list<int>  $siteIds
     * @return Builder<Model>
     */
    public static function constrain(Builder $q, string $modelClass, array $siteIds): Builder
    {
        if ($siteIds === []) {
            return $q->whereRaw('1 = 0');
        }

        return match ($modelClass) {
            Site::class => $q->whereIn($q->getModel()->getTable().'.id', $siteIds),
            Unit::class,
            UnitClassRate::class,
            AccessPoint::class => $q->whereIn($q->getModel()->getTable().'.site_id', $siteIds),
            UnitHold::class,
            Reservation::class => self::viaUnitSite($q, $modelClass, $siteIds),
            AccessGrant::class => self::viaAccessPoint($q, $siteIds),
            AccessEvent::class => self::viaAccessEvent($q, $siteIds),
            Contract::class => self::contractAtSites($q, 'contracts.id', $siteIds),
            Invoice::class => self::contractAtSites($q, 'invoices.contract_id', $siteIds),
            Payment::class => self::contractAtSites($q, 'payments.contract_id', $siteIds),
            Delinquency::class => self::contractAtSites($q, 'delinquencies.contract_id', $siteIds),
            ContractNotice::class => self::contractAtSites($q, 'contract_notices.contract_id', $siteIds),
            EsignEnvelope::class => self::contractAtSites($q, 'esign_envelopes.contract_id', $siteIds),
            Offer::class => self::viaDealSite($q, $siteIds),
            Deal::class => self::dealDRbac1($q, $siteIds),
            Contact::class => self::contactDRbac1($q, $siteIds),
            MessageThread::class => self::viaMessageThread($q, $siteIds),
            default => $q->whereRaw('1 = 0'),
        };
    }

    /**
     * @param  Builder<Model>  $q
     * @param  class-string<Model>  $modelClass
     * @param  list<int>  $siteIds
     * @return Builder<Model>
     */
    private static function viaUnitSite(Builder $q, string $modelClass, array $siteIds): Builder
    {
        $table = (new $modelClass)->getTable();

        return $q->whereExists(function (QueryBuilder $sub) use ($table, $siteIds): void {
            $sub->selectRaw('1')
                ->from('units')
                ->whereColumn('units.id', $table.'.unit_id')
                ->whereIn('units.site_id', $siteIds);
        });
    }

    /**
     * @param  Builder<Model>  $q
     * @param  list<int>  $siteIds
     * @return Builder<Model>
     */
    private static function viaAccessPoint(Builder $q, array $siteIds): Builder
    {
        return $q->whereExists(function (QueryBuilder $sub) use ($siteIds): void {
            $sub->selectRaw('1')
                ->from('access_points')
                ->whereColumn('access_points.id', 'access_grants.access_point_id')
                ->whereIn('access_points.site_id', $siteIds);
        });
    }

    /**
     * @param  Builder<Model>  $q
     * @param  list<int>  $siteIds
     * @return Builder<Model>
     */
    private static function viaAccessEvent(Builder $q, array $siteIds): Builder
    {
        return $q->where(function (Builder $outer) use ($siteIds): void {
            $outer->whereExists(function (QueryBuilder $sub) use ($siteIds): void {
                $sub->selectRaw('1')
                    ->from('access_points')
                    ->whereColumn('access_points.id', 'access_events.access_point_id')
                    ->whereIn('access_points.site_id', $siteIds);
            })->orWhereExists(function (QueryBuilder $sub) use ($siteIds): void {
                $sub->selectRaw('1')
                    ->from('access_grants')
                    ->join('access_points', 'access_points.id', '=', 'access_grants.access_point_id')
                    ->whereColumn('access_grants.id', 'access_events.access_grant_id')
                    ->whereIn('access_points.site_id', $siteIds);
            });
        });
    }

    /**
     * Current-or-latest occupancy → unit → site (mirrors SubjectSite::contractSite).
     *
     * @param  Builder<Model>  $q
     * @param  list<int>  $siteIds
     * @return Builder<Model>
     */
    private static function contractAtSites(Builder $q, string $contractIdExpr, array $siteIds): Builder
    {
        return $q->whereExists(function (QueryBuilder $sub) use ($contractIdExpr, $siteIds): void {
            $sub->selectRaw('1')
                ->from('unit_occupancies')
                ->join('units', 'units.id', '=', 'unit_occupancies.unit_id')
                ->whereColumn('unit_occupancies.contract_id', $contractIdExpr)
                ->whereIn('units.site_id', $siteIds)
                ->whereRaw(
                    'unit_occupancies.id = (
                        SELECT uo2.id FROM unit_occupancies uo2
                        WHERE uo2.contract_id = '.$contractIdExpr.'
                        ORDER BY CASE WHEN uo2.ended_on IS NULL THEN 0 ELSE 1 END,
                                 uo2.started_on DESC
                        LIMIT 1
                    )'
                );
        });
    }

    /**
     * Offers on deals at granted sites, or on unassigned deals (null site_id).
     *
     * @param  Builder<Model>  $q
     * @param  list<int>  $siteIds
     * @return Builder<Model>
     */
    private static function viaDealSite(Builder $q, array $siteIds): Builder
    {
        return $q->whereExists(function (QueryBuilder $sub) use ($siteIds): void {
            $sub->selectRaw('1')
                ->from('deals')
                ->whereColumn('deals.id', 'offers.deal_id')
                ->where(function (QueryBuilder $deal) use ($siteIds): void {
                    $deal->whereIn('deals.site_id', $siteIds)
                        ->orWhereNull('deals.site_id');
                });
        });
    }

    /**
     * D-RBAC-1: granted sites or unassigned (null site_id).
     *
     * @param  Builder<Model>  $q
     * @param  list<int>  $siteIds
     * @return Builder<Model>
     */
    private static function dealDRbac1(Builder $q, array $siteIds): Builder
    {
        return $q->where(function (Builder $inner) use ($siteIds): void {
            $inner->whereIn('deals.site_id', $siteIds)
                ->orWhereNull('deals.site_id');
        });
    }

    /**
     * D-RBAC-1: related to a granted site, or no site relation at all (unassigned lead).
     *
     * @param  Builder<Model>  $q
     * @param  list<int>  $siteIds
     * @return Builder<Model>
     */
    private static function contactDRbac1(Builder $q, array $siteIds): Builder
    {
        return $q->where(function (Builder $outer) use ($siteIds): void {
            $outer
                ->whereExists(function (QueryBuilder $sub) use ($siteIds): void {
                    $sub->selectRaw('1')
                        ->from('deals')
                        ->whereColumn('deals.contact_id', 'contacts.id')
                        ->whereIn('deals.site_id', $siteIds);
                })
                ->orWhereExists(function (QueryBuilder $sub) use ($siteIds): void {
                    $sub->selectRaw('1')
                        ->from('reservations')
                        ->join('units', 'units.id', '=', 'reservations.unit_id')
                        ->whereColumn('reservations.contact_id', 'contacts.id')
                        ->whereIn('units.site_id', $siteIds);
                })
                ->orWhereExists(function (QueryBuilder $sub) use ($siteIds): void {
                    $sub->selectRaw('1')
                        ->from('contracts')
                        ->whereColumn('contracts.contact_id', 'contacts.id')
                        ->whereExists(function (QueryBuilder $occ) use ($siteIds): void {
                            $occ->selectRaw('1')
                                ->from('unit_occupancies')
                                ->join('units', 'units.id', '=', 'unit_occupancies.unit_id')
                                ->whereColumn('unit_occupancies.contract_id', 'contracts.id')
                                ->whereIn('units.site_id', $siteIds)
                                ->whereRaw(
                                    'unit_occupancies.id = (
                                        SELECT uo2.id FROM unit_occupancies uo2
                                        WHERE uo2.contract_id = contracts.id
                                        ORDER BY CASE WHEN uo2.ended_on IS NULL THEN 0 ELSE 1 END,
                                                 uo2.started_on DESC
                                        LIMIT 1
                                    )'
                                );
                        });
                })
                ->orWhereExists(function (QueryBuilder $sub) use ($siteIds): void {
                    self::messageThreadRelatedToSites($sub, 'contacts.id', $siteIds);
                })
                ->orWhere(function (Builder $unassigned): void {
                    $unassigned
                        ->whereNotExists(function (QueryBuilder $sub): void {
                            $sub->selectRaw('1')
                                ->from('deals')
                                ->whereColumn('deals.contact_id', 'contacts.id')
                                ->whereNotNull('deals.site_id');
                        })
                        ->whereNotExists(function (QueryBuilder $sub): void {
                            $sub->selectRaw('1')
                                ->from('reservations')
                                ->whereColumn('reservations.contact_id', 'contacts.id');
                        })
                        ->whereNotExists(function (QueryBuilder $sub): void {
                            $sub->selectRaw('1')
                                ->from('contracts')
                                ->join('unit_occupancies', 'unit_occupancies.contract_id', '=', 'contracts.id')
                                ->whereColumn('contracts.contact_id', 'contacts.id');
                        })
                        ->whereNotExists(function (QueryBuilder $sub): void {
                            $sub->selectRaw('1')
                                ->from('message_threads')
                                ->whereColumn('message_threads.contact_id', 'contacts.id');
                        });
                });
        });
    }

    /**
     * Thread site = latest message's SiteSenderIdentity.site_id, else contact's
     * most recent contract occupancy site (mirrors SubjectSite::messageThreadSite).
     *
     * @param  Builder<Model>  $q
     * @param  list<int>  $siteIds
     * @return Builder<Model>
     */
    private static function viaMessageThread(Builder $q, array $siteIds): Builder
    {
        return $q->where(function (Builder $outer) use ($siteIds): void {
            $outer->whereExists(function (QueryBuilder $sub) use ($siteIds): void {
                $sub->selectRaw('1')
                    ->from('site_sender_identities')
                    ->whereIn('site_sender_identities.site_id', $siteIds)
                    ->whereIn('site_sender_identities.account_id', function (QueryBuilder $account): void {
                        $account->select('messages.communication_account_id')
                            ->from('messages')
                            ->whereColumn('messages.message_thread_id', 'message_threads.id')
                            ->whereNotNull('messages.communication_account_id')
                            ->orderByDesc('messages.id')
                            ->limit(1);
                    });
            })->orWhere(function (Builder $fallback) use ($siteIds): void {
                $fallback
                    ->whereNotExists(function (QueryBuilder $sub): void {
                        $sub->selectRaw('1')
                            ->from('site_sender_identities')
                            ->whereNotNull('site_sender_identities.site_id')
                            ->whereIn('site_sender_identities.account_id', function (QueryBuilder $account): void {
                                $account->select('messages.communication_account_id')
                                    ->from('messages')
                                    ->whereColumn('messages.message_thread_id', 'message_threads.id')
                                    ->whereNotNull('messages.communication_account_id')
                                    ->orderByDesc('messages.id')
                                    ->limit(1);
                            });
                    })
                    ->whereExists(function (QueryBuilder $sub) use ($siteIds): void {
                        $sub->selectRaw('1')
                            ->from('contracts')
                            ->whereColumn('contracts.contact_id', 'message_threads.contact_id')
                            ->whereRaw(
                                'contracts.id = (
                                    SELECT c2.id FROM contracts c2
                                    WHERE c2.contact_id = message_threads.contact_id
                                    ORDER BY c2.id DESC
                                    LIMIT 1
                                )'
                            )
                            ->whereExists(function (QueryBuilder $occ) use ($siteIds): void {
                                $occ->selectRaw('1')
                                    ->from('unit_occupancies')
                                    ->join('units', 'units.id', '=', 'unit_occupancies.unit_id')
                                    ->whereColumn('unit_occupancies.contract_id', 'contracts.id')
                                    ->whereIn('units.site_id', $siteIds)
                                    ->whereRaw(
                                        'unit_occupancies.id = (
                                            SELECT uo2.id FROM unit_occupancies uo2
                                            WHERE uo2.contract_id = contracts.id
                                            ORDER BY CASE WHEN uo2.ended_on IS NULL THEN 0 ELSE 1 END,
                                                     uo2.started_on DESC
                                            LIMIT 1
                                        )'
                                    );
                            });
                    });
            });
        });
    }

    /**
     * @param  list<int>  $siteIds
     */
    private static function messageThreadRelatedToSites(QueryBuilder $sub, string $contactIdColumn, array $siteIds): void
    {
        $sub->selectRaw('1')
            ->from('message_threads')
            ->whereColumn('message_threads.contact_id', $contactIdColumn)
            ->where(function (QueryBuilder $outer) use ($siteIds): void {
                $outer->whereExists(function (QueryBuilder $ident) use ($siteIds): void {
                    $ident->selectRaw('1')
                        ->from('site_sender_identities')
                        ->whereIn('site_sender_identities.site_id', $siteIds)
                        ->whereIn('site_sender_identities.account_id', function (QueryBuilder $account): void {
                            $account->select('messages.communication_account_id')
                                ->from('messages')
                                ->whereColumn('messages.message_thread_id', 'message_threads.id')
                                ->whereNotNull('messages.communication_account_id')
                                ->orderByDesc('messages.id')
                                ->limit(1);
                        });
                })->orWhere(function (QueryBuilder $fallback) use ($siteIds): void {
                    $fallback
                        ->whereNotExists(function (QueryBuilder $ident): void {
                            $ident->selectRaw('1')
                                ->from('site_sender_identities')
                                ->whereNotNull('site_sender_identities.site_id')
                                ->whereIn('site_sender_identities.account_id', function (QueryBuilder $account): void {
                                    $account->select('messages.communication_account_id')
                                        ->from('messages')
                                        ->whereColumn('messages.message_thread_id', 'message_threads.id')
                                        ->whereNotNull('messages.communication_account_id')
                                        ->orderByDesc('messages.id')
                                        ->limit(1);
                                });
                        })
                        ->whereExists(function (QueryBuilder $occ) use ($siteIds): void {
                            $occ->selectRaw('1')
                                ->from('contracts')
                                ->whereColumn('contracts.contact_id', 'message_threads.contact_id')
                                ->whereRaw(
                                    'contracts.id = (
                                        SELECT c2.id FROM contracts c2
                                        WHERE c2.contact_id = message_threads.contact_id
                                        ORDER BY c2.id DESC
                                        LIMIT 1
                                    )'
                                )
                                ->whereExists(function (QueryBuilder $inner) use ($siteIds): void {
                                    $inner->selectRaw('1')
                                        ->from('unit_occupancies')
                                        ->join('units', 'units.id', '=', 'unit_occupancies.unit_id')
                                        ->whereColumn('unit_occupancies.contract_id', 'contracts.id')
                                        ->whereIn('units.site_id', $siteIds)
                                        ->whereRaw(
                                            'unit_occupancies.id = (
                                                SELECT uo2.id FROM unit_occupancies uo2
                                                WHERE uo2.contract_id = contracts.id
                                                ORDER BY CASE WHEN uo2.ended_on IS NULL THEN 0 ELSE 1 END,
                                                         uo2.started_on DESC
                                                LIMIT 1
                                            )'
                                        );
                                });
                        });
                });
            });
    }
}
