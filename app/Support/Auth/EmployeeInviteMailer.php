<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\Employee;
use App\Support\Communications\Channel;
use App\Support\Communications\Contracts\SendsEmail;
use App\Support\Communications\Exceptions\ChannelNotConfigured;
use App\Support\Communications\Exceptions\ProviderRequestFailed;
use App\Support\Communications\Exceptions\UnsupportedCapability;
use App\Support\Communications\Messages\EmailAddress;
use App\Support\Communications\Messages\EmailMessage;
use App\Support\Communications\ProviderResolver;
use Illuminate\Support\Facades\Log;

/**
 * Company-scoped invite email (no site / contact thread). Uses the same
 * ProviderResolver + SendsEmail adapters as EmailSender; swallows
 * ChannelNotConfigured so day-one deploys still return a copyable link.
 */
final class EmployeeInviteMailer
{
    public function __construct(
        private readonly ProviderResolver $resolver,
    ) {}

    public function trySend(Employee $employee, string $inviteLink): bool
    {
        try {
            $resolved = $this->resolver->resolve(Channel::Email, null);
            $adapter = $resolved->require(SendsEmail::class, 'sending employee invite');
        } catch (ChannelNotConfigured|UnsupportedCapability) {
            return false;
        }

        $companyName = (string) config('app.name', 'Unit HQ');
        $subject = "You're invited to {$companyName}";
        $text = "Hi {$employee->first_name},\n\n"
            ."You've been invited to {$companyName}. Set your password using this link:\n\n"
            ."{$inviteLink}\n\n"
            ."This link expires and can only be used once.\n";
        $html = '<p>Hi '.e($employee->first_name).',</p>'
            .'<p>You&rsquo;ve been invited to '.e($companyName)
            .'. <a href="'.e($inviteLink).'">Set your password</a>.</p>'
            .'<p>This link expires and can only be used once.</p>';

        $fromEmail = (string) ($resolved->account->credentials['from_email'] ?? '');
        $from = $fromEmail !== ''
            ? new EmailAddress($fromEmail, $companyName)
            : null;

        $message = new EmailMessage(
            to: [new EmailAddress($employee->email, $employee->name)],
            subject: $subject,
            html: $html,
            text: $text,
            from: $from,
            tags: ['employee-invite'],
        );

        try {
            $adapter->sendEmail($message);
        } catch (ProviderRequestFailed $e) {
            Log::warning('employee.invite.send_failed', [
                'employee_id' => $employee->id,
                'message' => $e->getMessage(),
            ]);

            return false;
        }

        return true;
    }
}
