<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Singleton row: the deployment's locked country identity.
 *
 * @property int $id
 * @property string $country_code
 * @property Carbon|null $locked_at
 */
class DeploymentIdentity extends Model
{
    public const SINGLETON_ID = 1;

    protected $table = 'deployment_identity';

    protected $fillable = [
        'id',
        'country_code',
        'locked_at',
    ];

    protected function casts(): array
    {
        return [
            'locked_at' => 'immutable_datetime',
        ];
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    public static function instance(): ?self
    {
        return self::query()->find(self::SINGLETON_ID);
    }
}
