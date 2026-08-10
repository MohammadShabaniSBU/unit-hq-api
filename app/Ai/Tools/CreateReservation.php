<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Concerns\WritesReservationHolds;
use App\Models\Deal;
use App\Models\Employee;
use App\Models\Reservation;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClassRate;
use App\Support\Auth\Permission;
use App\Support\RecordsActivity;
use App\Support\Time\SiteClock;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class CreateReservation implements Tool, Approvable
{
    use InteractsWithApprovals;
    use WritesReservationHolds;

    public function __construct(private readonly Employee $employee) {}

    public function description(): Stringable|string
    {
        return 'Reserve a unit for a contact at a site, auto-picking an available unit unless one is specified.';
    }

    public function handle(Request $request): Stringable|string
    {
        if (! $this->employee->allowsPermission(Permission::ReservationManage)) {
            return json_encode([
                'success' => false,
                'error' => 'You do not have permission to create reservations.',
            ]);
        }

        try {
            $reservation = DB::transaction(function () use ($request): Reservation {
                $siteId = (int) $request['site_id'];
                $unitClassId = (int) $request['unit_class_id'];
                $dealId = $request['deal_id'] ?? null;

                if (! empty($dealId)) {
                    $deal = Deal::query()->findOrFail($dealId);

                    if ($deal->site_id === null) {
                        throw ValidationException::withMessages([
                            'deal_id' => ['Selected deal is missing a site and cannot create a reservation.'],
                        ]);
                    }

                    if ($deal->site_id !== $siteId) {
                        throw ValidationException::withMessages([
                            'site_id' => ['Selected site must match the deal site.'],
                        ]);
                    }
                }

                $latestRate = UnitClassRate::query()
                    ->with('price')
                    ->where('site_id', $siteId)
                    ->where('unit_class_id', $unitClassId)
                    ->first();

                if ($latestRate === null || $latestRate->price === null) {
                    throw ValidationException::withMessages([
                        'unit_class_id' => ['No active price configured for this unit class at the selected site.'],
                    ]);
                }

                if (! empty($request['unit_id'])) {
                    $selectedUnit = Unit::query()
                        ->where('site_id', $siteId)
                        ->where('unit_class_id', $unitClassId)
                        ->where('enabled', true)
                        ->whereKey((int) $request['unit_id'])
                        ->lockForUpdate()
                        ->first();
                } else {
                    $site = Site::query()->findOrFail($siteId);
                    $selectedUnit = Unit::query()
                        ->where('site_id', $siteId)
                        ->where('unit_class_id', $unitClassId)
                        ->where('enabled', true)
                        ->availableOn(SiteClock::today($site))
                        ->lockForUpdate()
                        ->inRandomOrder()
                        ->first();
                }

                if (! $selectedUnit) {
                    throw ValidationException::withMessages([
                        'unit_id' => ['No available unit found for the selected site and unit class.'],
                    ]);
                }

                $selectedUnit->load('site');

                $attributes = [
                    'unit_id' => $selectedUnit->id,
                    'price_id' => $latestRate->price->id,
                    'contact_id' => $request['contact_id'],
                    'deal_id' => $dealId,
                    'offer_option_id' => $request['offer_option_id'] ?? null,
                    'expires_at' => $request['expires_at'],
                ];

                // Omit rather than pass null — the column has a DB-level default
                // ('pending') that only applies when the key is absent from the insert.
                if ($request->has('status') && $request['status']) {
                    $attributes['status'] = $request['status'];
                }

                $reservation = Reservation::query()->create($attributes);

                $this->writeReservationHold($reservation, $selectedUnit, $this->employee->id);

                RecordsActivity::core('reservation.created', $reservation, [
                    'unit_id' => $reservation->unit_id,
                    'hold_expires_at' => $reservation->expires_at?->toIso8601String(),
                ], $this->employee);

                return $reservation;
            });
        } catch (ValidationException $exception) {
            return json_encode([
                'success' => false,
                'error' => implode(' ', $exception->validator->errors()->all()),
            ]);
        }

        return json_encode([
            'success' => true,
            'message' => 'Reservation created successfully.',
            'reservation_id' => $reservation->id,
            'unit_id' => $reservation->unit_id,
            'status' => $reservation->status,
            'expires_at' => $reservation->expires_at?->format('Y-m-d'),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'site_id' => $schema->integer()
                ->description('ID of the site to reserve a unit at')
                ->required(),
            'unit_class_id' => $schema->integer()
                ->description('ID of the unit class to reserve')
                ->required(),
            'contact_id' => $schema->integer()
                ->description('ID of the contact this reservation is for')
                ->required(),
            'expires_at' => $schema->string()
                ->description('Reservation hold expiry date (YYYY-MM-DD format)')
                ->required(),
            'unit_id' => $schema->integer()
                ->description('Specific unit ID to reserve; omit to auto-pick an available unit')
                ->nullable(),
            'deal_id' => $schema->integer()
                ->description('ID of the deal this reservation belongs to, if any')
                ->nullable(),
            'offer_option_id' => $schema->integer()
                ->description('ID of the offer option this reservation resulted from, if any')
                ->nullable(),
            'status' => $schema->string()
                ->description('Reservation status')
                ->enum(array_map(fn (ReservationStatus $status) => $status->value, ReservationStatus::cases()))
                ->nullable(),
        ];
    }
}
