<?php

declare(strict_types=1);

namespace App\Support\Marketing;

use App\Enums\ContactChannelType;
use App\Enums\ContactLifecycleStatus;
use App\Enums\ContactSource;
use App\Models\ConsentEvent;
use App\Models\ConsentText;
use App\Models\Contact;
use App\Models\ContactChannel;
use App\Models\SignupConfirmation;
use App\Models\SignupForm;
use App\Models\SubscriptionList;
use App\Support\Communications\Channel;
use App\Support\Communications\ContactChannelMatcher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Public signup. Creates a prospect contact when the email is new — the
 * documented exception to inbound-never-creates (S30-07 invariant 79). Never a deal.
 */
final class SignupSubmission
{
    public const MIN_SECONDS = 3;

    public const MAX_AGE_SECONDS = 86400;

    public const CONFIRM_DAYS = 7;

    public static function submit(SignupForm $form, Request $request): void
    {
        $input = $request->validate([
            'email' => ['required', 'string', 'max:255', 'email'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:32'],
            'list_ids' => ['sometimes', 'array'],
            'list_ids.*' => ['integer'],
            'website' => ['nullable', 'string', 'max:500'],
            'opened_at' => ['nullable', 'integer'],
            'locale' => ['nullable', 'string', 'max:5'],
            'consent_text_version' => ['nullable', 'integer'],
        ]);

        $email = ContactChannelMatcher::normalizeEmail((string) $input['email']);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages([
                'email' => __('validation.email', ['attribute' => 'email']),
            ]);
        }

        self::assertRequiredFields($form, $input);
        $listIds = self::selectedListIds($form, $input);

        if (self::dropped($request)) {
            return;
        }

        $locale = self::locale($input['locale'] ?? null);
        $text = self::consentText(
            $form->consent_text_key,
            $locale,
            isset($input['consent_text_version']) ? (int) $input['consent_text_version'] : null,
        );
        if ($text === null) {
            throw ValidationException::withMessages([
                'form' => __('errors.signup_forms.consent_text'),
            ]);
        }

        $lists = self::listsFor($form, $listIds);
        $channel = $form->marketingChannel();
        $evidence = [
            'ip' => (string) ($request->ip() ?? ''),
            'user_agent' => mb_substr((string) ($request->userAgent() ?? ''), 0, 512),
            'form_id' => $form->id,
            'consent_text_version' => $text->version,
        ];

        $names = [
            'first_name' => trim((string) ($input['first_name'] ?? '')),
            'last_name' => trim((string) ($input['last_name'] ?? '')),
            'phone' => self::phone((string) ($input['phone'] ?? '')),
        ];

        $token = DB::transaction(function () use ($form, $email, $names, $lists, $channel, $text, $evidence, $locale): ?string {
            $contact = self::resolveOrCreate($form, $email, $names, $locale);
            $events = [];

            foreach ($lists as $list) {
                if (ConsentState::isGranted((int) $contact->id, $channel, (int) $list->id)) {
                    continue;
                }

                if ($form->double_opt_in) {
                    $events[] = ConsentWriter::pending(
                        $contact,
                        $channel,
                        $list,
                        ConsentSource::Form,
                        $text->id,
                        $evidence,
                    );

                    continue;
                }

                ConsentWriter::grant(
                    $contact,
                    $channel,
                    $list,
                    ConsentSource::Form,
                    $text->id,
                    $evidence,
                );
            }

            if (! $form->double_opt_in || $events === []) {
                return null;
            }

            return self::issueConfirmation($form, $contact, $events);
        });

        if ($token !== null) {
            $contact = self::resolveContact($email);
            if ($contact !== null) {
                SignupConfirmationMail::send($form, $contact, $token, $locale);
            }
        }
    }

