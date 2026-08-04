<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Employee;
use App\Models\MessageThread;
use App\Support\Communications\Channel;

/**
 * Seed-end inbox assignment polish. Unread/triage/WA timing come from timed events;
 * this only distributes assignees across live threads.
 */
final class InboxStaging
{
    private const MIN_OPERATOR_THREADS = 8;

    public static function apply(DemoWorld $world): void
    {
        $employees = Employee::query()->orderBy('id')->get();
        if ($employees->isEmpty()) {
            return;
        }

        $operator = Employee::query()->withCompanyRole('owner')->orderBy('id')->first()
            ?? $employees->first();

        $staff = $employees->where('id', '!=', $operator->id)->values();

        $threads = MessageThread::query()
            ->where('unread_count', '>', 0)
            ->orderByDesc('last_message_at')
            ->limit(20)
            ->get();

        $i = 0;
        foreach ($threads as $thread) {
            // 2 operator, 3 unassigned, rest across staff.
            if ($i < 2) {
                $thread->forceFill(['assigned_employee_id' => $operator->id])->save();
            } elseif ($i < 5) {
                $thread->forceFill(['assigned_employee_id' => null])->save();
            } elseif ($staff->isNotEmpty()) {
                $assignee = $staff[$i % $staff->count()];
                $thread->forceFill(['assigned_employee_id' => $assignee->id])->save();
            }
            $i++;
        }

        // Ensure a few assigned/unassigned rows exist even if unread < 5.
        $extras = MessageThread::query()
            ->whereNull('assigned_employee_id')
            ->where('channel', '!=', Channel::Call->value)
            ->orderByDesc('last_message_at')
            ->limit(4)
            ->get();

        foreach ($extras as $idx => $thread) {
            if ($idx < 2) {
                $thread->forceFill(['assigned_employee_id' => $operator->id])->save();
            }
        }

        self::guaranteeOperatorMine($operator);

        $world->remember('inbox.staged', true);
    }

    /**
     * Mine must not be empty for the demo manager after seed / --inbox-only.
     */
    private static function guaranteeOperatorMine(Employee $operator): void
    {
        $assigned = MessageThread::query()
            ->where('assigned_employee_id', $operator->id)
            ->count();

        $needed = self::MIN_OPERATOR_THREADS - $assigned;
        if ($needed <= 0) {
            return;
        }

        $candidates = MessageThread::query()
            ->where(function ($query) use ($operator): void {
                $query->whereNull('assigned_employee_id')
                    ->orWhere('assigned_employee_id', '!=', $operator->id);
            })
            ->orderByDesc('last_message_at')
            ->limit($needed)
            ->get();

        foreach ($candidates as $thread) {
            $thread->forceFill(['assigned_employee_id' => $operator->id])->save();
        }
    }
}
