<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Base class for all tenant-scoped models.
 *
 * The 'tenant' connection is resolved dynamically at request time by the
 * tenant resolution middleware, which calls:
 *   config(['database.connections.tenant' => $connectionConfig]);
 *   DB::purge('tenant');
 *
 * All models extending this class will use whichever tenant DB is currently
 * active for the request — no company_id column is needed.
 */
abstract class TenantModel extends Model
{
    protected $connection = 'tenant';
}
