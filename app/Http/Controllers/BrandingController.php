<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\JsonResponse;

class BrandingController extends Controller
{
    public function show(): JsonResponse
    {
        $general = Setting::general();

        return $this->success([
            'company_name' => $general->companyName,
            'date_format' => $general->dateFormat,
        ]);
    }
}
