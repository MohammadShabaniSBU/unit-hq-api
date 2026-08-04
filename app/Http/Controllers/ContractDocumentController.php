<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ContractDocumentStatus;
use App\Enums\TemplateChannel;
use App\Enums\TemplatePurpose;
use App\Http\Resources\ContractDocumentResource;
use App\Models\Contract;
use App\Models\ContractDocument;
use App\Models\TemplateFamily;
use App\Models\TemplateVariant;
use App\Support\Communications\TemplateResolver;
use App\Support\Documents\ContractDocumentRenderer;
use App\Support\RecordsActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use App\Support\Auth\Permission;
use Illuminate\Support\Facades\Gate;

class ContractDocumentController extends Controller
{
    public function index(Contract $contract): JsonResponse
    {
        Gate::authorize(Permission::ContractView->value, $contract);

        $documents = $contract->documents()
            ->with('templateVariant')
            ->latest('id')
            ->get();

        return $this->success(
            ContractDocumentResource::collection($documents),
            'Contract documents retrieved successfully.'
        );
    }

    public function store(Request $request, Contract $contract): JsonResponse
    {
        Gate::authorize(Permission::ContractSign->value, $contract);

        $validated = $request->validate([
            'template_family_id' => ['sometimes', 'nullable', 'integer', 'exists:template_families,id'],
            'template_variant_id' => ['sometimes', 'nullable', 'integer', 'exists:template_variants,id'],
            'locale' => ['sometimes', 'nullable', 'string', 'max:8'],
        ]);

        $family = $this->resolveFamily($validated['template_family_id'] ?? null);
        $resolved = $this->resolveVariant($contract, $family, $validated);
        $variant = $resolved['variant'];
        $overridden = $resolved['overridden'];

        $document = $this->generateSnapshot($contract, $family, $variant);

        if ($overridden) {
            RecordsActivity::core('contract.document.locale_overridden', $contract, [
                'resolved_locale' => $resolved['resolved_locale'],
                'chosen_locale' => $variant->locale,
                'template_variant_id' => $variant->id,
                'contract_document_id' => $document->id,
            ], $request->user());
        }

        return $this->created(
            ContractDocumentResource::make($document->load('templateVariant')),
            'Contract document generated successfully.'
        );
    }

    public function regenerate(Request $request, Contract $contract, ContractDocument $document): JsonResponse
    {
        Gate::authorize(Permission::ContractSign->value, $contract);

        $this->assertBelongs($contract, $document);

        if ($document->status !== ContractDocumentStatus::Draft) {
            return $this->error(__('errors.documents.regenerate_frozen'), [], 422);
        }

        $validated = $request->validate([
            'template_family_id' => ['sometimes', 'nullable', 'integer', 'exists:template_families,id'],
            'template_variant_id' => ['sometimes', 'nullable', 'integer', 'exists:template_variants,id'],
            'locale' => ['sometimes', 'nullable', 'string', 'max:8'],
        ]);

        $family = $this->resolveFamily($validated['template_family_id'] ?? $document->template_family_id);
        $resolved = $this->resolveVariant($contract, $family, $validated, $document->template_variant_id);
        $variant = $resolved['variant'];

        $newDocument = DB::transaction(function () use ($contract, $document, $family, $variant): ContractDocument {
            $document->update(['status' => ContractDocumentStatus::Superseded]);

            return $this->generateSnapshot($contract, $family, $variant);
        });

        if ($resolved['overridden']) {
            RecordsActivity::core('contract.document.locale_overridden', $contract, [
                'resolved_locale' => $resolved['resolved_locale'],
                'chosen_locale' => $variant->locale,
                'template_variant_id' => $variant->id,
                'contract_document_id' => $newDocument->id,
                'superseded_document_id' => $document->id,
            ], $request->user());
        }

        return $this->success(
            ContractDocumentResource::make($newDocument->load('templateVariant')),
            'Contract document regenerated successfully.'
        );
    }

    public function pdf(Contract $contract, ContractDocument $document): Response
    {
        Gate::authorize(Permission::ContractView->value, $contract);

        $this->assertBelongs($contract, $document);

        $disk = Storage::disk('local');
        if (! $disk->exists($document->pdf_path)) {
            return response('Document not available.', 404);
        }

        return response($disk->get($document->pdf_path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="contract-'.$contract->id.'-'.$document->id.'.pdf"',
        ]);
    }

