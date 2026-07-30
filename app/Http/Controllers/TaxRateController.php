<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\LogChannel;
use App\Http\Resources\TaxRateResource;
use App\Models\Employee;
use App\Models\TaxRate;
use App\Support\Billing\JurisdictionCode;
use App\Support\RecordsActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Tax rates are a selectable catalog with history, mirroring Price
 * immutability: "editing" a rate inserts a new version (same code, new
 * effective_from) and closes the previous one via effective_to. Applied
 * rates are snapshotted onto contract_items/charges at signing, so a later
 * version change never rewrites signed contracts.
 */
class TaxRateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['nullable', 'string'],
        ]);

        $query = TaxRate::query()->orderBy('code')->orderByDesc('effective_from');

        if (! empty($validated['code'])) {
            $query->where('code', $validated['code']);
        } else {
            $query->current();
        }

        return $this->success(
            TaxRateResource::collection($query->get())->resolve(),
            'Tax rates retrieved successfully.'
        );
    }

    /** Current versions as {value, label} pairs for select inputs. */
    public function options(): JsonResponse
    {
        $options = TaxRate::query()
            ->current()
            ->orderBy('name')
            ->get()
            ->map(fn (TaxRate $taxRate) => [
                'value' => $taxRate->id,
                'label' => "{$taxRate->name} ({$taxRate->rate}%)",
                'code'  => $taxRate->code,
            ]);

        return $this->success($options, 'Tax rate options retrieved successfully.');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'           => ['required', 'string', 'max:255'],
            'code'           => ['required', 'string', 'max:100'],
            'rate'           => ['required', 'numeric', 'min:0', 'max:100'],
            'jurisdiction'   => ['nullable', 'string', new JurisdictionCode],
            'is_default'     => ['sometimes', 'boolean'],
            'effective_from' => ['nullable', 'date'],
        ]);

        $createdBy = $this->resolveCreatedBy($request);
        $isDefault = (bool) ($validated['is_default'] ?? false);

        $taxRate = DB::transaction(function () use ($validated, $createdBy, $isDefault) {
            if ($isDefault) {
                TaxRate::query()->where('is_default', true)->update(['is_default' => false]);
            }

            $taxRate = TaxRate::query()->create([
                'name'           => $validated['name'],
                'code'           => $validated['code'],
                'rate'           => $validated['rate'],
                'jurisdiction'   => $validated['jurisdiction'] ?? null,
                'is_default'     => $isDefault,
                'effective_from' => $validated['effective_from'] ?? Carbon::today()->toDateString(),
                'effective_to'   => null,
                'created_by'     => $createdBy,
            ]);

            RecordsActivity::log(LogChannel::Facility, 'rate.tax.versioned', $taxRate, [
                'code'   => $taxRate->code,
                'rate'   => (string) $taxRate->rate,
                'old_tax_rate_id' => null,
                'new_tax_rate_id' => $taxRate->id,
            ]);

            return $taxRate;
        });

        return $this->created(
            TaxRateResource::make($taxRate),
            'Tax rate created successfully.'
        );
    }

    /**
     * "Edit" a tax rate: insert a new version (same code) and close the
     * previous one via effective_to, in one transaction. Never UPDATEs rate
     * in place.
     */
    public function update(Request $request, TaxRate $taxRate): JsonResponse
    {
        $validated = $request->validate([
            'name'           => ['sometimes', 'required', 'string', 'max:255'],
            'rate'           => ['sometimes', 'required', 'numeric', 'min:0', 'max:100'],
            'jurisdiction'   => ['sometimes', 'nullable', 'string', new JurisdictionCode],
            'effective_from' => ['nullable', 'date'],
        ]);

        $createdBy = $this->resolveCreatedBy($request);
        $effectiveFrom = $validated['effective_from'] ?? Carbon::today()->toDateString();

        if ($taxRate->effective_to !== null) {
            throw ValidationException::withMessages([
                'id' => ['Only the current version of a tax rate can be edited.'],
            ]);
        }

        $newVersion = DB::transaction(function () use ($taxRate, $validated, $createdBy, $effectiveFrom) {
            $taxRate->update(['effective_to' => Carbon::parse($effectiveFrom)->subDay()->toDateString()]);

            $newVersion = TaxRate::query()->create([
                'name'           => $validated['name'] ?? $taxRate->name,
                'code'           => $taxRate->code,
                'rate'           => $validated['rate'] ?? $taxRate->rate,
                'jurisdiction'   => array_key_exists('jurisdiction', $validated) ? $validated['jurisdiction'] : $taxRate->jurisdiction,
                'is_default'     => $taxRate->is_default,
                'effective_from' => $effectiveFrom,
                'effective_to'   => null,
                'created_by'     => $createdBy,
            ]);

            if ($newVersion->is_default) {
                TaxRate::query()
                    ->where('is_default', true)
                    ->whereKeyNot($newVersion->id)
                    ->update(['is_default' => false]);
            }

            RecordsActivity::log(LogChannel::Facility, 'rate.tax.versioned', $newVersion, [
                'code'            => $newVersion->code,
                'rate'            => (string) $newVersion->rate,
                'old_tax_rate_id' => $taxRate->id,
                'new_tax_rate_id' => $newVersion->id,
            ]);

            return $newVersion;
        });

        return $this->success(
            TaxRateResource::make($newVersion),
            'Tax rate updated successfully.'
        );
    }

    /**
     * Set the org-wide default rate, clearing the previous default in the
     * same transaction.
     */
    public function setDefault(TaxRate $taxRate): JsonResponse
    {
        DB::transaction(function () use ($taxRate) {
            TaxRate::query()
                ->where('is_default', true)
                ->whereKeyNot($taxRate->id)
                ->update(['is_default' => false]);

            $taxRate->update(['is_default' => true]);
        });

        return $this->success(
            TaxRateResource::make($taxRate->fresh()),
            'Default tax rate updated successfully.'
        );
    }

    private function resolveCreatedBy(Request $request): int
    {
        $createdBy = $request->user()?->id ?? Employee::query()->value('id');

        if ($createdBy === null) {
            throw ValidationException::withMessages([
                'rate' => ['No employee record found to attribute this tax rate change.'],
            ]);
        }

        return $createdBy;
    }
}
