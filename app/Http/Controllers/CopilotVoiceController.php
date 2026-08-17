<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CopilotVoiceSession;
use App\Models\Employee;
use App\Support\Auth\Permission;
use App\Support\RecordsActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class CopilotVoiceController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        Gate::authorize(Permission::CopilotVoiceUse->value);

        $key = config('services.vocal_bridge.key');
        if (! is_string($key) || $key === '') {
            return $this->error('errors.voice.not_configured', [], 422);
        }

        /** @var Employee $employee */
        $employee = $request->user();
        $tokenUrl = (string) config('services.vocal_bridge.token_url');
        $agentId = config('services.vocal_bridge.agent_id');
        $headers = ['X-API-Key' => $key];
        if (is_string($agentId) && $agentId !== '') {
            $headers['X-Agent-Id'] = $agentId;
        }

        try {
            $response = Http::withHeaders($headers)
                ->timeout(8)
                ->acceptJson()
                ->post($tokenUrl, ['participant_name' => $employee->name]);
        } catch (Throwable $e) {
            report($e);
            Log::warning('vocal_bridge.token_unavailable', ['status' => 0]);

            return $this->error('errors.voice.token_unavailable', [], 502);
        }

        if (! $response->successful()) {
            Log::warning('vocal_bridge.token_unavailable', ['status' => $response->status()]);

            return $this->error('errors.voice.token_unavailable', [], 502);
        }

        $payload = $response->json();
        $connectionUrl = is_array($payload)
            ? ($payload['url'] ?? $payload['livekit_url'] ?? null)
            : null;
        if (! is_array($payload) || ! is_string($payload['token'] ?? null) || ! is_string($connectionUrl) || $connectionUrl === '') {
            Log::warning('vocal_bridge.token_unavailable', ['status' => $response->status()]);

            return $this->error('errors.voice.token_unavailable', [], 502);
        }

        $session = CopilotVoiceSession::query()->create([
            'employee_id' => $employee->id,
            'started_at' => now(),
        ]);

        RecordsActivity::core('copilot.voice.session_started', $session, [
            'employee_id' => $employee->id,
        ], $employee);

        return $this->created([
            'session_id' => $session->id,
            'url' => $connectionUrl,
            'token' => $payload['token'],
            'room_name' => $payload['room_name'] ?? '',
            'participant_identity' => $payload['participant_identity'] ?? '',
            'expires_in' => $payload['expires_in'] ?? 0,
            'agent_mode' => $payload['agent_mode'] ?? null,
            'livekit_url' => $payload['livekit_url'] ?? $connectionUrl,
        ], 'Voice token created.');
    }

    public function update(Request $request, CopilotVoiceSession $session): JsonResponse
    {
        Gate::authorize(Permission::CopilotVoiceUse->value);

        /** @var Employee $employee */
        $employee = $request->user();

        if ($session->employee_id !== $employee->id) {
            abort(403);
        }

        if ($session->ended_at !== null) {
            return $this->error('errors.voice.session_already_ended', [], 409);
        }

        $validated = $request->validate([
            'vb_session_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'conversation_id' => ['sometimes', 'nullable', 'string', 'max:36'],
            'duration_seconds' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'turn_count' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'end_reason' => ['sometimes', 'nullable', 'string', 'in:hangup,error,timeout'],
        ]);

        $session->fill($validated);
        $session->ended_at = now();
        $session->save();

        RecordsActivity::core('copilot.voice.session_ended', $session, [
            'employee_id' => $employee->id,
            'duration_seconds' => $session->duration_seconds,
            'turn_count' => $session->turn_count,
            'end_reason' => $session->end_reason,
        ], $employee);

        return $this->success([
            'id' => $session->id,
            'ended_at' => $session->ended_at,
            'duration_seconds' => $session->duration_seconds,
            'turn_count' => $session->turn_count,
            'end_reason' => $session->end_reason,
        ], 'Voice session ended.');
    }
}
