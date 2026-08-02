<?php

declare(strict_types=1);

namespace App\Support\Communications;

use App\Models\AircallUserLink;
use App\Models\Employee;
use App\Support\Communications\Providers\AircallAdapter;
use App\Support\Credentials\CredentialMasker;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Per-employee dial enablement truth. Cached 60s; buttons never invent state.
 */
final class CallAvailability
{
    private const CACHE_SECONDS = 60;

    private const BUSY_STATUSES = [
        'in_call',
        'after_call_work',
        'do_not_disturb',
    ];

    /**
     * @return array{
     *     mapped: bool,
     *     aircall_user_id: string|null,
     *     aircall_user_label: string|null,
     *     availability: string|null,
     *     can_dial: bool,
     *     disabled_reason: string|null
     * }
     */
    public static function forEmployee(Employee $employee): array
    {
        $cacheKey = 'calls.availability.'.$employee->id;

        /** @var array{
         *     mapped: bool,
         *     aircall_user_id: string|null,
         *     aircall_user_label: string|null,
         *     availability: string|null,
         *     can_dial: bool,
         *     disabled_reason: string|null
         * } $payload
         */
        $payload = Cache::remember($cacheKey, self::CACHE_SECONDS, function () use ($employee): array {
            return self::resolve($employee);
        });

        return $payload;
    }

    public static function forget(Employee $employee): void
    {
        Cache::forget('calls.availability.'.$employee->id);
    }

    /**
     * @return array{
     *     mapped: bool,
     *     aircall_user_id: string|null,
     *     aircall_user_label: string|null,
     *     availability: string|null,
     *     can_dial: bool,
     *     disabled_reason: string|null
     * }
     */
    private static function resolve(Employee $employee): array
    {
        $link = AircallUserLink::query()
            ->where('employee_id', $employee->id)
            ->first();

        if ($link === null) {
            return [
                'mapped' => false,
                'aircall_user_id' => null,
                'aircall_user_label' => null,
                'availability' => null,
                'can_dial' => false,
                'disabled_reason' => 'not_mapped',
            ];
        }

        $account = CallDialer::activeAircallAccount();
        if ($account === null || ! $account->isConnected()) {
            return [
                'mapped' => true,
                'aircall_user_id' => $link->aircall_user_id,
                'aircall_user_label' => $link->aircall_user_label,
                'availability' => null,
                'can_dial' => false,
                'disabled_reason' => 'account_unavailable',
            ];
        }

        $credentials = CredentialMasker::readSafely($account, 'credentials');
        if (! is_array($credentials)) {
            return [
                'mapped' => true,
                'aircall_user_id' => $link->aircall_user_id,
                'aircall_user_label' => $link->aircall_user_label,
                'availability' => null,
                'can_dial' => false,
                'disabled_reason' => 'account_unavailable',
            ];
        }

        try {
            $availability = AircallAdapter::make($credentials)->userAvailability($link->aircall_user_id);
        } catch (RuntimeException) {
            return [
                'mapped' => true,
                'aircall_user_id' => $link->aircall_user_id,
                'aircall_user_label' => $link->aircall_user_label,
                'availability' => null,
                'can_dial' => false,
                'disabled_reason' => 'account_unavailable',
            ];
        }

        if ($availability === 'available') {
            return [
                'mapped' => true,
                'aircall_user_id' => $link->aircall_user_id,
                'aircall_user_label' => $link->aircall_user_label,
                'availability' => $availability,
                'can_dial' => true,
                'disabled_reason' => null,
            ];
        }

        $reason = in_array($availability, self::BUSY_STATUSES, true)
            ? 'user_busy'
            : 'user_offline';

        return [
            'mapped' => true,
            'aircall_user_id' => $link->aircall_user_id,
            'aircall_user_label' => $link->aircall_user_label,
            'availability' => $availability,
            'can_dial' => false,
            'disabled_reason' => $reason,
        ];
    }
}
