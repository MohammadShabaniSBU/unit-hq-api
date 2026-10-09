<?php

declare(strict_types=1);

namespace App\Support\Marketing;

use App\Enums\ContactChannelType;
use App\Models\Contact;
use App\Models\ContactChannel;
use App\Models\Employee;
use App\Models\SubscriptionList;
use App\Support\Auth\Permission;
use App\Support\Communications\Channel;
use App\Support\Communications\ContactChannelMatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * CSV membership import. Matching uses ContactChannelMatcher, plus the
 * contact email column (portal identity is not a channel row).
 * Unmatched rows create contacts only when create_missing is set.
 */
final class SubscriptionListImport
{
    public const int MAX_ROWS = 5000;

    /** @var list<string> */
    public const COLUMNS = [
        'email',
        'phone',
        'name',
        'consent_date',
        'original_source',
    ];

    public static function run(
        SubscriptionList $list,
        Employee $employee,
        string $contents,
        bool $confirm,
        bool $createMissing,
        ?int $consentTextId,
    ): SubscriptionListImportResult {
        if ($list->isArchived()) {
            return new SubscriptionListImportResult(errorKey: 'list_archived');
        }

        $parsed = self::parse($contents);
        if ($parsed['error'] !== null) {
            return new SubscriptionListImportResult(errorKey: $parsed['error']);
        }

        $batch = (string) Str::uuid();
        $result = new SubscriptionListImportResult(dryRun: ! $confirm);

        $apply = function () use (
            $list,
            $employee,
            $confirm,
            $createMissing,
            $consentTextId,
            $parsed,
            $batch,
            $result,
        ): void {
            /** @var array<string, int> $batchContacts address key => contact id; negative ids exist only on a dry run */
            $batchContacts = [];
            $nextSynthetic = -1;

            foreach ($parsed['rows'] as $row) {
                $outcome = self::classifyRow(
                    $list,
                    $row,
                    $createMissing,
                    $batchContacts,
                );

                if ($outcome['status'] === 'rejected') {
                    $result->rejected++;
                    $result->rows[] = self::reportRow($row['line'], 'rejected', $outcome['reason'], null, []);

                    continue;
                }

                $contactId = $outcome['contact_id'];
                if ($outcome['status'] === 'created') {
                    if ($confirm) {
                        $contactId = self::createContact($employee, $list, $outcome)->id;
                    } else {
                        $contactId = $nextSynthetic;
                        $nextSynthetic--;
                    }
                }

                if ($contactId !== null) {
                    foreach ($outcome['address_keys'] as $addressKey) {
                        $batchContacts[$addressKey] = $contactId;
                    }
                }

                if ($confirm && $contactId !== null && $contactId > 0) {
                    $contact = Contact::query()->findOrFail($contactId);
                    foreach ($outcome['channels'] as $channel) {
                        ConsentWriter::grant(
                            $contact,
                            $channel,
                            $list,
                            ConsentSource::Import,
                            $consentTextId,
                            [
                                'import_batch' => $batch,
                                'original_source' => $outcome['original_source'],
                                'row' => $row['line'],
                            ],
                            $employee,
                            $outcome['occurred_at'],
                        );
                    }
                }

                if ($outcome['status'] === 'created') {
                    $result->created++;
                } else {
                    $result->matched++;
                }

                $result->rows[] = self::reportRow(
                    $row['line'],
                    $outcome['status'],
                    null,
                    $contactId,
                    array_map(static fn (Channel $channel): string => $channel->value, $outcome['channels']),
                );
            }
        };

        if ($confirm) {
            DB::transaction($apply);
        } else {
            $apply();
        }

        return $result;
    }

