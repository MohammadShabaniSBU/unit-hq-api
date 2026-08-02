<?php

declare(strict_types=1);

namespace App\Support\Communications;

use App\Enums\ContactChannelType;
use App\Enums\CredentialStatus;
use App\Models\AircallUserLink;
use App\Models\CallIntent;
use App\Models\CommunicationAccount;
use App\Models\Contact;
use App\Models\Employee;
use App\Support\Automation\SubjectChain;
use App\Support\Communications\Providers\AircallAdapter;
use App\Support\Credentials\CredentialMasker;
use Illuminate\Validation\ValidationException;

/**
 * Click-to-dial: record an intent, ask Aircall to dial, never synthesize a Message.
 */
final class CallDialer
{
    public const CONTEXT_TYPES = ['thread', 'delinquency', 'task', 'contact'];

    /**
     * @param  array{type?: string, id?: int}|null  $context
     * @return array{intent: CallIntent}
     */
    public static function dial(
        Employee $employee,
        Contact $contact,
        ?string $toNumber,
        ?array $context,
    ): array {
        $link = AircallUserLink::query()
            ->where('employee_id', $employee->id)
            ->first();

        if ($link === null) {
            throw ValidationException::withMessages([
                'not_mapped' => [
                    'Link your Aircall user in Settings → Communications before placing calls.',
                ],
            ]);
        }

        $number = $toNumber !== null && trim($toNumber) !== ''
            ? ContactChannelMatcher::normalize(Channel::Call, trim($toNumber))
            : null;

        if ($number === null || $number === '') {
            $primary = SubjectChain::primaryChannel($contact, ContactChannelType::Phone);
            $number = $primary !== null
                ? ContactChannelMatcher::normalize(Channel::Call, (string) $primary->value)
                : '';
        }

        if ($number === '' || ! str_starts_with($number, '+')) {
            throw ValidationException::withMessages([
                'to_number' => ['A valid E.164 phone number is required.'],
            ]);
        }

        $contextType = null;
        $contextId = null;
        if (is_array($context) && isset($context['type']) && is_string($context['type'])) {
            if (! in_array($context['type'], self::CONTEXT_TYPES, true)) {
                throw ValidationException::withMessages([
                    'context.type' => ['Context type must be thread, delinquency, task, or contact.'],
                ]);
            }
            $contextType = $context['type'];
            $contextId = isset($context['id']) ? (int) $context['id'] : null;
        }

        $account = self::activeAircallAccount();
        if ($account === null || ! $account->isConnected()) {
            throw ValidationException::withMessages([
                'account' => ['Aircall is not connected. Configure it in Settings → Communications.'],
            ]);
        }

        $credentials = CredentialMasker::readSafely($account, 'credentials');
        if (! is_array($credentials)) {
            throw ValidationException::withMessages([
                'account' => ['Aircall credentials are unreadable. Re-enter them in Settings → Communications.'],
            ]);
        }

        $intent = CallIntent::query()->create([
            'employee_id' => $employee->id,
            'contact_id' => $contact->id,
            'to_number' => $number,
            'context_type' => $contextType,
            'context_id' => $contextId,
            'status' => CallIntent::STATUS_REQUESTED,
        ]);

        $adapter = AircallAdapter::make($credentials);
        $result = $adapter->dial($link->aircall_user_id, $number);

        if (! $result->ok) {
            $intent->forceFill([
                'status' => CallIntent::STATUS_DIAL_FAILED,
                'error' => $result->error,
            ])->save();

            throw ValidationException::withMessages([
                $result->errorKey ?? 'dial' => [$result->error ?? 'Dial failed.'],
            ]);
        }

        if ($result->aircallCallId !== null) {
            $intent->forceFill([
                'aircall_call_id' => $result->aircallCallId,
            ])->save();
        }

        return ['intent' => $intent->fresh() ?? $intent];
    }

    public static function activeAircallAccount(): ?CommunicationAccount
    {
        return CommunicationAccount::query()
            ->where('scope', AccountScope::Company)
            ->whereNull('site_id')
            ->where('channel', Channel::Call)
            ->where('provider', Provider::Aircall)
            ->where('is_active', true)
            ->first();
    }

    public static function markAccountError(CommunicationAccount $account, string $error): void
    {
        $account->forceFill([
            'status' => CredentialStatus::Error,
            'last_error' => $error,
        ])->save();
    }
}
