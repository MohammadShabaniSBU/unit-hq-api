<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AccessGrant;
use App\Support\Access\AccessState;
use Illuminate\Http\Request;

class AccessGrantResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var AccessGrant $grant */
        $grant = $this->resource;

        return AccessState::grantRow($grant);
    }
}
