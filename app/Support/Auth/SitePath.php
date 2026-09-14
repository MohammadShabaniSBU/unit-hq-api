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
use Illuminate\Support\Facades\DB;

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
     * Site-related branches start from the small site-filtered tables (UNION)
     * so the planner materializes related contact ids instead of OR-ing
     * five whereIn subqueries against every contact.
     *
     * @param  list<int>  $siteIds
     */
    private static function applyContactRelatedToSites(Builder $outer, array $siteIds): void
    {
        $outer->whereIn('contacts.id', function (QueryBuilder $sub) use ($siteIds): void {
            $sub->select('related_contacts.contact_id')
                ->fromSub(self::contactIdsRelatedToSites($siteIds), 'related_contacts');
        });
    }

    /**
     * @param  list<int>  $siteIds
     */
    private static function contactIdsRelatedToSites(array $siteIds): QueryBuilder
    {
        $fromSites = DB::table('contact_sites')
            ->select('contact_sites.contact_id')
            ->whereIn('contact_sites.site_id', $siteIds);

        $fromDeals = DB::table('deals')
            ->select('deals.contact_id')
            ->whereIn('deals.site_id', $siteIds);

        $fromReservations = DB::table('reservations')
            ->select('reservations.contact_id')
            ->join('units', 'units.id', '=', 'reservations.unit_id')
            ->whereIn('units.site_id', $siteIds);

        $fromOccupancies = DB::table('unit_occupancies')
            ->select('contracts.contact_id')
            ->join('units', 'units.id', '=', 'unit_occupancies.unit_id')
            ->join('contracts', 'contracts.id', '=', 'unit_occupancies.contract_id')
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

        $fromThreads = DB::query();
        self::messageThreadRelatedToSites($fromThreads, $siteIds);

        return $fromSites
            ->union($fromDeals)
            ->union($fromReservations)
            ->union($fromOccupancies)
            ->union($fromThreads);
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
                    })
                    ->whereIn('message_threads.contact_id', self::contactBusinessSiteIds($siteIds));
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
                        })
                        ->whereIn('message_threads.contact_id', self::contactBusinessSiteIds($siteIds));
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
            ->fromSub(self::singleSiteIdentityAccounts(), 'unique_site_identity')
            ->whereIn('unique_site_identity.account_id', function (QueryBuilder $account) use ($threadIdColumn): void {
                $account->select('messages.communication_account_id')
                    ->from('messages')
                    ->whereColumn('messages.message_thread_id', $threadIdColumn)
                    ->whereNotNull('messages.communication_account_id')
                    ->orderByDesc('messages.id')
                    ->limit(1);
            });

        if ($siteIds !== null) {
            $ident->whereIn('unique_site_identity.site_id', $siteIds);
        }
    }

    /**
     * Accounts that have exactly one non-null site identity. A company-scoped
     * account shared across sites is excluded.
     */
    private static function singleSiteIdentityAccounts(): QueryBuilder
    {
        return DB::table('site_sender_identities')
            ->selectRaw('account_id, MIN(site_id) AS site_id')
            ->whereNotNull('site_id')
            ->groupBy('account_id')
            ->havingRaw('COUNT(*) = 1');
    }

    /**
     * Contact ids related to a granted site via deal / reservation / occupancy /
     * signature hold / contact_sites — used when a comms account is shared.
     *
     * @param  list<int>  $siteIds
     */
    private static function contactBusinessSiteIds(array $siteIds): QueryBuilder
    {
        $fromSites = DB::table('contact_sites')
            ->select('contact_sites.contact_id')
            ->whereIn('contact_sites.site_id', $siteIds);

        $fromDeals = DB::table('deals')
            ->select('deals.contact_id')
            ->whereIn('deals.site_id', $siteIds);

        $fromReservations = DB::table('reservations')
            ->select('reservations.contact_id')
            ->join('units', 'units.id', '=', 'reservations.unit_id')
            ->whereIn('units.site_id', $siteIds);

        $fromOccupancies = DB::table('unit_occupancies')
            ->select('contracts.contact_id')
            ->join('units', 'units.id', '=', 'unit_occupancies.unit_id')
            ->join('contracts', 'contracts.id', '=', 'unit_occupancies.contract_id')
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

        $fromHolds = DB::table('unit_holds')
            ->select('contracts.contact_id')
            ->join('units', 'units.id', '=', 'unit_holds.unit_id')
            ->join('contracts', 'contracts.id', '=', 'unit_holds.contract_id')
            ->whereNull('unit_holds.released_at')
            ->whereIn('units.site_id', $siteIds);

        return $fromSites
            ->union($fromDeals)
            ->union($fromReservations)
            ->union($fromOccupancies)
            ->union($fromHolds);
    }
}
