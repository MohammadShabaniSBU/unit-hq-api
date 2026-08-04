<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\WhatsappTemplateResource;
use App\Models\WhatsappTemplate;
use App\Support\Communications\Channel;
use App\Support\Communications\Contracts\ManagesWhatsAppTemplates;
use App\Support\Communications\Exceptions\ChannelNotConfigured;
use App\Support\Communications\Exceptions\ProviderRequestFailed;
use App\Support\Communications\Exceptions\UnsupportedCapability;
use App\Support\Communications\Messages\WhatsAppTemplateDraft;
use App\Support\Communications\ProviderResolver;
use App\Support\Communications\WhatsAppTemplateSync;
use App\Support\Communications\WhatsAppTemplateValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use App\Support\Auth\Permission;
use Illuminate\Support\Facades\Gate;

class WhatsappTemplateController extends Controller
{
    public function __construct(
        private readonly ProviderResolver $resolver,
        private readonly WhatsAppTemplateSync $sync,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize(Permission::TemplateManage->value);

        $validated = $request->validate([
            'status' => ['sometimes', 'nullable', 'string'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $query = WhatsappTemplate::query()->latest();

        $status = $validated['status'] ?? null;
        if ($status === null || $status === '' || $status === 'active') {
            $query->where('status', '!=', WhatsappTemplate::STATUS_ARCHIVED);
        } elseif ($status !== 'all') {
            $query->where('status', $status);
        }

        if (! empty($validated['search'])) {
            $search = trim((string) $validated['search']);
            $query->where('name', 'like', "%{$search}%");
        }

        return $this->paginated(
            $query->paginate($this->perPage())->through(
                fn (WhatsappTemplate $row) => WhatsappTemplateResource::make($row)
            ),
            'WhatsApp templates retrieved successfully.'
        );
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize(Permission::TemplateManage->value);

        $validated = WhatsAppTemplateValidator::validate($request->all(), requireSamples: false);
        $account = $this->activeAccount();

        $exists = WhatsappTemplate::query()
            ->where('communication_account_id', $account->id)
            ->where('name', $validated['name'])
            ->where('language', $validated['language'])
            ->where('status', '!=', WhatsappTemplate::STATUS_ARCHIVED)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => ['A non-archived template with this name and language already exists.'],
            ]);
        }

        $template = WhatsappTemplate::query()->create([
            ...$validated,
            'status' => WhatsappTemplate::STATUS_DRAFT,
            'communication_account_id' => $account->id,
            'created_by' => $request->user()?->id,
        ]);

        return $this->created(
            WhatsappTemplateResource::make($template),
            'WhatsApp template created successfully.'
        );
    }

    public function show(WhatsappTemplate $whatsappTemplate): JsonResponse
    {
        Gate::authorize(Permission::TemplateManage->value, $whatsappTemplate);

        return $this->success(
            WhatsappTemplateResource::make($whatsappTemplate),
            'WhatsApp template retrieved successfully.'
        );
    }

