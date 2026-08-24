<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\AiAgentResource;
use App\Models\AiAgent;
use App\Support\Auth\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class AiAgentController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize(Permission::AiAgentUse->value);

        $agents = AiAgent::query()->active()->with('writePolicies')->orderBy('key')->get();

        return response()->json([
            'message' => 'Agents retrieved successfully.',
            'data' => AiAgentResource::collection($agents)->resolve(),
            'meta' => [
                'demo_enabled' => filter_var(config('agents.demo_enabled'), FILTER_VALIDATE_BOOLEAN),
            ],
        ]);
    }
}
