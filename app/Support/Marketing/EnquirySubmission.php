<?php

declare(strict_types=1);

namespace App\Support\Marketing;

use App\Enums\ContactChannelType;
use App\Enums\ContactLifecycleStatus;
use App\Enums\ContactSource;
use App\Enums\DealStatus;
use App\Enums\LogChannel;
use App\Models\Contact;
use App\Models\ContactChannel;
use App\Models\Deal;
use App\Models\SignupForm;
use App\Support\Advertising\TouchRecorder;
use App\Support\Communications\Channel;
use App\Support\Communications\ContactChannelMatcher;
use App\Support\RecordsActivity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Public enquiry. Creates a prospect when nothing matches, and at most one
 * open deal per contact and site. Sibling of SignupSubmission.
 */
final class EnquirySubmission
{
    public static function submit(SignupForm $form, Request $request): void
    {
        if ($form->site_id === null) {
            throw ValidationException::withMessages([
                'form' => __('errors.signup_forms.enquiry_site_required'),
            ]);
        }

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
            't' => ['nullable', 'string', 'max:2048'],
            'desired_size' => ['nullable'],
            'unit_class_id' => ['nullable'],
            'expected_move_in' => ['nullable', 'string', 'max:32'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);

        $email = ContactChannelMatcher::normalizeEmail((string) $input['email']);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages([
                'email' => __('validation.email', ['attribute' => 'email']),
            ]);
        }

        SignupSubmission::assertRequiredFields($form, $input);
        $answers = self::answers($form, $input);
        $listIds = self::selectedListIds($form, $input);

        if (SignupSubmission::dropped($request)) {
            return;
        }

        $locale = SignupSubmission::locale($input['locale'] ?? null);
        $text = SignupSubmission::consentText(
            $form->consent_text_key,
            $locale,
            isset($input['consent_text_version']) ? (int) $input['consent_text_version'] : null,
        );
        if ($text === null) {
            throw ValidationException::withMessages([
                'form' => __('errors.signup_forms.consent_text'),
            ]);
        }

        $lists = $listIds === [] ? [] : SignupSubmission::listsFor($form, $listIds);
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
        $touch = is_string($input['t'] ?? null) ? $input['t'] : null;

        $token = DB::transaction(function () use ($form, $email, $names, $lists, $text, $evidence, $locale, $touch, $answers): ?string {
            $contact = self::resolveOrCreate($form, $email, $names, $locale);
            self::openOrCreateDeal($form, $contact, $answers);
            $snapshot = TouchRecorder::fromEncoded($form, $contact, $touch);
            AdvertisingConsent::recordFromSubmission($contact, $form, $snapshot, $locale, $evidence);

            return SignupSubmission::applyListConsent($form, $contact, $lists, $text, $evidence);
        });

