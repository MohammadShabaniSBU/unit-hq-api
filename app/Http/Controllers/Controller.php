<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Traits\ApiResponsable;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    use ApiResponsable;
    use AuthorizesRequests;
}
