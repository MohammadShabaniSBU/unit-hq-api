<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TemplateChannel;
use App\Enums\TemplatePurpose;
use App\Models\SignupForm;
use App\Models\SubscriptionList;
use App\Support\Auth\Permission;
use App\Support\Communications\Channel;
use App\Support\Marketing\EnquiryFields;
use App\Support\Marketing\SignupFormPurpose;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SignupFormController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize(Permission::MarketingView->value);

        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['active', 'archived', 'all'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = SignupForm::query()->orderByDesc('created_at')->orderByDesc('id');

        match ($validated['status'] ?? 'active') {
            'archived' => $query->whereNotNull('archived_at'),
            'all' => null,
            default => $query->active(),
        };

        $paged = array_key_exists('page', $validated) || array_key_exists('per_page', $validated);

        if ($paged) {
            $perPage = (int) ($validated['per_page'] ?? 25);
            $paginator = $query->paginate($perPage)->through(
                fn (SignupForm $form): array => $this->payload($form),
            );

            return $this->paginated($paginator, 'Signup forms retrieved successfully.');
        }

        $forms = $query->get()->map(fn (SignupForm $form): array => $this->payload($form))->all();

        return $this->success($forms, 'Signup forms retrieved successfully.');
    }

    public function show(SignupForm $signupForm): JsonResponse
    {
        Gate::authorize(Permission::MarketingView->value);

        return $this->success($this->payload($signupForm), 'Signup form retrieved successfully.');
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize(Permission::MarketingManage->value);

        $validated = $this->validatePayload($request, creating: true);
        $purpose = $this->purposeFrom($validated, SignupFormPurpose::Subscribe);
        $this->assertEnquirySite($purpose, $validated['site_id'] ?? null);
        $listIds = $validated['subscription_list_ids'] ?? [];
        if ($listIds !== []) {
            $this->assertLists($listIds);
        } elseif ($purpose !== SignupFormPurpose::Enquiry) {
            $this->assertLists($listIds);
        }
        $this->assertDoubleOptInSite($validated['double_opt_in'] ?? true, $validated['site_id'] ?? null);

        $fields = $validated['fields'] ?? [];
        if ($purpose === SignupFormPurpose::Enquiry) {
            $fields = $this->withDefaultPhone($fields);
        }

        $form = SignupForm::query()->create([
            ...$validated,
            'purpose' => $purpose,
            'enquiry_fields' => $purpose === SignupFormPurpose::Enquiry
                ? EnquiryFields::sanitize($validated['enquiry_fields'] ?? null)
                : null,
            'fields' => $fields,
            'subscription_list_ids' => $listIds,
            'public_token' => SignupForm::mintPublicToken(),
            'channel' => Channel::Email->value,
            'double_opt_in' => $validated['double_opt_in'] ?? true,
        ]);

        return $this->created($this->payload($form), 'Signup form created successfully.');
    }

    public function update(Request $request, SignupForm $signupForm): JsonResponse
    {
        Gate::authorize(Permission::MarketingManage->value);

        $validated = $this->validatePayload($request, creating: false, existing: $signupForm);
        if (array_key_exists('purpose', $validated)) {
            $incoming = $validated['purpose'] instanceof SignupFormPurpose
                ? $validated['purpose']
                : SignupFormPurpose::from((string) $validated['purpose']);
            if ($incoming !== $signupForm->purpose && $signupForm->hasSubmissions()) {
                return $this->error(__('errors.signup_forms.purpose_locked'), [
                    'purpose' => [__('errors.signup_forms.purpose_locked')],
                ], 409);
            }
        }

        $purpose = $this->purposeFrom($validated, $signupForm->purpose);
        $siteId = array_key_exists('site_id', $validated) ? $validated['site_id'] : $signupForm->site_id;
        $this->assertEnquirySite($purpose, $siteId);

        if (array_key_exists('subscription_list_ids', $validated)) {
            if ($validated['subscription_list_ids'] !== [] || $purpose !== SignupFormPurpose::Enquiry) {
                $this->assertLists($validated['subscription_list_ids']);
            }
        }

        $double = array_key_exists('double_opt_in', $validated)
            ? (bool) $validated['double_opt_in']
            : $signupForm->double_opt_in;
        $this->assertDoubleOptInSite($double, $siteId);

        $attributes = [];
        foreach ([
            'name',
            'site_id',
            'subscription_list_ids',
            'fields',
            'consent_text_key',
            'double_opt_in',
            'confirmation_template_family_id',
            'success_redirect_url',
            'allowed_origins',
        ] as $field) {
            if (array_key_exists($field, $validated)) {
                $attributes[$field] = $validated[$field];
            }
        }

        if (array_key_exists('purpose', $validated) || $purpose !== $signupForm->purpose) {
            $attributes['purpose'] = $purpose;
        }

        if ($purpose === SignupFormPurpose::Enquiry) {
            if (array_key_exists('enquiry_fields', $validated) || $signupForm->purpose !== SignupFormPurpose::Enquiry) {
                $raw = array_key_exists('enquiry_fields', $validated)
                    ? $validated['enquiry_fields']
                    : $signupForm->enquiry_fields;
                $attributes['enquiry_fields'] = EnquiryFields::sanitize(is_array($raw) ? $raw : null);
            }
            $fields = array_key_exists('fields', $validated) ? $validated['fields'] : $signupForm->fields;
            $attributes['fields'] = $this->withDefaultPhone(is_array($fields) ? $fields : []);
        } elseif (array_key_exists('purpose', $validated)) {
            $attributes['enquiry_fields'] = null;
        }

        if ($attributes !== []) {
            $signupForm->update($attributes);
        }

        return $this->success(
            $this->payload($signupForm->fresh() ?? $signupForm),
            'Signup form updated successfully.',
        );
    }

    public function archive(SignupForm $signupForm): JsonResponse
    {
        Gate::authorize(Permission::MarketingManage->value);

        if (! $signupForm->isArchived()) {
            $signupForm->update(['archived_at' => now()]);
        }

        return $this->success(
            $this->payload($signupForm->fresh() ?? $signupForm),
            'Signup form archived successfully.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, bool $creating, ?SignupForm $existing = null): array
    {
        $presence = $creating ? 'required' : 'sometimes';
        $purpose = $this->requestedPurpose($request, $existing);
        $listRules = $purpose === SignupFormPurpose::Enquiry
            ? ['sometimes', 'array']
            : [$presence, 'array', 'min:1'];

        $rules = [
            'name' => [$presence, 'string', 'max:128'],
            'purpose' => ['sometimes', Rule::enum(SignupFormPurpose::class)],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'subscription_list_ids' => $listRules,
            'subscription_list_ids.*' => ['integer', 'distinct', 'exists:subscription_lists,id'],
            'fields' => [$creating ? 'present' : 'sometimes', 'array'],
            'fields.*.key' => ['required', 'string', Rule::in(['first_name', 'last_name', 'phone'])],
            'fields.*.required' => ['required', 'boolean'],
            'consent_text_key' => [$presence, 'string', 'max:64', 'exists:consent_texts,key'],
            'double_opt_in' => ['sometimes', 'boolean'],
            'confirmation_template_family_id' => [
                'nullable',
                'integer',
                Rule::exists('template_families', 'id')->where(
                    fn ($query) => $query
                        ->where('channel', TemplateChannel::Email->value)
                        ->where('purpose', TemplatePurpose::System->value)
                        ->whereNull('archived_at'),
                ),
            ],
            'success_redirect_url' => ['nullable', 'string', 'max:500', 'url'],
            'allowed_origins' => ['nullable', 'array'],
            'allowed_origins.*' => ['string', 'max:255'],
        ];

        if ($purpose === SignupFormPurpose::Enquiry) {
            $rules['enquiry_fields'] = ['sometimes', 'array'];
            foreach (EnquiryFields::KEYS as $key) {
                $rules['enquiry_fields.'.$key] = ['sometimes', 'array'];
                $rules['enquiry_fields.'.$key.'.enabled'] = ['sometimes', 'boolean'];
                $rules['enquiry_fields.'.$key.'.required'] = ['sometimes', 'boolean'];
            }
            $rules['enquiry_fields.unit_class_id.options'] = ['sometimes', 'array'];
            $rules['enquiry_fields.unit_class_id.options.*'] = ['integer', 'distinct', 'exists:unit_classes,id'];
        }

        $validated = $request->validate($rules);

        if (array_key_exists('fields', $validated)) {
            $keys = array_map(static fn (array $field): string => $field['key'], $validated['fields']);
            if (count($keys) !== count(array_unique($keys))) {
                throw ValidationException::withMessages([
                    'fields' => __('errors.signup_forms.fields'),
                ]);
            }
        }

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function purposeFrom(array $validated, SignupFormPurpose $fallback): SignupFormPurpose
    {
        if (! array_key_exists('purpose', $validated)) {
            return $fallback;
        }

        $raw = $validated['purpose'];

        return $raw instanceof SignupFormPurpose
            ? $raw
            : SignupFormPurpose::from((string) $raw);
    }

    private function requestedPurpose(Request $request, ?SignupForm $existing): SignupFormPurpose
    {
        $raw = $request->input('purpose');
        if (is_string($raw)) {
            return SignupFormPurpose::tryFrom($raw) ?? ($existing?->purpose ?? SignupFormPurpose::Subscribe);
        }

        return $existing?->purpose ?? SignupFormPurpose::Subscribe;
    }

    private function assertEnquirySite(SignupFormPurpose $purpose, mixed $siteId): void
    {
        if ($purpose === SignupFormPurpose::Enquiry && ($siteId === null || $siteId === '')) {
            throw ValidationException::withMessages([
                'site_id' => __('errors.signup_forms.enquiry_site_required'),
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @return list<array<string, mixed>>
     */
    private function withDefaultPhone(array $fields): array
    {
        foreach ($fields as $field) {
            if (($field['key'] ?? '') === 'phone') {
                return $fields;
            }
        }

        $fields[] = ['key' => 'phone', 'required' => true];

        return $fields;
    }

    /**
     * @param  list<int>  $listIds
     */
    private function assertLists(array $listIds): void
    {
        $lists = SubscriptionList::query()->whereIn('id', $listIds)->get();
        foreach ($lists as $list) {
            if ($list->archived_at !== null || ! $list->allows(Channel::Email)) {
                throw ValidationException::withMessages([
                    'subscription_list_ids' => __('errors.signup_forms.list_unavailable'),
                ]);
            }
        }
    }

    private function assertDoubleOptInSite(bool $doubleOptIn, mixed $siteId): void
    {
        if ($doubleOptIn && ($siteId === null || $siteId === '')) {
            throw ValidationException::withMessages([
                'site_id' => __('errors.signup_forms.site_required'),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(SignupForm $form): array
    {
        return [
            'id' => $form->id,
            'public_token' => $form->public_token,
            'name' => $form->name,
            'purpose' => $form->purpose instanceof SignupFormPurpose
                ? $form->purpose->value
                : SignupFormPurpose::Subscribe->value,
            'enquiry_fields' => $form->purpose === SignupFormPurpose::Enquiry
                ? EnquiryFields::sanitize(is_array($form->enquiry_fields) ? $form->enquiry_fields : null)
                : null,
            'site_id' => $form->site_id,
            'subscription_list_ids' => array_map(static fn ($id): int => (int) $id, $form->subscription_list_ids),
            'channel' => $form->channel,
            'fields' => $form->fields,
            'consent_text_key' => $form->consent_text_key,
            'double_opt_in' => $form->double_opt_in,
            'confirmation_template_family_id' => $form->confirmation_template_family_id,
            'success_redirect_url' => $form->success_redirect_url,
            'allowed_origins' => $form->allowed_origins,
            'archived_at' => $form->archived_at?->toIso8601String(),
            'submissions_count' => $form->submissionsCount(),
            'purpose_locked' => $form->hasSubmissions(),
            'enquiry_count' => $form->enquiryCount(),
            'created_at' => $form->created_at->toIso8601String(),
            'updated_at' => $form->updated_at->toIso8601String(),
        ];
    }
}