    /**
     * @return 'confirmed'|'already_confirmed'|'expired'|'invalid'
     */
    public static function confirm(string $token): string
    {
        if ($token === '') {
            return 'invalid';
        }

        $hash = SignupConfirmation::hashToken($token);

        return DB::transaction(function () use ($hash): string {
            $row = SignupConfirmation::query()
                ->where('token_hash', $hash)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return 'invalid';
            }

            if ($row->confirmed_at !== null) {
                return 'already_confirmed';
            }

            if ($row->expires_at->isPast()) {
                return 'expired';
            }

            $contact = Contact::query()->find($row->contact_id);
            if ($contact === null) {
                return 'invalid';
            }

            $ids = array_map(static fn ($id): int => (int) $id, $row->consent_event_ids);
            $pending = ConsentEvent::query()->whereIn('id', $ids)->get();

            foreach ($pending as $event) {
                $list = SubscriptionList::query()->find($event->subscription_list_id);
                $channel = $event->channel instanceof Channel
                    ? $event->channel
                    : Channel::tryFrom((string) $event->channel);
                if ($list === null || $channel === null || $event->consent_text_id === null) {
                    continue;
                }

                if (ConsentState::isGranted((int) $contact->id, $channel, (int) $list->id)) {
                    continue;
                }

                ConsentWriter::grant(
                    $contact,
                    $channel,
                    $list,
                    ConsentSource::DoubleOptIn,
                    (int) $event->consent_text_id,
                    is_array($event->evidence) ? $event->evidence : null,
                );
            }

            $row->update(['confirmed_at' => now()]);

            return 'confirmed';
        });
    }

    public static function consentText(string $key, string $locale, ?int $version = null): ?ConsentText
    {
        if ($version !== null) {
            $exact = ConsentText::query()
                ->where('key', $key)
                ->where('version', $version)
                ->where('locale', $locale)
                ->first();
            if ($exact !== null) {
                return $exact;
            }
        }

        foreach (array_values(array_unique([$locale, 'en'])) as $candidate) {
            $text = ConsentText::query()
                ->where('key', $key)
                ->where('locale', $candidate)
                ->orderByDesc('version')
                ->first();
            if ($text !== null) {
                return $text;
            }
        }

        return ConsentText::query()
            ->where('key', $key)
            ->orderByDesc('version')
            ->first();
    }

    public static function locale(?string $locale): string
    {
        $locale = strtolower(substr(trim((string) $locale), 0, 2));

        return in_array($locale, ['en', 'es', 'fr'], true) ? $locale : 'en';
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private static function assertRequiredFields(SignupForm $form, array $input): void
    {
        foreach ($form->fields as $field) {
            $key = (string) ($field['key'] ?? '');
            if ($key === '' || empty($field['required'])) {
                continue;
            }

            if (trim((string) ($input[$key] ?? '')) === '') {
                throw ValidationException::withMessages([
                    $key => __('errors.signup_forms.field_required'),
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<int>
     */
    private static function selectedListIds(SignupForm $form, array $input): array
    {
        $offered = array_map(static fn ($id): int => (int) $id, $form->subscription_list_ids);

        if (! array_key_exists('list_ids', $input)) {
            return $offered;
        }

        $selected = array_values(array_unique(array_map(
            static fn ($id): int => (int) $id,
            is_array($input['list_ids']) ? $input['list_ids'] : [],
        )));

        if ($selected === [] || array_diff($selected, $offered) !== []) {
            throw ValidationException::withMessages([
                'list_ids' => __('errors.signup_forms.lists'),
            ]);
        }

        return $selected;
    }

    private static function dropped(Request $request): bool
    {
        $website = trim((string) $request->input('website', ''));
        if ($website !== '') {
            return true;
        }

        $opened = $request->input('opened_at');
        if (! is_numeric($opened)) {
            return true;
        }

        $age = now()->getTimestamp() - (int) $opened;

        return $age < self::MIN_SECONDS || $age > self::MAX_AGE_SECONDS;
    }

    /**
     * @param  list<int>  $listIds
     * @return list<SubscriptionList>
     */
    private static function listsFor(SignupForm $form, array $listIds): array
    {
        $channel = $form->marketingChannel();
        $lists = SubscriptionList::query()
            ->whereIn('id', $listIds)
            ->whereNull('archived_at')
            ->get()
            ->filter(static fn (SubscriptionList $list): bool => $list->allows($channel))
            ->values()
            ->all();

        if ($lists === []) {
            throw ValidationException::withMessages([
                'list_ids' => __('errors.signup_forms.list_unavailable'),
            ]);
        }

        return $lists;
    }

    private static function phone(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        return ContactChannelMatcher::normalizePhone($raw);
    }

    /**
     * @param  array{first_name: string, last_name: string, phone: string}  $names
     */
    private static function resolveOrCreate(SignupForm $form, string $email, array $names, string $locale): Contact
    {
        $existing = self::resolveContact($email);
        if ($existing !== null) {
            return $existing;
        }

        try {
            return self::createContact($form, $email, $names, $locale);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = self::resolveContact($email);
            if ($existing === null) {
                throw $exception;
            }

            return $existing;
        }
    }

    private static function resolveContact(string $email): ?Contact
    {
        $byColumn = Contact::query()
            ->whereNotNull('email')
            ->whereRaw('lower(trim(email)) = ?', [$email])
            ->orderByDesc('id')
            ->first();
        if ($byColumn !== null) {
            return $byColumn;
        }

        $match = ContactChannelMatcher::match(Channel::Email, $email);

        return $match['contact'];
    }

    /**
     * @param  array{first_name: string, last_name: string, phone: string}  $names
     */
    private static function createContact(SignupForm $form, string $email, array $names, string $locale): Contact
    {
        $contact = Contact::query()->create([
            'first_name' => $names['first_name'],
            'last_name' => $names['last_name'],
            'email' => $email,
            'status' => ContactLifecycleStatus::Prospect,
            'source' => ContactSource::SignupForm,
            'locale' => $locale,
        ]);

        ContactChannel::query()->create([
            'contact_id' => $contact->id,
            'type' => ContactChannelType::Email,
            'value' => $email,
            'is_primary' => true,
            'opted_in' => false,
        ]);

        if ($names['phone'] !== '') {
            ContactChannel::query()->create([
                'contact_id' => $contact->id,
                'type' => ContactChannelType::Phone,
                'value' => $names['phone'],
                'is_primary' => false,
                'opted_in' => false,
            ]);
        }

        if ($form->site_id !== null) {
            $contact->sites()->syncWithoutDetaching([$form->site_id]);
        }

        return $contact;
    }

    /**
     * @param  list<ConsentEvent>  $events
     */
    private static function issueConfirmation(SignupForm $form, Contact $contact, array $events): string
    {
        $token = bin2hex(random_bytes(24));

        SignupConfirmation::query()->create([
            'signup_form_id' => $form->id,
            'contact_id' => $contact->id,
            'token_hash' => SignupConfirmation::hashToken($token),
            'consent_event_ids' => array_map(static fn (ConsentEvent $event): int => (int) $event->id, $events),
            'expires_at' => now()->addDays(self::CONFIRM_DAYS),
        ]);

        return $token;
    }
}
