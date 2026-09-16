<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Country\CountryProfiles;
use Illuminate\Http\JsonResponse;

class DeploymentController extends Controller
{
    public function show(): JsonResponse
    {
        return $this->success(CountryProfiles::apiPayload());
    }
}
