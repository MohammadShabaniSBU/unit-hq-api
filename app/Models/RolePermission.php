<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Auth\Permission;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int        $id
 * @property int        $role_id
 * @property string     $permission  Permission enum value
 * @property Carbon     $created_at
 * @property Carbon     $updated_at
 *
 * @property-read Role  $role
 */
class RolePermission extends Model
{
    protected $fillable = [
        'role_id',
        'permission',
    ];

    protected function casts(): array
    {
        return [
            'permission' => Permission::class,
        ];
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