    /**
     * @return array{error: string|null, rows: list<array{line: int, email: string, phone: string, name: string, consent_date: string, original_source: string}>}
     */
    private static function parse(string $contents): array
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        if (trim($contents) === '') {
            return ['error' => 'csv_missing_header', 'rows' => []];
        }

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return ['error' => 'csv_missing_header', 'rows' => []];
        }

        fwrite($handle, $contents);
        rewind($handle);

        $header = fgetcsv($handle);
        if ($header === false || $header === [null]) {
            fclose($handle);

            return ['error' => 'csv_missing_header', 'rows' => []];
        }

        $indexes = [];
        foreach ($header as $position => $cell) {
            $name = strtolower(trim((string) $cell));
            if ($name !== '') {
                $indexes[$name] = $position;
            }
        }

        foreach (self::COLUMNS as $column) {
            if (! array_key_exists($column, $indexes)) {
                fclose($handle);

                return ['error' => 'csv_invalid_header', 'rows' => []];
            }
        }

        $rows = [];
        $line = 1;
        while (($cells = fgetcsv($handle)) !== false) {
            $line++;
            if ($cells === [null] || self::blank($cells)) {
                continue;
            }

            $rows[] = [
                'line' => $line,
                'email' => trim((string) ($cells[$indexes['email']] ?? '')),
                'phone' => trim((string) ($cells[$indexes['phone']] ?? '')),
                'name' => trim((string) ($cells[$indexes['name']] ?? '')),
                'consent_date' => trim((string) ($cells[$indexes['consent_date']] ?? '')),
                'original_source' => trim((string) ($cells[$indexes['original_source']] ?? '')),
            ];

            if (count($rows) > self::MAX_ROWS) {
                fclose($handle);

                return ['error' => 'csv_too_many_rows', 'rows' => []];
            }
        }

        fclose($handle);

        return ['error' => null, 'rows' => $rows];
    }

    /**
     * @param  array{line: int, email: string, phone: string, name: string, consent_date: string, original_source: string}  $row
     * @param  array<string, int>  $batchContacts
     * @return array{
     *     status: string,
     *     reason: string|null,
     *     contact_id: int|null,
     *     channels: list<Channel>,
     *     address_keys: list<string>,
     *     original_source: string,
     *     occurred_at: Carbon|null,
     *     first_name: string,
     *     last_name: string,
     *     email: string,
     *     phone: string
     * }
     */
    private static function classifyRow(
        SubscriptionList $list,
        array $row,
        bool $createMissing,
        array $batchContacts,
    ): array {
        $empty = self::emptyOutcome();

        if ($row['original_source'] === '') {
            return [...$empty, 'reason' => 'original_source'];
        }

        $occurredAt = self::parseDate($row['consent_date']);
        if ($occurredAt === null) {
            return [...$empty, 'reason' => 'consent_date'];
        }

        $email = $row['email'];
        $phone = $row['phone'];
        if ($email === '' && $phone === '') {
            return [...$empty, 'reason' => 'address'];
        }

        if ($email !== '' && filter_var(ContactChannelMatcher::normalizeEmail($email), FILTER_VALIDATE_EMAIL) === false) {
            return [...$empty, 'reason' => 'invalid_email'];
        }

        if ($phone !== '' && ContactChannelMatcher::normalizePhone($phone) === '') {
            return [...$empty, 'reason' => 'invalid_phone'];
        }

        $targets = [];
        if ($email !== '' && $list->allows(Channel::Email)) {
            $targets[] = [Channel::Email, $email];
        }
        if ($phone !== '' && $list->allows(Channel::Sms)) {
            $targets[] = [Channel::Sms, $phone];
        }

        if ($targets === []) {
            return [...$empty, 'reason' => 'channel'];
        }

        $contactId = null;
        $addressKeys = [];
        foreach ($targets as [$channel, $raw]) {
            $key = self::addressKey($channel, $raw);
            $addressKeys[] = $key;
            $resolved = self::resolveAddress($channel, $raw, $batchContacts);

            if ($resolved['ambiguous']) {
                return [...$empty, 'reason' => 'ambiguous'];
            }

            if ($resolved['contact_id'] === null) {
                continue;
            }

            if ($contactId !== null && $contactId !== $resolved['contact_id']) {
                return [...$empty, 'reason' => 'conflict'];
            }

            $contactId = $resolved['contact_id'];
        }

        $channels = array_map(static fn (array $target): Channel => $target[0], $targets);
        [$firstName, $lastName] = self::splitName($row['name']);

        if ($contactId === null) {
            if (! $createMissing) {
                return [...$empty, 'reason' => 'unmatched'];
            }

            if ($firstName === '') {
                return [...$empty, 'reason' => 'name'];
            }

            return [
                'status' => 'created',
                'reason' => null,
                'contact_id' => null,
                'channels' => $channels,
                'address_keys' => $addressKeys,
                'original_source' => $row['original_source'],
                'occurred_at' => $occurredAt,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'phone' => $phone,
            ];
        }

        return [
            'status' => 'matched',
            'reason' => null,
            'contact_id' => $contactId,
            'channels' => $channels,
            'address_keys' => $addressKeys,
            'original_source' => $row['original_source'],
            'occurred_at' => $occurredAt,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'phone' => $phone,
        ];
    }

    /**
     * @param  array<string, int>  $batchContacts
     * @return array{contact_id: int|null, ambiguous: bool}
     */
    private static function resolveAddress(Channel $channel, string $raw, array $batchContacts): array
    {
        $match = ContactChannelMatcher::match($channel, $raw);
        if ($match['ambiguous']) {
            return ['contact_id' => null, 'ambiguous' => true];
        }

        $ids = [];
        if ($match['contact'] instanceof Contact) {
            $ids[] = (int) $match['contact']->id;
        }

        if ($channel === Channel::Email) {
            $normalized = ContactChannelMatcher::normalizeEmail($raw);
            $columnIds = Contact::query()
                ->whereNotNull('email')
                ->whereRaw('lower(trim(email)) = ?', [$normalized])
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
            $ids = array_values(array_unique([...$ids, ...$columnIds]));
        }

        if (count($ids) > 1) {
            return ['contact_id' => null, 'ambiguous' => true];
        }

        if ($ids !== []) {
            return ['contact_id' => $ids[0], 'ambiguous' => false];
        }

        $key = self::addressKey($channel, $raw);
        if (array_key_exists($key, $batchContacts)) {
            return ['contact_id' => $batchContacts[$key], 'ambiguous' => false];
        }

        return ['contact_id' => null, 'ambiguous' => false];
    }

    /**
     * @param  array{
     *     first_name: string,
     *     last_name: string,
     *     email: string,
     *     phone: string,
     *     channels: list<Channel>
     * }  $outcome
     */
    private static function createContact(Employee $employee, SubscriptionList $list, array $outcome): Contact
    {
        $email = $outcome['email'] !== ''
            ? ContactChannelMatcher::normalizeEmail($outcome['email'])
            : null;

        $contact = Contact::query()->create([
            'first_name' => $outcome['first_name'],
            'last_name' => $outcome['last_name'],
            'email' => $email,
            'created_by' => $employee->id,
        ]);

        $primary = true;
        foreach ($outcome['channels'] as $channel) {
            $value = $channel === Channel::Email
                ? (string) $email
                : ContactChannelMatcher::normalizePhone($outcome['phone']);

            if ($value === '') {
                continue;
            }

            ContactChannel::query()->create([
                'contact_id' => $contact->id,
                'type' => $channel === Channel::Email ? ContactChannelType::Email : ContactChannelType::Phone,
                'value' => $value,
                'is_primary' => $primary,
                'opted_in' => false,
            ]);
            $primary = false;
        }

        $siteIds = self::siteIdsFor($list, $employee);
        if ($siteIds !== []) {
            $contact->sites()->attach($siteIds);
        }

        return $contact;
    }

    /**
     * @return list<int>
     */
    private static function siteIdsFor(SubscriptionList $list, Employee $employee): array
    {
        if ($list->site_id !== null) {
            return [$list->site_id];
        }

        $granted = $employee->siteIdsFor(Permission::MarketingManage);

        return is_array($granted) ? $granted : [];
    }

    private static function addressKey(Channel $channel, string $raw): string
    {
        return $channel->value.'|'.ContactChannelMatcher::normalize($channel, $raw);
    }

    private static function parseDate(string $value): ?Carbon
    {
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function splitName(string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            return ['', ''];
        }

        $parts = preg_split('/\s+/u', $name, 2) ?: [];
        $first = mb_substr((string) ($parts[0] ?? ''), 0, 100);
        $last = mb_substr((string) ($parts[1] ?? $first), 0, 100);

        return [$first, $last];
    }

    /**
     * @param  list<string|null>  $cells
     */
    private static function blank(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $channels
     * @return array{line: int, status: string, reason: string|null, contact_id: int|null, channels: list<string>}
     */
    private static function reportRow(int $line, string $status, ?string $reason, ?int $contactId, array $channels): array
    {
        return [
            'line' => $line,
            'status' => $status,
            'reason' => $reason,
            'contact_id' => $contactId !== null && $contactId > 0 ? $contactId : null,
            'channels' => $channels,
        ];
    }

    /**
     * @return array{
     *     status: string,
     *     reason: string|null,
     *     contact_id: int|null,
     *     channels: list<Channel>,
     *     address_keys: list<string>,
     *     original_source: string,
     *     occurred_at: Carbon|null,
     *     first_name: string,
     *     last_name: string,
     *     email: string,
     *     phone: string
     * }
     */
    private static function emptyOutcome(): array
    {
        return [
            'status' => 'rejected',
            'reason' => null,
            'contact_id' => null,
            'channels' => [],
            'address_keys' => [],
            'original_source' => '',
            'occurred_at' => null,
            'first_name' => '',
            'last_name' => '',
            'email' => '',
            'phone' => '',
        ];
    }
}
