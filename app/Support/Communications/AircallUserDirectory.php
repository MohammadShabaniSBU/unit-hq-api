<?php

declare(strict_types=1);

namespace App\Support\Communications;

use App\Enums\CredentialStatus;
use App\Models\AircallUserLink;
use App\Models\CallIntent;
use App\Models\CommunicationAccount;
use App\Models\Employee;
use App\Support\Communications\Providers\AircallAdapter;
use App\Support\Credentials\CredentialMasker;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Cached Aircall user list + employee mapping for Settings.
 */
final class AircallUserDirectory
{
    private const CACHE_KEY = 'aircall.users.list';

    private const CACHE_TTL_SECONDS = 3600;

    /**
     * @return array{
     *     users: list<array{id: string, label: string, email: string|null, employee_id: int|null, employee_name: string|null}>,
     *     synced_at: string|null,
     *     dial_health: array{uncorrelated_count: int}
     * }
     */
    public static function list(): array
    {
        $account = self::requireConnectedAccount();
        $cached = Cache::get(self::CACHE_KEY);

        /** @var list<array{id: string, label: string, email: string|null, availability_status: string|null}> $users */
        $users = is_array($cached['users'] ?? null) ? $cached['users'] : [];
        $syncedAt = is_string($cached['synced_at'] ?? null) ? $cached['synced_at'] : null;

        return [
            'users' => self::withMappings($users),
            'synced_at' => $syncedAt,
            'dial_health' => self::dialHealth(),
            'account_status' => $account->status->value,
            'last_error' => $account->last_error,
        ];
    }

    /**
     * @return array{
     *     users: list<array{id: string, label: string, email: string|null, employee_id: int|null, employee_name: string|null}>,
     *     synced_at: string|null,
     *     dial_health: array{uncorrelated_count: int}
     * }
     */
    public static function sync(): array
    {
        $account = self::requireConnectedAccount();
        $credentials = CredentialMasker::readSafely($account, 'credentials');
        if (! is_array($credentials)) {
            throw ValidationException::withMessages([
                'account' => ['Aircall credentials are unreadable. Re-enter them in Settings → Communications.'],
            ]);
        }

        try {
            $users = AircallAdapter::make($credentials)->listUsers();
        } catch (RuntimeException $e) {
            $account->forceFill([
                'status' => CredentialStatus::Error,
                'last_error' => $e->getMessage(),
            ])->save();

            throw ValidationException::withMessages([
                'sync' => [$e->getMessage()],
            ]);
        }

        $syncedAt = now()->toIso8601String();
        Cache::put(self::CACHE_KEY, [
            'users' => $users,
            'synced_at' => $syncedAt,
        ], self::CACHE_TTL_SECONDS);

        // Refresh labels on existing links when Aircall renamed the user.
        $byId = collect($users)->keyBy('id');
        foreach (AircallUserLink::query()->get() as $link) {
            $remote = $byId->get($link->aircall_user_id);
            if (is_array($remote) && ($remote['label'] ?? null) !== $link->aircall_user_label) {
                $link->forceFill([
                    'aircall_user_label' => (string) $remote['label'],
                ])->save();
            }
        }

        return [
            'users' => self::withMappings($users),
            'synced_at' => $syncedAt,
            'dial_health' => self::dialHealth(),
            'account_status' => $account->fresh()?->status->value ?? $account->status->value,
            'last_error' => $account->fresh()?->last_error,
        ];
    }

    public static function map(string $aircallUserId, int $employeeId): AircallUserLink
    {
        self::requireConnectedAccount();

        if (Employee::query()->whereKey($employeeId)->doesntExist()) {
            throw ValidationException::withMessages([
                'employee_id' => ['Employee not found.'],
            ]);
        }

        $cached = Cache::get(self::CACHE_KEY);
        /** @var list<array{id: string, label: string}> $users */
        $users = is_array($cached['users'] ?? null) ? $cached['users'] : [];
        $remote = collect($users)->firstWhere('id', $aircallUserId);
        $label = is_array($remote) ? (string) ($remote['label'] ?? $aircallUserId) : $aircallUserId;

        $existingForEmployee = AircallUserLink::query()
            ->where('employee_id', $employeeId)
            ->where('aircall_user_id', '!=', $aircallUserId)
            ->exists();
        if ($existingForEmployee) {
            throw ValidationException::withMessages([
                'employee_id' => ['This employee is already mapped to another Aircall user. Unlink them first.'],
            ]);
        }

        $existingForUser = AircallUserLink::query()
            ->where('aircall_user_id', $aircallUserId)
            ->where('employee_id', '!=', $employeeId)
            ->exists();
        if ($existingForUser) {
            throw ValidationException::withMessages([
                'aircall_user_id' => ['This Aircall user is already mapped to another employee. Unlink them first.'],
            ]);
        }

        try {
            $link = AircallUserLink::query()->updateOrCreate(
                ['aircall_user_id' => $aircallUserId],
                [
                    'employee_id' => $employeeId,
                    'aircall_user_label' => $label,
                ],
            );
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'employee_id' => ['Mapping must be unique for both the employee and the Aircall user.'],
            ]);
        }

        CallAvailability::forget(Employee::query()->findOrFail($employeeId));

        return $link;
    }

    public static function unlink(string $aircallUserId): void
    {
        $link = AircallUserLink::query()
            ->where('aircall_user_id', $aircallUserId)
            ->first();

        if ($link === null) {
            return;
        }

        $employeeId = $link->employee_id;
        $link->delete();
        CallAvailability::forget(Employee::query()->findOrFail($employeeId));
    }

    /**
     * @return array{uncorrelated_count: int}
     */
    public static function dialHealth(): array
    {
        return [
            'uncorrelated_count' => CallIntent::query()
                ->where('status', CallIntent::STATUS_UNCORRELATED)
                ->count(),
        ];
    }

    private static function requireConnectedAccount(): CommunicationAccount
    {
        $account = CallDialer::activeAircallAccount();
        if ($account === null) {
            throw ValidationException::withMessages([
                'account' => ['Connect Aircall in Settings → Communications first.'],
            ]);
        }

        return $account;
    }

    /**
     * @param  list<array{id: string, label: string, email: string|null, availability_status?: string|null}>  $users
     * @return list<array{id: string, label: string, email: string|null, employee_id: int|null, employee_name: string|null}>
     */
    private static function withMappings(array $users): array
    {
        $links = AircallUserLink::query()
            ->with('employee:id,first_name,last_name')
            ->get()
            ->keyBy('aircall_user_id');

        // Include mapped users even if they fell off the last sync cache.
        $knownIds = collect($users)->pluck('id')->all();
        foreach ($links as $aircallUserId => $link) {
            if (! in_array($aircallUserId, $knownIds, true)) {
                $users[] = [
                    'id' => (string) $aircallUserId,
                    'label' => $link->aircall_user_label,
                    'email' => null,
                    'availability_status' => null,
                ];
            }
        }

        $rows = array_map(function (array $user) use ($links): array {
            $id = (string) $user['id'];
            $link = $links->get($id);

            return [
                'id' => $id,
                'label' => $user['label'],
                'email' => $user['email'] ?? null,
                'employee_id' => $link?->employee_id,
                'employee_name' => $link?->employee?->name,
            ];
        }, $users);

        return array_values(collect($rows)->unique('id')->values()->all());
    }
}
