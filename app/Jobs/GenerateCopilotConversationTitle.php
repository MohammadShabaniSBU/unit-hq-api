<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\Agents\CopilotTitleAgent;
use App\Events\CopilotConversationTitled;
use App\Models\AiUsageEvent;
use App\Models\CopilotConversation;
use App\Models\Employee;
use App\Support\RequestId;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Throwable;

class GenerateCopilotConversationTitle implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public int $uniqueFor = 120;

    public function __construct(
        public readonly string $conversationId,
        public readonly int $employeeId,
        public readonly string $message,
    ) {
        $this->onQueue('ai');
    }

    public function uniqueId(): string
    {
        return 'copilot-title:'.$this->conversationId;
    }

    public function handle(): void
    {
        $conversation = $this->untitledConversation();
        if ($conversation === null) {
            return;
        }

        $employee = Employee::query()->find($this->employeeId);
        $title = $employee instanceof Employee
            ? $this->generate($employee)
            : $this->fallback();

        $conversation = $this->untitledConversation();
        if ($conversation === null) {
            return;
        }

        $conversation->forceFill(['title' => $title])->save();

        event(new CopilotConversationTitled(
            conversationId: $conversation->id,
            employeeId: $this->employeeId,
            title: $title,
        ));
    }

    private function untitledConversation(): ?CopilotConversation
    {
        $conversation = CopilotConversation::query()->find($this->conversationId);
        if ($conversation === null || $conversation->title !== CopilotConversation::UNTITLED) {
            return null;
        }

        return $conversation;
    }

    private function generate(Employee $employee): string
    {
        $callId = (string) Str::uuid7();
        Context::add([
            'ai_call_id' => $callId,
            'employee_id' => $employee->id,
            'ai_purpose' => 'title',
            'conversation_id' => $this->conversationId,
            'request_id' => RequestId::get() ?? $callId,
        ]);

        try {
            $response = (new CopilotTitleAgent($employee))->prompt(
                Str::limit($this->message, 500),
                timeout: 20,
            );
        } catch (Throwable) {
            AiUsageEvent::markFailed($callId);

            return $this->fallback();
        }

        return $this->normalize((string) $response->text);
    }

    private function normalize(string $raw): string
    {
        $title = trim(preg_replace('/\s+/', ' ', $raw) ?? '');
        $title = trim($title, " \t\n\r\0\x0B\"'`");

        if ($title === '') {
            return $this->fallback();
        }

        return Str::limit($title, 100, '');
    }

    private function fallback(): string
    {
        return Str::limit($this->message, 50, '...');
    }
}
