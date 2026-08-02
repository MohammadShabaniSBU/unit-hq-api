<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AccessGrantState;
use App\Http\Resources\AccessGrantResource;
use App\Models\AccessGrant;
use App\Support\Access\AccessSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class AccessGrantController extends Controller
{
    public function retry(AccessGrant $accessGrant): JsonResponse
    {
        $state = $accessGrant->state instanceof AccessGrantState
            ? $accessGrant->state
            : AccessGrantState::from((string) $accessGrant->state);

        if ($state !== AccessGrantState::Failed) {
            throw ValidationException::withMessages([
                'grant' => ['Only failed grants can be retried.'],
            ]);
        }

        AccessSync::nudge((int) $accessGrant->contract_id);

        $accessGrant->load(['accessPoint:id,label,point_type,unit_id,site_id', 'contact:id,first_name,last_name']);

        return $this->success(
            AccessGrantResource::make($accessGrant)->resolve(),
            'Access grant retry queued successfully.',
        );
    }
}
