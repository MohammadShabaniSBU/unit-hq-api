<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\EsignEnvelopeResource;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\EsignEnvelope;
use App\Support\ESign\EnvelopeOrchestrator;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class EsignEnvelopeController extends Controller
{
    public function index(Contract $contract): JsonResponse
    {
        $envelopes = $contract->esignEnvelopes()
            ->with('contractDocument')
            ->latest('id')
            ->get();

        return $this->success(
            EsignEnvelopeResource::collection($envelopes),
            'E-sign envelopes retrieved successfully.'
        );
    }

    public function store(Request $request, Contract $contract, EnvelopeOrchestrator $orchestrator): JsonResponse
    {
        $validated = $request->validate([
            'contract_document_id' => ['sometimes', 'nullable', 'integer', 'exists:contract_documents,id'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $expiresAt = isset($validated['expires_at'])
            ? CarbonImmutable::parse($validated['expires_at'])
            : null;

        $envelope = $orchestrator->send(
            $contract,
            $validated['contract_document_id'] ?? null,
            $expiresAt,
            $this->actor($request),
        );

        return $this->created(
            EsignEnvelopeResource::make($envelope->load('contractDocument')),
            'E-sign envelope sent successfully.'
        );
    }

    public function resend(
        Request $request,
        Contract $contract,
        EsignEnvelope $envelope,
        EnvelopeOrchestrator $orchestrator,
    ): JsonResponse {
        $this->assertBelongs($contract, $envelope);

        $validated = $request->validate([
            'contract_document_id' => ['sometimes', 'nullable', 'integer', 'exists:contract_documents,id'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $expiresAt = isset($validated['expires_at'])
            ? CarbonImmutable::parse($validated['expires_at'])
            : null;

        $newEnvelope = $orchestrator->resend(
            $contract,
            $envelope,
            $validated['contract_document_id'] ?? null,
            $expiresAt,
            $this->actor($request),
        );

        return $this->created(
            EsignEnvelopeResource::make($newEnvelope->load('contractDocument')),
            'E-sign envelope resent successfully.'
        );
    }

    public function cancel(
        Request $request,
        Contract $contract,
        EsignEnvelope $envelope,
        EnvelopeOrchestrator $orchestrator,
    ): JsonResponse {
        $this->assertBelongs($contract, $envelope);

        $cancelled = $orchestrator->cancel(
            $contract,
            $envelope,
            $this->actor($request),
        );

        return $this->success(
            EsignEnvelopeResource::make($cancelled->load('contractDocument')),
            'E-sign envelope cancelled successfully.'
        );
    }

    public function signedPdf(Contract $contract, EsignEnvelope $envelope): Response
    {
        $this->assertBelongs($contract, $envelope);

        return $this->streamArtifact($envelope->signed_pdf_path, 'signed.pdf');
    }

    public function certificate(Contract $contract, EsignEnvelope $envelope): Response
    {
        $this->assertBelongs($contract, $envelope);

        return $this->streamArtifact($envelope->certificate_path, 'certificate.pdf');
    }

    private function streamArtifact(?string $path, string $filename): Response
    {
        if ($path === null || $path === '') {
            return response(__('errors.esign.artifact_missing'), 404);
        }

        $disk = Storage::disk('local');
        if (! $disk->exists($path)) {
            return response(__('errors.esign.artifact_missing'), 404);
        }

        return response($disk->get($path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }

    /**
     * @throws ValidationException
     */
    private function assertBelongs(Contract $contract, EsignEnvelope $envelope): void
    {
        if ($envelope->contract_id !== $contract->id) {
            throw ValidationException::withMessages([
                'envelope' => [__('errors.esign.envelope_mismatch')],
            ]);
        }
    }

    private function actor(Request $request): ?Employee
    {
        $user = $request->user();

        return $user instanceof Employee ? $user : null;
    }
}
