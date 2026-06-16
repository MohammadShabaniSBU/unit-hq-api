<?php

namespace App\Models;

use Illuminate\Support\Carbon;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as TenantModel;

/**
 * Tenant routing registry. One row per storage company.
 * The platform reads db_connection to resolve which database to use.
 * Stripe Connect account info lives here because it is tenant-wide.
 *
 * @property int         $id
 * @property string      $name
 * @property string      $slug
 * @property array|null  $data
 * @property Carbon      $created_at
 * @property Carbon      $updated_at
 */
class Tenant extends TenantModel implements TenantWithDatabase
{
    use HasDatabase, HasDomains;

    public static function getCustomColumns(): array
    {
        return [
            'id',
            'name',
            'slug',
        ];
    }
}
