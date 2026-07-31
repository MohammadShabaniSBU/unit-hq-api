<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\InvoiceKind;
use App\Http\Resources\InvoiceResource;
use App\Models\Charge;
use App\Models\Contract;
use App\Models\Invoice;
use App\Support\Fiscal\InvoiceIssuer;
use App\Support\Fiscal\InvoiceRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InvoiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'legal_entity_id' => ['nullable', 'integer', 'exists:legal_entities,id'],
            'invoice_series_id' => ['nullable', 'integer', 'exists:invoice_series,id'],
            'contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'contract_id' => ['nullable', 'integer', 'exists:contracts,id'],
            'kind' => ['nullable', Rule::enum(InvoiceKind::class)],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $query = Invoice::query()
            ->with(['contact', 'contract', 'lines'])
            ->latest('issue_date')
            ->latest('id');

        if (isset($validated['legal_entity_id'])) {
            $query->where('legal_entity_id', $validated['legal_entity_id']);
        }
        if (isset($validated['invoice_series_id'])) {
            $query->where('invoice_series_id', $validated['invoice_series_id']);
        }
        if (isset($validated['contact_id'])) {
            $query->where('contact_id', $validated['contact_id']);
        }
        if (isset($validated['contract_id'])) {
            $query->where('contract_id', $validated['contract_id']);
        }
        if (isset($validated['kind'])) {
            $query->where('kind', $validated['kind']);
        }
        if (isset($validated['date_from'])) {
            $query->whereDate('issue_date', '>=', $validated['date_from']);
        }
        if (isset($validated['date_to'])) {
            $query->whereDate('issue_date', '<=', $validated['date_to']);
        }

        return $this->paginated(
            $query->paginate($this->perPage())->through(
                fn (Invoice $invoice) => InvoiceResource::make($invoice)
            ),
            'Invoices retrieved successfully.'
        );
    }

    public function show(Invoice $invoice): JsonResponse
    {
        $invoice->load(['contact', 'contract', 'lines']);

        return $this->success(
            InvoiceResource::make($invoice),
            'Invoice retrieved successfully.'
        );
    }

    public function pdf(Invoice $invoice): Response
    {
        $invoice->load('lines');
        $payload = InvoiceRenderer::payloadFromInvoice($invoice);
        $pdf = InvoiceRenderer::pdf($payload);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$invoice->full_number.'.pdf"',
        ]);
    }

    public function storeForContract(Request $request, Contract $contract): JsonResponse
    {
        $validated = $request->validate([
            'charge_ids' => ['required', 'array', 'min:1'],
            'charge_ids.*' => ['integer', 'exists:charges,id'],
        ]);

        $charges = Charge::query()
            ->whereIn('id', $validated['charge_ids'])
            ->get();

        if ($charges->count() !== count($validated['charge_ids'])) {
            throw ValidationException::withMessages([
                'charge_ids' => [__('errors.invoices.charge_not_invoicable')],
            ]);
        }

        foreach ($charges as $charge) {
            if ((int) $charge->contract_id !== (int) $contract->id) {
                throw ValidationException::withMessages([
                    'charge_ids' => [__('errors.invoices.charge_not_invoicable')],
                ]);
            }
            if ($charge->invoice_id !== null) {
                throw ValidationException::withMessages([
                    'charge_ids' => [__('errors.invoices.charges_already_invoiced')],
                ]);
            }
        }

        $eligible = InvoiceIssuer::filterCharges($contract, $charges);
        if ($eligible->count() !== $charges->count()) {
            throw ValidationException::withMessages([
                'charge_ids' => [__('errors.invoices.charge_not_invoicable')],
            ]);
        }

        $invoice = DB::transaction(function () use ($contract, $charges, $request) {
            $contract->load(['contact', 'unitItem.item.site.country', 'unitItem.item.site.legalEntity']);

            return InvoiceIssuer::issue(
                $contract,
                $charges,
                null,
                $request->user()?->id,
            );
        });

        if ($invoice === null) {
            throw ValidationException::withMessages([
                'charge_ids' => [__('errors.invoices.charge_not_invoicable')],
            ]);
        }

        return $this->created(
            InvoiceResource::make($invoice->load(['contact', 'contract', 'lines'])),
            'Invoice issued successfully.'
        );
    }
}
