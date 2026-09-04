<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\VoiceSessionResource;
use App\Models\Employee;
use App\Models\VoiceSession;
use App\Support\Auth\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class VoiceSessionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize(Permission::AiAgentUse->value);

        /** @var Employee $employee */
        $employee = $request->user();

        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
        ]);

        $query = VoiceSession::query()
            ->visibleTo($employee, Permission::AiAgentUse)
            ->with(['contact', 'site'])
            ->withMax('turns', 'created_at')
            ->withExists([
                'turns as transfer_requested' => fn ($q) => $q->where('transfer', true),
            ])
            ->latest('started_at')
            ->latest('id');

        if (isset($validated['site_id'])) {
            $query->where('site_id', $validated['site_id']);
        }
        if (isset($validated['date_from'])) {
            $query->whereDate('started_at', '>=', $validated['date_from']);
        }
        if (isset($validated['date_to'])) {
            $query->whereDate('started_at', '<=', $validated['date_to']);
        }

        return $this->paginated(
            $query->paginate($this->perPage())->through(
                fn (VoiceSession $session) => VoiceSessionResource::make($session),
            ),
            'Voice sessions retrieved successfully.',
        );
    }

    public function show(VoiceSession $voiceSession): JsonResponse
    {
        Gate::authorize(Permission::AiAgentUse->value, $voiceSession);
        $this->authorize('view', $voiceSession);

        $voiceSession->load([
            'contact',
            'site',
            'turns' => fn ($q) => $q->orderBy('id'),
            'voiceTranscriptSegments' => fn ($q) => $q->orderBy('sequence'),
            'conversation.aiAgent',
            'conversation.contact',
            'conversation.messages',
            'conversation.toolInvocations.pendingAction',
            'conversation.handoffs',
            'conversation.guardrailEvents',
            'conversation.usageEvents',
            'conversation.principalPromotions',
        ]);

        return $this->success(
            VoiceSessionResource::make($voiceSession),
            'Voice session retrieved successfully.',
        );
    }
}
