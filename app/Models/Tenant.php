<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Tenant routing registry. One row per storage company.
 * The platform reads db_connection to resolve which database to use.
 * Stripe Connect account info lives here because it is tenant-wide.
 *
 * @property int         $id
 * @property string      $name
 * @property string      $slug
 * @property string      $db_connection              connection name or DSN
 * @property string|null $stripe_connect_account_id
 * @property string      $stripe_onboarding_status   pending|active|restricted
 * @property Carbon      $created_at
 * @property Carbon      $updated_at
 */
class Tenant extends Model
{
    use HasFactory;

    protected array $fillable = [
        'name',
        'slug',
        'db_connection',
        'stripe_connect_account_id',
        'stripe_onboarding_status',
    ];
}
