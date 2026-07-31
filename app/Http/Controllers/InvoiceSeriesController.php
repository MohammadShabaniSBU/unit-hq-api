<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\InvoiceSeriesKind;
use App\Http\Resources\InvoiceSeriesResource;
use App\Models\InvoiceSeries;
use App\Models\LegalEntity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InvoiceSeriesController extends Controller
{
    public function index(Request $request, LegalEntity $legalEntity): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['active', 'archived', 'all'])],
        ]);

        $query = InvoiceSeries::query()
            ->where('legal_entity_id', $legalEntity->id)
            ->orderBy('kind')
            ->orderBy('code');

        $status = $validated['status'] ?? 'active';

        match ($status) {
            'archived' => $query->archived(),
            'all' => null,
            default => $query->active(),
        };

        return $this->success(
            InvoiceSeriesResource::collection($query->get())->resolve(),
            'Invoice series retrieved successfully.'
        );
    }

    public function store(Request $request, LegalEntity $legalEntity): JsonResponse
    {
        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:20',
                Rule::unique('invoice_series', 'code')
                    ->where('legal_entity_id', $legalEntity->id)
                    ->whereNull('archived_at'),
            ],
            'kind' => ['required', Rule::enum(InvoiceSeriesKind::class)],
            'starting_number' => ['nullable', 'integer', 'min:1'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        $series = DB::transaction(function () use ($validated, $legalEntity) {
            $isDefault = (bool) ($validated['is_default'] ?? false);

            if ($isDefault) {
                $this->clearDefaults($legalEntity->id, $validated['kind']);
            }

            return InvoiceSeries::query()->create([
                'legal_entity_id' => $legalEntity->id,
                'code' => $validated['code'],
                'kind' => $validated['kind'],
                'next_number' => (int) ($validated['starting_number'] ?? 1),
                'is_default' => $isDefault,
                'archived_at' => null,
            ]);
        });

        return $this->created(
            InvoiceSeriesResource::make($series),
            'Invoice series created successfully.'
        );
    }

    public function update(Request $request, InvoiceSeries $invoiceSeries): JsonResponse
    {
        if ($request->exists('next_number') || $request->exists('starting_number')) {
            throw ValidationException::withMessages([
                'next_number' => [__('errors.invoice_series.next_number_immutable')],
            ]);
        }

        if ($request->exists('code') || $request->exists('legal_entity_id') || $request->exists('kind')) {
            if ($invoiceSeries->hasIssuedInvoices()) {
                throw ValidationException::withMessages([
                    'code' => [__('errors.invoice_series.identity_frozen')],
                ]);
            }

            throw ValidationException::withMessages([
                'code' => [__('errors.invoice_series.identity_immutable')],
            ]);
        }

        $validated = $request->validate([
            'is_default' => ['required', 'boolean'],
        ]);

        DB::transaction(function () use ($validated, $invoiceSeries) {
            if ($validated['is_default']) {
                $this->clearDefaults(
                    $invoiceSeries->legal_entity_id,
                    $invoiceSeries->kind->value,
                    $invoiceSeries->id
                );
            }

            $invoiceSeries->update(['is_default' => $validated['is_default']]);
        });

        return $this->success(
            InvoiceSeriesResource::make($invoiceSeries->fresh()),
            'Invoice series updated successfully.'
        );
    }

    public function archive(InvoiceSeries $invoiceSeries): JsonResponse
    {
        if ($invoiceSeries->isArchived()) {
            return $this->success(
                InvoiceSeriesResource::make($invoiceSeries),
                'Invoice series already archived.'
            );
        }

        $this->assertCanArchive($invoiceSeries);

        $invoiceSeries->update(['archived_at' => now()]);

        return $this->success(
            InvoiceSeriesResource::make($invoiceSeries->fresh()),
            'Invoice series archived successfully.'
        );
    }

    public function unarchive(InvoiceSeries $invoiceSeries): JsonResponse
    {
        if (! $invoiceSeries->isArchived()) {
            return $this->success(
                InvoiceSeriesResource::make($invoiceSeries),
                'Invoice series already active.'
            );
        }

        DB::transaction(function () use ($invoiceSeries) {
            $codeTaken = InvoiceSeries::query()
                ->where('legal_entity_id', $invoiceSeries->legal_entity_id)
                ->where('code', $invoiceSeries->code)
                ->whereNull('archived_at')
                ->whereKeyNot($invoiceSeries->id)
                ->exists();

            if ($codeTaken) {
                throw ValidationException::withMessages([
                    'code' => [__('errors.invoice_series.code_in_use')],
                ]);
            }

            if ($invoiceSeries->is_default) {
                $this->clearDefaults(
                    $invoiceSeries->legal_entity_id,
                    $invoiceSeries->kind->value,
                    $invoiceSeries->id
                );
            }

            $invoiceSeries->update(['archived_at' => null]);
        });

        return $this->success(
            InvoiceSeriesResource::make($invoiceSeries->fresh()),
            'Invoice series unarchived successfully.'
        );
    }

    private function assertCanArchive(InvoiceSeries $series): void
    {
        if (! $series->is_default) {
            return;
        }

        $alternative = InvoiceSeries::query()
            ->where('legal_entity_id', $series->legal_entity_id)
            ->where('kind', $series->kind)
            ->whereNull('archived_at')
            ->whereKeyNot($series->id)
            ->exists();

        if (! $alternative) {
            throw ValidationException::withMessages([
                'invoice_series' => [__('errors.invoice_series.cannot_archive_sole_default')],
            ]);
        }
    }

    private function clearDefaults(int $legalEntityId, string $kind, ?int $exceptId = null): void
    {
        $query = InvoiceSeries::query()
            ->where('legal_entity_id', $legalEntityId)
            ->where('kind', $kind)
            ->where('is_default', true)
            ->whereNull('archived_at');

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        $query->update(['is_default' => false]);
    }
}
