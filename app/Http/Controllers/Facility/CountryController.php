<?php

namespace App\Http\Controllers\Facility;

use App\Http\Controllers\Controller;
use App\Models\Country;
use Illuminate\Http\JsonResponse;

class CountryController extends Controller
{
    public function options(): JsonResponse
    {
        $options = Country::query()->orderBy('name')->get(['id', 'code', 'name'])
            ->map(fn (Country $country) => [
                'value' => $country->id,
                'code' => $country->code,
                'title' => $country->name,
            ]);

        return $this->success($options, 'Country options retrieved successfully.');
    }
}