    public function preview(Request $request, Contract $contract): Response
    {
        Gate::authorize(Permission::ContractView->value, $contract);

        $validated = $request->validate([
            'template_family_id' => ['sometimes', 'nullable', 'integer', 'exists:template_families,id'],
            'template_variant_id' => ['sometimes', 'nullable', 'integer', 'exists:template_variants,id'],
            'locale' => ['sometimes', 'nullable', 'string', 'max:8'],
        ]);

        $family = $this->resolveFamily($validated['template_family_id'] ?? null);
        $resolved = $this->resolveVariant($contract, $family, $validated);
        $rendered = ContractDocumentRenderer::render($contract, $resolved['variant']);

        return response($rendered['html'], 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    private function resolveFamily(?int $familyId): TemplateFamily
    {
        if ($familyId !== null) {
            $family = TemplateFamily::query()
                ->notArchived()
                ->where('channel', TemplateChannel::Document)
                ->find($familyId);
            if ($family === null) {
                throw ValidationException::withMessages([
                    'template_family_id' => [__('errors.documents.family_not_found')],
                ]);
            }

            return $family;
        }

        $family = TemplateFamily::query()
            ->notArchived()
            ->where('channel', TemplateChannel::Document)
            ->where('purpose', TemplatePurpose::Contract)
            ->orderBy('id')
            ->first();

        if ($family === null) {
            throw ValidationException::withMessages([
                'template_family_id' => [__('errors.documents.family_not_found')],
            ]);
        }

        return $family;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{variant: TemplateVariant, overridden: bool, resolved_locale: string}
     */
    private function resolveVariant(
        Contract $contract,
        TemplateFamily $family,
        array $validated,
        ?int $fallbackVariantId = null,
    ): array {
        $contract->loadMissing(['contact', 'unitItem.item.site']);
        $site = null;
        $unit = $contract->unitItem?->item;
        if ($unit instanceof \App\Models\Unit) {
            $site = $unit->site;
        }

        $ladderVariant = TemplateResolver::variant($family, $contract->contact, $site);

        if (! empty($validated['template_variant_id'])) {
            $variant = TemplateVariant::query()->findOrFail($validated['template_variant_id']);
            if ($variant->template_family_id !== $family->id) {
                throw ValidationException::withMessages([
                    'template_variant_id' => [__('errors.templates.variant_mismatch')],
                ]);
            }

            return [
                'variant' => $variant,
                'overridden' => $variant->id !== $ladderVariant->id,
                'resolved_locale' => $ladderVariant->locale,
            ];
        }

        if (! empty($validated['locale'])) {
            $family->loadMissing('variants');
            $variant = $family->variants->firstWhere('locale', $validated['locale']);
            if ($variant === null) {
                throw ValidationException::withMessages([
                    'locale' => ['No variant exists for locale '.$validated['locale'].'.'],
                ]);
            }

            return [
                'variant' => $variant,
                'overridden' => $variant->locale !== $ladderVariant->locale,
                'resolved_locale' => $ladderVariant->locale,
            ];
        }

        if ($fallbackVariantId !== null) {
            $variant = TemplateVariant::query()->findOrFail($fallbackVariantId);

            return [
                'variant' => $variant,
                'overridden' => false,
                'resolved_locale' => $ladderVariant->locale,
            ];
        }

        return [
            'variant' => $ladderVariant,
            'overridden' => false,
            'resolved_locale' => $ladderVariant->locale,
        ];
    }

    private function generateSnapshot(
        Contract $contract,
        TemplateFamily $family,
        TemplateVariant $variant,
    ): ContractDocument {
        $rendered = ContractDocumentRenderer::render($contract, $variant);
        $sha256 = hash('sha256', $rendered['pdf_bytes']);
        $path = 'contract-documents/'.$contract->id.'/'.uniqid('doc_', true).'.pdf';

        Storage::disk('local')->put($path, $rendered['pdf_bytes']);

        return ContractDocument::query()->create([
            'contract_id' => $contract->id,
            'template_family_id' => $family->id,
            'template_variant_id' => $variant->id,
            'rendered_at' => now(),
            'pdf_path' => $path,
            'sha256' => $sha256,
            'status' => ContractDocumentStatus::Draft,
        ]);
    }

    private function assertBelongs(Contract $contract, ContractDocument $document): void
    {
        if ($document->contract_id !== $contract->id) {
            abort(404, __('errors.documents.document_mismatch'));
        }
    }
}
