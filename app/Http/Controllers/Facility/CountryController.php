<?php

namespace App\Http\Controllers\Facility;

use App\Http\Controllers\Controller;
use App\Models\Country;
use Illuminate\Http\JsonResponse;

class CountryController extends Controller
{
    public function options(): JsonResponse
    {
        $options = Country::query()->orderBy('name')->get(['id', 'name'])
            ->map(fn (Country $country) => ['value' => $country->id, 'title' => $country->name]);

        return $this->success($options, 'Country options retrieved successfully.');
    }
}
