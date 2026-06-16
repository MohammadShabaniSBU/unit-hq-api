<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * Company staff who operate the tenant dashboard.
 * Authenticatable within the tenant DB — no cross-tenant access.
 *
 * @property int    $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property string $role   manager|staff
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @property-read Collection<int, Price>   $createdPrices
 * @property-read Collection<int, Task>    $assignedTasks
 * @property-read Collection<int, Task>    $createdTasks
 * @property-read Collection<int, Comment> $comments
 */
#[Fillable(['name', 'email', 'password', 'role'])]
#[Hidden(['password', 'remember_token'])]
class Employee extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $connection = 'tenant';

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    /** @return HasMany<Price> */
    public function createdPrices(): HasMany
    {
        return $this->hasMany(Price::class, 'created_by');
    }

    /** @return HasMany<Task> */
    public function assignedTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assigned_to');
    }

    /** @return HasMany<Task> */
    public function createdTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'created_by');
    }

    /** @return HasMany<Comment> */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }
}
