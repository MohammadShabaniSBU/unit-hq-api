<?php

declare(strict_types=1);

namespace App\Http\Controllers\Facility;

use App\Http\Controllers\Controller;
use App\Http\Resources\VoiceBridgeTokenResource;
use App\Models\Site;
use App\Models\VoiceBridgeToken;
use App\Support\Auth\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Site-scoped CRUD for VoiceBridgeToken rows. Secret is system-generated
 * and returned once on store / regenerateSecret — see invariant 26b (V03-04).
 */
class VoiceBridgeTokenController extends Controller
{
    private const E164 = ['regex:/^\+[1-9]\d{1,14}$/'];

    public function index(Site $site): JsonResponse
    {
        Gate::authorize(Permission::CredentialManage->value, $site);

        $tokens = $site->voiceBridgeTokens()
            ->orderByDesc('created_at')
            ->get();

        return $this->success(
            VoiceBridgeTokenResource::collection($tokens)->resolve(),
            'Voice bridge tokens retrieved successfully.',
        );
    }

    public function store(Request $request, Site $site): JsonResponse
    {
        Gate::authorize(Permission::CredentialManage->value, $site);

        $validated = $request->validate([
            'phone_number' => ['required', 'string', ...self::E164, 'unique:voice_bridge_tokens,phone_number'],
            'main_line_number' => ['nullable', 'string', ...self::E164],
            'voicemail_number' => ['nullable', 'string', ...self::E164],
            'label' => ['nullable', 'string', 'max:255'],
        ]);

        $token = VoiceBridgeToken::query()->create([
            'site_id' => $site->id,
            'secret' => Str::random(40),
            'phone_number' => $validated['phone_number'],
            'main_line_number' => $validated['main_line_number'] ?? null,
            'voicemail_number' => $validated['voicemail_number'] ?? null,
            'label' => $validated['label'] ?? null,
        ]);

        return $this->created(
            $this->payload($token, withSecret: true),
            'Voice bridge token created successfully.',
        );
    }

    public function update(Request $request, Site $site, VoiceBridgeToken $voiceBridgeToken): JsonResponse
    {
        Gate::authorize(Permission::CredentialManage->value, $site);
        $this->ensureTokenBelongsToSite($voiceBridgeToken, $site);

        $validated = $request->validate([
            'phone_number' => [
                'sometimes',
                'required',
                'string',
                ...self::E164,
                Rule::unique('voice_bridge_tokens', 'phone_number')->ignore($voiceBridgeToken->id),
            ],
            'main_line_number' => ['sometimes', 'nullable', 'string', ...self::E164],
            'voicemail_number' => ['sometimes', 'nullable', 'string', ...self::E164],
            'label' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $voiceBridgeToken->fill($validated);
        $voiceBridgeToken->save();

        return $this->success(
            $this->payload($voiceBridgeToken),
            'Voice bridge token updated successfully.',
        );
    }

    public function regenerateSecret(Site $site, VoiceBridgeToken $voiceBridgeToken): JsonResponse
    {
        Gate::authorize(Permission::CredentialManage->value, $site);
        $this->ensureTokenBelongsToSite($voiceBridgeToken, $site);

        $voiceBridgeToken->secret_previous = $voiceBridgeToken->secret;
        $voiceBridgeToken->secret = Str::random(40);
        $voiceBridgeToken->save();

        return $this->success(
            $this->payload($voiceBridgeToken, withSecret: true),
            'Secret regenerated successfully.',
        );
    }

    public function revoke(Site $site, VoiceBridgeToken $voiceBridgeToken): JsonResponse
    {
        Gate::authorize(Permission::CredentialManage->value, $site);
        $this->ensureTokenBelongsToSite($voiceBridgeToken, $site);

        $voiceBridgeToken->revoked_at = now();
        $voiceBridgeToken->save();

        return $this->success(
            $this->payload($voiceBridgeToken),
            'Voice bridge token revoked successfully.',
        );
    }

    private function ensureTokenBelongsToSite(VoiceBridgeToken $token, Site $site): void
    {
        if ($token->site_id !== $site->id) {
            abort(404);
        }
    }

    /** @return array<string, mixed> */
    private function payload(VoiceBridgeToken $token, bool $withSecret = false): array
    {
        $data = VoiceBridgeTokenResource::make($token)->resolve();

        if ($withSecret) {
            $data['secret'] = $token->secret;
        }

        return $data;
    }
}
