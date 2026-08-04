<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Single-use employee invite. Raw token is shown once and never stored —
 * only sha256(token) lives in token_hash.
 *
 * @property int         $id
 * @property int         $employee_id
 * @property string      $token_hash
 * @property Carbon      $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $revoked_at
 * @property int|null    $invited_by
 * @property Carbon      $created_at
 * @property Carbon      $updated_at
 *
 * @property-read Employee      $employee
 * @property-read Employee|null $invitedBy
 */
class EmployeeInvitation extends Model
{
    public const DEFAULT_TTL_DAYS = 7;

    protected $fillable = [
        'employee_id',
        'token_hash',
        'expires_at',
        'accepted_at',
        'revoked_at',
        'invited_by',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Issue a new invitation. Returns [invitation, rawToken].
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(Employee $employee, ?Employee $invitedBy = null): array
    {
        $raw = Str::random(64);

        $invitation = self::query()->create([
            'employee_id' => $employee->id,
            'token_hash' => self::hashToken($raw),
            'expires_at' => now()->addDays(self::DEFAULT_TTL_DAYS),
            'invited_by' => $invitedBy?->id,
        ]);

        return [$invitation, $raw];
    }

    public function isOpen(): bool
    {
        return $this->accepted_at === null
            && $this->revoked_at === null
            && $this->expires_at->isFuture();
    }

    public function isSpent(): bool
    {
        return $this->accepted_at !== null || $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->accepted_at === null
            && $this->revoked_at === null
            && $this->expires_at->isPast();
    }

    /** @param  Builder<self>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'invited_by');
    }
}
