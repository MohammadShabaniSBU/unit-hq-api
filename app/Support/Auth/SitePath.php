<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\AccessEvent;
use App\Models\AccessGrant;
use App\Models\AccessPoint;
use App\Models\AgentPendingAction;
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
use App\Models\Task;
use App\Models\Unit;
use App\Models\UnitClassRate;
use App\Models\UnitHold;
use App\Models\VoiceSession;
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
            MessageThread::class,
            Task::class,
            AgentPendingAction::class,
            VoiceSession::class => true,
            default => false,
        };
    }

    /**
     * Constrain $q to rows whose resolved site is in $siteIds.
     *
     * @param  Builder<Model>  $q
     * @param  list<int>  $siteIds
     * @param  bool  $selectedSiteOnly  Portal selector: Contact drops the unassigned-lead branch.
     * @return Builder<Model>
     */
    public static function constrain(Builder $q, string $modelClass, array $siteIds, bool $selectedSiteOnly = false): Builder
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
            Contact::class => $selectedSiteOnly
                ? self::contactRelatedToSites($q, $siteIds)
                : self::contactDRbac1($q, $siteIds),
            MessageThread::class => self::viaMessageThread($q, $siteIds),
            Task::class => self::viaTaskable($q, $siteIds),
            AgentPendingAction::class,
            VoiceSession::class => $q->whereIn($q->getModel()->getTable().'.site_id', $siteIds),
            default => $q->whereRaw('1 = 0'),
        };
    }

    /**
     * Tasks whose morph parent resolves to a granted site (or unassigned deal/contact).
     * Morph aliases match Relation::morphMap keys stored on tasks.taskable_type.
     *
     * @param  Builder<Model>  $q
     * @param  list<int>  $siteIds
     * @return Builder<Model>
     */
    private static function viaTaskable(Builder $q, array $siteIds): Builder
    {
        return $q->where(function (Builder $outer) use ($siteIds): void {
            $outer
                ->where(function (Builder $inner) use ($siteIds): void {
                    $inner->where('tasks.taskable_type', 'deal')
                        ->whereIn(
                            'tasks.taskable_id',
                            self::dealDRbac1(Deal::query(), $siteIds)->select('deals.id')
                        );
                })
                ->orWhere(function (Builder $inner) use ($siteIds): void {
                    $inner->where('tasks.taskable_type', 'contact')
                        ->whereIn(
                            'tasks.taskable_id',
                            self::contactDRbac1(Contact::query(), $siteIds)->select('contacts.id')
                        );
                })
                ->orWhere(function (Builder $inner) use ($siteIds): void {
                    $inner->where('tasks.taskable_type', 'unit')
                        ->whereIn(
                            'tasks.taskable_id',
                            Unit::query()->whereIn('units.site_id', $siteIds)->select('units.id')
                        );
                })
                ->orWhere(function (Builder $inner) use ($siteIds): void {
                    $inner->where('tasks.taskable_type', 'contract')
                        ->whereIn(
                            'tasks.taskable_id',
                            self::contractAtSites(Contract::query(), 'contracts.id', $siteIds)->select('contracts.id')
                        );
                })
                ->orWhere(function (Builder $inner) use ($siteIds): void {
                    $inner->where('tasks.taskable_type', 'reservation')
                        ->whereIn(
                            'tasks.taskable_id',
                            self::viaUnitSite(Reservation::query(), Reservation::class, $siteIds)->select('reservations.id')
                        );
                })
                ->orWhere(function (Builder $inner) use ($siteIds): void {
                    $inner->where('tasks.taskable_type', 'offer')
                        ->whereIn(
                            'tasks.taskable_id',
                            self::viaDealSite(Offer::query(), $siteIds)->select('offers.id')
                        );
                });
        });
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
     * Used by grant visibility. The portal site selector uses
     * {@see contactRelatedToSites} so a selected site is not mixed with every lead.
     *
     * @param  Builder<Model>  $q
     * @param  list<int>  $siteIds
     * @return Builder<Model>
     */
    private static function contactDRbac1(Builder $q, array $siteIds): Builder
    {
        return $q->where(function (Builder $outer) use ($siteIds): void {
            self::applyContactRelatedToSites($outer, $siteIds);
            $outer->orWhere(function (Builder $unassigned): void {
                $unassigned
                    ->whereNotExists(function (QueryBuilder $sub): void {
                        $sub->selectRaw('1')
                            ->from('contact_sites')
                            ->whereColumn('contact_sites.contact_id', 'contacts.id');
                    })
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
     * Contacts with a relation to the given sites — no unassigned-lead branch.
     *
     * @param  Builder<Model>  $q
     * @param  list<int>  $siteIds
     * @return Builder<Model>
     */
    private static function contactRelatedToSites(Builder $q, array $siteIds): Builder
    {
        return $q->where(function (Builder $outer) use ($siteIds): void {
            self::applyContactRelatedToSites($outer, $siteIds);
        });
    }

    /**
     * Site-related branches start from the small site-filtered tables (whereIn)
     * so the planner does not scan every contact for correlated EXISTS.
     *
     * @param  list<int>  $siteIds
     */
    private static function applyContactRelatedToSites(Builder $outer, array $siteIds): void
    {
        $outer
            ->whereIn('contacts.id', function (QueryBuilder $sub) use ($siteIds): void {
                $sub->select('contact_sites.contact_id')
                    ->from('contact_sites')
                    ->whereIn('contact_sites.site_id', $siteIds);
            })
            ->orWhereIn('contacts.id', function (QueryBuilder $sub) use ($siteIds): void {
                $sub->select('deals.contact_id')
                    ->from('deals')
                    ->whereIn('deals.site_id', $siteIds);
            })
            ->orWhereIn('contacts.id', function (QueryBuilder $sub) use ($siteIds): void {
                $sub->select('reservations.contact_id')
                    ->from('reservations')
                    ->join('units', 'units.id', '=', 'reservations.unit_id')
                    ->whereIn('units.site_id', $siteIds);
            })
            ->orWhereIn('contacts.id', function (QueryBuilder $sub) use ($siteIds): void {
                $sub->select('contracts.contact_id')
                    ->from('contracts')
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
            ->orWhereIn('contacts.id', function (QueryBuilder $sub) use ($siteIds): void {
                self::messageThreadRelatedToSites($sub, $siteIds);
            });
    }

    /**
     * Thread site = unique SiteSenderIdentity for the latest message account,
     * else the contact's business site (mirrors SubjectSite::messageThreadSite).
     * A company-scoped account shared across sites is not a unique identity.
     *
     * @param  Builder<Model>  $q
     * @param  list<int>  $siteIds
     * @return Builder<Model>
     */
    private static function viaMessageThread(Builder $q, array $siteIds): Builder
    {
        return $q->where(function (Builder $outer) use ($siteIds): void {
            $outer->whereExists(function (QueryBuilder $sub) use ($siteIds): void {
                self::uniqueIdentityForLatestAccount($sub, 'message_threads.id', $siteIds);
            })->orWhere(function (Builder $fallback) use ($siteIds): void {
                $fallback
                    ->whereNotExists(function (QueryBuilder $sub): void {
                        self::uniqueIdentityForLatestAccount($sub, 'message_threads.id');
                    });
                self::applyContactBusinessSiteExists($fallback, 'message_threads.contact_id', $siteIds);
            });
        });
    }

    /**
     * @param  list<int>  $siteIds
     */
    private static function messageThreadRelatedToSites(QueryBuilder $sub, array $siteIds): void
    {
        $sub->select('message_threads.contact_id')
            ->from('message_threads')
            ->where(function (QueryBuilder $outer) use ($siteIds): void {
                $outer->whereExists(function (QueryBuilder $ident) use ($siteIds): void {
                    self::uniqueIdentityForLatestAccount($ident, 'message_threads.id', $siteIds);
                })->orWhere(function (QueryBuilder $fallback) use ($siteIds): void {
                    $fallback
                        ->whereNotExists(function (QueryBuilder $ident): void {
                            self::uniqueIdentityForLatestAccount($ident, 'message_threads.id');
                        });
                    self::applyContactBusinessSiteExists($fallback, 'message_threads.contact_id', $siteIds);
                });
            });
    }

    /**
     * Latest message account has exactly one non-null site identity (optionally
     * required to be a granted site). Shared company accounts fail this check.
     *
     * @param  list<int>|null  $siteIds
     */
    private static function uniqueIdentityForLatestAccount(
        QueryBuilder $ident,
        string $threadIdColumn,
        ?array $siteIds = null,
    ): void {
        $ident->selectRaw('1')
            ->from('site_sender_identities')
            ->whereNotNull('site_sender_identities.site_id')
            ->whereIn('site_sender_identities.account_id', function (QueryBuilder $account) use ($threadIdColumn): void {
                $account->select('messages.communication_account_id')
                    ->from('messages')
                    ->whereColumn('messages.message_thread_id', $threadIdColumn)
                    ->whereNotNull('messages.communication_account_id')
                    ->orderByDesc('messages.id')
                    ->limit(1);
            })
            ->whereRaw(
                '(SELECT COUNT(*) FROM site_sender_identities AS ssi_uniq
                  WHERE ssi_uniq.account_id = site_sender_identities.account_id
                    AND ssi_uniq.site_id IS NOT NULL) = 1'
            );

        if ($siteIds !== null) {
            $ident->whereIn('site_sender_identities.site_id', $siteIds);
        }
    }

    /**
     * Contact is related to a granted site via deal / reservation / occupancy /
     * signature hold / contact_sites — used when a comms account is shared.
     *
     * @param  Builder<Model>|QueryBuilder  $q
     * @param  list<int>  $siteIds
     */
    private static function applyContactBusinessSiteExists(
        Builder|QueryBuilder $q,
        string $contactIdColumn,
        array $siteIds,
    ): void {
        $q->where(function (Builder|QueryBuilder $outer) use ($contactIdColumn, $siteIds): void {
            $outer
                ->whereExists(function (QueryBuilder $sub) use ($contactIdColumn, $siteIds): void {
                    $sub->selectRaw('1')
                        ->from('contact_sites')
                        ->whereColumn('contact_sites.contact_id', $contactIdColumn)
                        ->whereIn('contact_sites.site_id', $siteIds);
                })
                ->orWhereExists(function (QueryBuilder $sub) use ($contactIdColumn, $siteIds): void {
                    $sub->selectRaw('1')
                        ->from('deals')
                        ->whereColumn('deals.contact_id', $contactIdColumn)
                        ->whereIn('deals.site_id', $siteIds);
                })
                ->orWhereExists(function (QueryBuilder $sub) use ($contactIdColumn, $siteIds): void {
                    $sub->selectRaw('1')
                        ->from('reservations')
                        ->join('units', 'units.id', '=', 'reservations.unit_id')
                        ->whereColumn('reservations.contact_id', $contactIdColumn)
                        ->whereIn('units.site_id', $siteIds);
                })
                ->orWhereExists(function (QueryBuilder $sub) use ($contactIdColumn, $siteIds): void {
                    $sub->selectRaw('1')
                        ->from('contracts')
                        ->whereColumn('contracts.contact_id', $contactIdColumn)
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
                ->orWhereExists(function (QueryBuilder $sub) use ($contactIdColumn, $siteIds): void {
                    $sub->selectRaw('1')
                        ->from('contracts')
                        ->whereColumn('contracts.contact_id', $contactIdColumn)
                        ->whereExists(function (QueryBuilder $hold) use ($siteIds): void {
                            $hold->selectRaw('1')
                                ->from('unit_holds')
                                ->join('units', 'units.id', '=', 'unit_holds.unit_id')
                                ->whereColumn('unit_holds.contract_id', 'contracts.id')
                                ->whereNull('unit_holds.released_at')
                                ->whereIn('units.site_id', $siteIds);
                        });
                });
        });
    }
}
