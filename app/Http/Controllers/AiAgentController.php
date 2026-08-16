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

        $agents = AiAgent::query()->active()->orderBy('key')->get();

        return $this->success(
            AiAgentResource::collection($agents)->resolve(),
            'Agents retrieved successfully.',
        );
    }
}