    public function update(Request $request, WhatsappTemplate $whatsappTemplate): JsonResponse
    {
        Gate::authorize(Permission::TemplateManage->value, $whatsappTemplate);

        if (! $whatsappTemplate->isEditable()) {
            return $this->error(
                'Only draft or rejected templates can be edited. Clone an approved template to revise it.',
                statusCode: 422
            );
        }

        $validated = WhatsAppTemplateValidator::validate($request->all(), requireSamples: false);

        $exists = WhatsappTemplate::query()
            ->where('communication_account_id', $whatsappTemplate->communication_account_id)
            ->where('name', $validated['name'])
            ->where('language', $validated['language'])
            ->where('status', '!=', WhatsappTemplate::STATUS_ARCHIVED)
            ->where('id', '!=', $whatsappTemplate->id)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => ['A non-archived template with this name and language already exists.'],
            ]);
        }

        try {
            $whatsappTemplate->fill([
                ...$validated,
                // Rejected templates return to draft on edit so they can be re-submitted.
                'status' => WhatsappTemplate::STATUS_DRAFT,
                'rejection_reason' => null,
                'provider_template_id' => null,
                'submitted_at' => null,
                'decided_at' => null,
            ])->save();
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), statusCode: 422);
        }

        return $this->success(
            WhatsappTemplateResource::make($whatsappTemplate->fresh()),
            'WhatsApp template updated successfully.'
        );
    }

    public function submit(WhatsappTemplate $whatsappTemplate): JsonResponse
    {
        Gate::authorize(Permission::TemplateManage->value, $whatsappTemplate);

        if (! in_array($whatsappTemplate->status, [
            WhatsappTemplate::STATUS_DRAFT,
            WhatsappTemplate::STATUS_REJECTED,
        ], true)) {
            return $this->error('Only draft or rejected templates can be submitted.', statusCode: 422);
        }

        $validated = WhatsAppTemplateValidator::validate([
            'name' => $whatsappTemplate->name,
            'language' => $whatsappTemplate->language,
            'category' => $whatsappTemplate->category,
            'header_text' => $whatsappTemplate->header_text,
            'body' => $whatsappTemplate->body,
            'footer_text' => $whatsappTemplate->footer_text,
            'buttons' => $whatsappTemplate->buttons,
            'variables' => $whatsappTemplate->variables,
        ], requireSamples: true);

        try {
            $resolved = $this->resolver->resolve(Channel::Whatsapp);
            $adapter = $resolved->require(ManagesWhatsAppTemplates::class, 'managing WhatsApp templates');
        } catch (ChannelNotConfigured|UnsupportedCapability $e) {
            return $this->error($e->getMessage(), statusCode: 422);
        }

        $draft = new WhatsAppTemplateDraft(
            name: $validated['name'],
            language: $validated['language'],
            category: $validated['category'],
            body: $validated['body'],
            headerText: $validated['header_text'],
            footerText: $validated['footer_text'],
            buttons: $validated['buttons'],
            variables: $validated['variables'],
        );

        try {
            $ref = $adapter->submit($draft);
        } catch (ProviderRequestFailed $e) {
            return $this->error($e->getMessage(), statusCode: 422);
        }

        $whatsappTemplate->forceFill([
            'variables' => $validated['variables'],
            'status' => WhatsappTemplate::STATUS_SUBMITTED,
            'provider_template_id' => $ref->providerTemplateId,
            'submitted_at' => now(),
            'decided_at' => null,
            'rejection_reason' => null,
        ])->save();

        return $this->success(
            WhatsappTemplateResource::make($whatsappTemplate->fresh()),
            'WhatsApp template submitted for approval.'
        );
    }

    public function clone(WhatsappTemplate $whatsappTemplate): JsonResponse
    {
        Gate::authorize(Permission::TemplateManage->value, $whatsappTemplate);

        if ($whatsappTemplate->status === WhatsappTemplate::STATUS_ARCHIVED) {
            return $this->error('Archived templates cannot be cloned.', statusCode: 422);
        }

        $newName = $this->nextCloneName(
            $whatsappTemplate->communication_account_id,
            $whatsappTemplate->name,
            $whatsappTemplate->language,
        );

        $clone = WhatsappTemplate::query()->create([
            'name' => $newName,
            'language' => $whatsappTemplate->language,
            'category' => $whatsappTemplate->category,
            'header_text' => $whatsappTemplate->header_text,
            'body' => $whatsappTemplate->body,
            'footer_text' => $whatsappTemplate->footer_text,
            'buttons' => $whatsappTemplate->buttons,
            'variables' => $whatsappTemplate->variables,
            'status' => WhatsappTemplate::STATUS_DRAFT,
            'communication_account_id' => $whatsappTemplate->communication_account_id,
            'created_by' => request()->user()?->id,
        ]);

        return $this->created(
            WhatsappTemplateResource::make($clone),
            'WhatsApp template cloned to a new draft.'
        );
    }

    public function archive(WhatsappTemplate $whatsappTemplate): JsonResponse
    {
        Gate::authorize(Permission::TemplateManage->value, $whatsappTemplate);

        if ($whatsappTemplate->status === WhatsappTemplate::STATUS_ARCHIVED) {
            return $this->success(
                WhatsappTemplateResource::make($whatsappTemplate),
                'WhatsApp template already archived.'
            );
        }

        $whatsappTemplate->forceFill([
            'status' => WhatsappTemplate::STATUS_ARCHIVED,
        ])->save();

        return $this->success(
            WhatsappTemplateResource::make($whatsappTemplate->fresh()),
            'WhatsApp template archived successfully.'
        );
    }

    public function sync(): JsonResponse
    {
        Gate::authorize(Permission::TemplateManage->value);

        $updated = $this->sync->pollAll();

        return $this->success(
            ['updated' => $updated],
            'WhatsApp template sync completed.'
        );
    }

    private function activeAccount(): \App\Models\CommunicationAccount
    {
        try {
            return $this->resolver->resolve(Channel::Whatsapp)->account;
        } catch (ChannelNotConfigured $e) {
            throw ValidationException::withMessages([
                'communication_account_id' => [$e->getMessage()],
            ]);
        }
    }

    private function nextCloneName(int $accountId, string $baseName, string $language): string
    {
        $root = preg_replace('/_v\d+$/', '', $baseName) ?: $baseName;
        $version = 2;

        do {
            $candidate = $root.'_v'.$version;
            $taken = WhatsappTemplate::query()
                ->where('communication_account_id', $accountId)
                ->where('name', $candidate)
                ->where('language', $language)
                ->where('status', '!=', WhatsappTemplate::STATUS_ARCHIVED)
                ->exists();
            $version++;
        } while ($taken && $version < 100);

        return $candidate;
    }
}