        if ($token !== null) {
            $contact = SignupSubmission::findByEmail($email);
            if ($contact !== null) {
                SignupConfirmationMail::send($form, $contact, $token, $locale);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{expected_move_in: ?string, desired_size: ?string, desired_unit_class_id: ?int, message: ?string}
     */
    private static function answers(SignupForm $form, array $input): array
    {
        $stored = is_array($form->enquiry_fields) ? $form->enquiry_fields : null;
        $answers = [
            'expected_move_in' => null,
            'desired_size' => null,
            'desired_unit_class_id' => null,
            'message' => null,
        ];

        if (EnquiryFields::enabled($stored, 'desired_size')) {
            $raw = trim((string) ($input['desired_size'] ?? ''));
            if (EnquiryFields::required($stored, 'desired_size') && $raw === '') {
                throw ValidationException::withMessages([
                    'desired_size' => __('errors.signup_forms.field_required'),
                ]);
            }
            if ($raw !== '' && (! is_numeric($raw) || (float) $raw < 0)) {
                throw ValidationException::withMessages([
                    'desired_size' => __('validation.numeric', ['attribute' => 'desired_size']),
                ]);
            }
            $answers['desired_size'] = $raw === '' ? null : $raw;
        }

        if (EnquiryFields::enabled($stored, 'unit_class_id')) {
            $raw = $input['unit_class_id'] ?? null;
            if ($raw === '' || $raw === null) {
                $id = null;
            } elseif (! is_numeric($raw)) {
                throw ValidationException::withMessages([
                    'unit_class_id' => __('errors.signup_forms.field_required'),
                ]);
            } else {
                $id = (int) $raw;
            }
            if (EnquiryFields::required($stored, 'unit_class_id') && $id === null) {
                throw ValidationException::withMessages([
                    'unit_class_id' => __('errors.signup_forms.field_required'),
                ]);
            }
            if ($id !== null && ! in_array($id, EnquiryFields::unitClassOptions($stored), true)) {
                throw ValidationException::withMessages([
                    'unit_class_id' => __('errors.signup_forms.field_required'),
                ]);
            }
            $answers['desired_unit_class_id'] = $id;
        }

        if (EnquiryFields::enabled($stored, 'expected_move_in')) {
            $raw = trim((string) ($input['expected_move_in'] ?? ''));
            if (EnquiryFields::required($stored, 'expected_move_in') && $raw === '') {
                throw ValidationException::withMessages([
                    'expected_move_in' => __('errors.signup_forms.field_required'),
                ]);
            }
            if ($raw !== '' && strtotime($raw) === false) {
                throw ValidationException::withMessages([
                    'expected_move_in' => __('validation.date', ['attribute' => 'expected_move_in']),
                ]);
            }
            $answers['expected_move_in'] = $raw === '' ? null : $raw;
        }

        if (EnquiryFields::enabled($stored, 'message')) {
            $raw = trim((string) ($input['message'] ?? ''));
            if (EnquiryFields::required($stored, 'message') && $raw === '') {
                throw ValidationException::withMessages([
                    'message' => __('errors.signup_forms.field_required'),
                ]);
            }
            $answers['message'] = $raw === '' ? null : $raw;
        }

        return $answers;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<int>
     */
    private static function selectedListIds(SignupForm $form, array $input): array
    {
        $offered = array_map(static fn ($id): int => (int) $id, $form->subscription_list_ids ?? []);

        if (! array_key_exists('list_ids', $input)) {
            return [];
        }

        $selected = array_values(array_unique(array_map(
            static fn ($id): int => (int) $id,
            is_array($input['list_ids']) ? $input['list_ids'] : [],
        )));

        if ($selected === []) {
            return [];
        }

        if (array_diff($selected, $offered) !== []) {
            throw ValidationException::withMessages([
                'list_ids' => __('errors.signup_forms.lists'),
            ]);
        }

        return $selected;
    }

    /**
     * @param  array{first_name: string, last_name: string, phone: string}  $names
     */
    private static function resolveOrCreate(SignupForm $form, string $email, array $names, string $locale): Contact
    {
        $existing = SignupSubmission::findByEmail($email);
        if ($existing === null && $names['phone'] !== '') {
            $match = ContactChannelMatcher::match(Channel::Sms, $names['phone']);
            $existing = $match['contact'];
        }

        if ($existing !== null) {
            return $existing;
        }

        try {
            return self::createContact($form, $email, $names, $locale);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = SignupSubmission::findByEmail($email);
            if ($existing === null) {
                throw $exception;
            }

            return $existing;
        }
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
            'source' => ContactSource::EnquiryForm,
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
     * @param  array{expected_move_in: ?string, desired_size: ?string, desired_unit_class_id: ?int, message: ?string}  $answers
     */
    private static function openOrCreateDeal(SignupForm $form, Contact $contact, array $answers): void
    {
        $open = Deal::query()
            ->where('contact_id', $contact->id)
            ->where('site_id', $form->site_id)
            ->whereNotIn('status', DealStatus::terminalValues())
            ->orderByDesc('id')
            ->first();

        if ($open !== null) {
            self::fillEmptyExpectations($open, $answers);
            $open->notes()->create([
                'content' => 'deal.web_enquiry',
                'employee_id' => null,
            ]);
            RecordsActivity::log(LogChannel::Crm, 'deal.web_enquiry', $open, [
                'signup_form_id' => $form->id,
            ], anonymous: true);
            self::messageNote($open, $answers['message']);

            return;
        }

        $deal = Deal::query()->create([
            'contact_id' => $contact->id,
            'site_id' => $form->site_id,
            'status' => DealStatus::New,
            'expected_move_in' => $answers['expected_move_in'],
            'desired_size' => $answers['desired_size'],
            'desired_unit_class_id' => $answers['desired_unit_class_id'],
        ]);

        RecordsActivity::log(LogChannel::Crm, 'deal.created', $deal, [
            'status' => $deal->status instanceof DealStatus ? $deal->status->value : (string) $deal->status,
            'signup_form_id' => $form->id,
        ], anonymous: true);

        RecordsActivity::log(LogChannel::Crm, 'deal.created', $contact, [
            'deal_id' => $deal->id,
            'status' => $deal->status instanceof DealStatus ? $deal->status->value : (string) $deal->status,
            'signup_form_id' => $form->id,
        ], anonymous: true);

        self::messageNote($deal, $answers['message']);
    }

    /**
     * @param  array{expected_move_in: ?string, desired_size: ?string, desired_unit_class_id: ?int, message: ?string}  $answers
     */
    private static function fillEmptyExpectations(Deal $deal, array $answers): void
    {
        $updates = [];

        if ($deal->expected_move_in === null && $answers['expected_move_in'] !== null) {
            $updates['expected_move_in'] = $answers['expected_move_in'];
        }

        if ($deal->desired_size === null && $answers['desired_size'] !== null) {
            $updates['desired_size'] = $answers['desired_size'];
        }

        if ($deal->desired_unit_class_id === null && $answers['desired_unit_class_id'] !== null) {
            $updates['desired_unit_class_id'] = $answers['desired_unit_class_id'];
        }

        if ($updates !== []) {
            $deal->update($updates);
        }
    }

    private static function messageNote(Deal $deal, ?string $message): void
    {
        if ($message === null || $message === '') {
            return;
        }

        $deal->notes()->create([
            'content' => $message,
            'employee_id' => null,
        ]);
    }

    private static function phone(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        return ContactChannelMatcher::normalizePhone($raw);
    }
}
