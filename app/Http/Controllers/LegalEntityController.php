<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\FiscalRegime;
use App\Enums\TaxIdType;
use App\Http\Resources\LegalEntityResource;
use App\Models\LegalEntity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LegalEntityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['active', 'archived', 'all'])],
        ]);

        $query = LegalEntity::query()->withCount('sites')->latest();

        $status = $validated['status'] ?? 'active';

        match ($status) {
            'archived' => $query->archived(),
            'all' => null,
            default => $query->active(),
        };

        return $this->paginated(
            $query->paginate($this->perPage())->through(
                fn (LegalEntity $entity) => LegalEntityResource::make($entity)
            ),
            'Legal entities retrieved successfully.'
        );
    }

    public function options(): JsonResponse
    {
        $options = LegalEntity::query()->active()->orderBy('legal_name')->get(['id', 'legal_name'])
            ->map(fn (LegalEntity $entity) => [
                'value' => $entity->id,
                'label' => $entity->legal_name,
            ]);

        return $this->success($options, 'Legal entity options retrieved successfully.');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatedPayload($request, creating: true);

        $entity = LegalEntity::query()->create($validated);

        return $this->created(
            LegalEntityResource::make($entity),
            'Legal entity created successfully.'
        );
    }

    public function show(LegalEntity $legalEntity): JsonResponse
    {
        $legalEntity->loadCount('sites');

        return $this->success(
            LegalEntityResource::make($legalEntity),
            'Legal entity retrieved successfully.'
        );
    }

    public function update(Request $request, LegalEntity $legalEntity): JsonResponse
    {
        $validated = $this->validatedPayload($request, creating: false, entity: $legalEntity);

        if ($legalEntity->hasIssuedInvoices()) {
            if (
                (array_key_exists('tax_id', $validated) && $validated['tax_id'] !== $legalEntity->tax_id)
                || (array_key_exists('country_code', $validated) && $validated['country_code'] !== $legalEntity->country_code)
            ) {
                throw ValidationException::withMessages([
                    'tax_id' => [__('errors.legal_entities.identity_frozen')],
                    'country_code' => [__('errors.legal_entities.identity_frozen')],
                ]);
            }
        }

        $legalEntity->update($validated);

        return $this->success(
            LegalEntityResource::make($legalEntity->fresh()->loadCount('sites')),
            'Legal entity updated successfully.'
        );
    }

    public function archive(LegalEntity $legalEntity): JsonResponse
    {
        if ($legalEntity->isArchived()) {
            return $this->success(
                LegalEntityResource::make($legalEntity->loadCount('sites')),
                'Legal entity already archived.'
            );
        }

        $this->assertCanArchive($legalEntity);

        $legalEntity->update(['archived_at' => now()]);

        return $this->success(
            LegalEntityResource::make($legalEntity->fresh()->loadCount('sites')),
            'Legal entity archived successfully.'
        );
    }

    public function unarchive(LegalEntity $legalEntity): JsonResponse
    {
        if (! $legalEntity->isArchived()) {
            return $this->success(
                LegalEntityResource::make($legalEntity->loadCount('sites')),
                'Legal entity already active.'
            );
        }

        $legalEntity->update(['archived_at' => null]);

        return $this->success(
            LegalEntityResource::make($legalEntity->fresh()->loadCount('sites')),
            'Legal entity unarchived successfully.'
        );
    }

    public function destroy(LegalEntity $legalEntity): JsonResponse
    {
        if (! $legalEntity->isArchived()) {
            $this->assertCanArchive($legalEntity);
            $legalEntity->update(['archived_at' => now()]);
        }

        return $this->noContent('Legal entity archived successfully.');
    }

    /** @return array<string, mixed> */
    private function validatedPayload(Request $request, bool $creating, ?LegalEntity $entity = null): array
    {
        $ignoreId = $entity?->id;

        $fiscalRegime = $request->input('fiscal_regime', $creating ? FiscalRegime::None->value : null);

        if ($fiscalRegime !== null && $fiscalRegime !== FiscalRegime::None->value) {
            $this->rejectFiscalRegime((string) $fiscalRegime);
        }

        $taxIdUnique = Rule::unique('legal_entities', 'tax_id')
            ->whereNull('archived_at')
            ->ignore($ignoreId);

        return $request->validate([
            'legal_name' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:255'],
            'trading_name' => ['nullable', 'string', 'max:255'],
            'tax_id' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:64', $taxIdUnique],
            'tax_id_type' => [$creating ? 'required' : 'sometimes', 'required', Rule::enum(TaxIdType::class)],
            'vat_number' => ['nullable', 'string', 'max:64'],
            'country_code' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'size:2'],
            'address_line1' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:128'],
            'postal_code' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:32'],
            'fiscal_regime' => [$creating ? 'nullable' : 'sometimes', Rule::enum(FiscalRegime::class)],
            'sepa_creditor_id' => ['nullable', 'string', 'max:64'],
        ]);
    }

    private function rejectFiscalRegime(string $regime): never
    {
        $message = match ($regime) {
            FiscalRegime::Verifactu->value,
            FiscalRegime::NoVerificable->value => __('errors.legal_entities.fiscal_regime_s04', ['regime' => $regime]),
            FiscalRegime::Ticketbai->value,
            FiscalRegime::Sii->value => __('errors.legal_entities.fiscal_regime_unimplemented', ['regime' => $regime]),
            default => __('errors.legal_entities.fiscal_regime_invalid', ['regime' => $regime]),
        };

        throw ValidationException::withMessages([
            'fiscal_regime' => [$message],
        ]);
    }

    private function assertCanArchive(LegalEntity $entity): void
    {
        $activeSites = $entity->activeSitesCount();

        if ($activeSites > 0) {
            throw ValidationException::withMessages([
                'legal_entity' => [__('errors.legal_entities.archive_with_active_sites', ['count' => $activeSites])],
            ]);
        }

        if ($entity->hasIssuedInvoices()) {
            throw ValidationException::withMessages([
                'legal_entity' => [__('errors.legal_entities.archive_with_invoices')],
            ]);
        }
    }
}
