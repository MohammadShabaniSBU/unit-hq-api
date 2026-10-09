<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\LogChannel;
use App\Enums\TemplateChannel;
use App\Enums\TemplatePurpose;
use App\Enums\TemplateVersionStatus;
use App\Http\Resources\TemplateFamilyResource;
use App\Http\Resources\TemplateVersionResource;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Site;
use App\Models\TemplateFamily;
use App\Models\TemplateVariant;
use App\Models\TemplateVersion;
use App\Support\Auth\Permission;
use App\Support\Communications\EmailBlockDocument;
use App\Support\Communications\EmailTemplateRenderer;
use App\Support\Communications\Messages\EmailAddress;
use App\Support\Communications\Messages\EmailMessage;
use App\Support\Communications\SendClass;
use App\Support\Communications\SendContext;
use App\Support\Communications\Senders\EmailSender;
use App\Support\Communications\SiteLocale;
use App\Support\Communications\TemplateBuilderContext;
use App\Support\Communications\TemplateProvenance;
use App\Support\Communications\TemplatePublishValidator;
use App\Support\Communications\TemplateResolver;
use App\Support\Documents\ContractDocumentRenderer;
use App\Support\Documents\DocumentBlockDocument;
use App\Support\RecordsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TemplateFamilyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize(Permission::TemplateManage->value);

        $validated = $request->validate([
            'channel' => ['sometimes', 'nullable', Rule::enum(TemplateChannel::class)],
            'purpose' => ['sometimes', 'nullable', Rule::enum(TemplatePurpose::class)],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'nullable', Rule::in(['active', 'archived', 'all'])],
            'sendable' => ['sometimes', 'boolean'],
        ]);

        $query = TemplateFamily::query()->with($this->versionRelations())->latest();

        $status = $validated['status'] ?? 'active';
        match ($status) {
            'archived' => $query->whereNotNull('archived_at'),
            'all' => null,
            default => $query->notArchived(),
        };

        if (! empty($validated['channel'])) {
            $query->channel($validated['channel']);
        }

        if (! empty($validated['purpose'])) {
            $query->purposeIn($validated['purpose']);
        }

        if (! empty($validated['search'])) {
            $search = trim((string) $validated['search']);
            $query->where('name', 'like', "%{$search}%");
        }

        if ($request->boolean('sendable')) {
            $query->whereHas('versions', function ($versions): void {
                $versions->where('status', TemplateVersionStatus::Published);
            });
        }

        return $this->paginated(
            $query->paginate($this->perPage())->through(
                fn (TemplateFamily $family) => TemplateFamilyResource::make($family)
            ),
            'Template families retrieved successfully.'
        );
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize(Permission::TemplateManage->value);

        $validated = $request->validate([
            'channel' => ['required', Rule::enum(TemplateChannel::class)],
            'name' => ['required', 'string', 'max:128'],
            'purpose' => ['sometimes', Rule::enum(TemplatePurpose::class)],
            'locale' => ['sometimes', 'string', Rule::in(SiteLocale::ALLOWED)],
            'subject' => ['sometimes', 'nullable', 'string', 'max:500'],
            'body_text' => ['sometimes', 'nullable', 'string'],
            'legacy_html' => ['sometimes', 'nullable', 'string'],
            'blocks' => ['sometimes', 'nullable', 'array'],
        ]);

        $channel = TemplateChannel::from($validated['channel'] instanceof TemplateChannel
            ? $validated['channel']->value
            : (string) $validated['channel']);
        $purpose = isset($validated['purpose'])
            ? ($validated['purpose'] instanceof TemplatePurpose
                ? $validated['purpose']
                : TemplatePurpose::from((string) $validated['purpose']))
            : TemplatePurpose::General;

        $blocksDoc = null;
        if (array_key_exists('blocks', $validated) && $validated['blocks'] !== null) {
            $blocksDoc = $this->validateBlocksForChannel($channel, $validated['blocks'], $purpose);
        }

        $family = DB::transaction(function () use ($validated, $request, $blocksDoc, $purpose): TemplateFamily {
            $family = TemplateFamily::query()->create([
                'channel' => $validated['channel'],
                'name' => $validated['name'],
                'purpose' => $purpose,
            ]);

            $version = $family->versions()->create([
                'version_number' => 1,
                'status' => TemplateVersionStatus::Draft,
                'published_at' => null,
                'created_by' => $request->user()?->id,
            ]);

            $version->variants()->create([
                'template_family_id' => $family->id,
                'locale' => $validated['locale'] ?? 'en',
                'subject' => $validated['subject'] ?? $validated['name'],
                'blocks' => $blocksDoc,
                'legacy_html' => $blocksDoc !== null ? null : ($validated['legacy_html'] ?? null),
                'body_text' => $validated['body_text'] ?? null,
                'updated_by' => $request->user()?->id,
            ]);

            $this->logVersion($family, 'template.version.drafted', $version, $request->user());

            return $family;
        });

        return $this->created(
            TemplateFamilyResource::make($family->load($this->versionRelations())),
            'Template family created successfully.'
        );
    }

    public function show(TemplateFamily $templateFamily): JsonResponse
    {
        Gate::authorize(Permission::TemplateManage->value, $templateFamily);

        return $this->success(
            TemplateFamilyResource::make($templateFamily->load($this->versionRelations())),
            'Template family retrieved successfully.'
        );
    }

    public function update(Request $request, TemplateFamily $templateFamily): JsonResponse
    {
        Gate::authorize(Permission::TemplateManage->value, $templateFamily);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:128'],
            'purpose' => ['sometimes', Rule::enum(TemplatePurpose::class)],
            'channel' => ['sometimes', Rule::enum(TemplateChannel::class)],
        ]);

        $templateFamily->update($validated);

        return $this->success(
            TemplateFamilyResource::make($templateFamily->fresh($this->versionRelations())),
            'Template family updated successfully.'
        );
    }

    public function archive(TemplateFamily $templateFamily): JsonResponse
    {
        Gate::authorize(Permission::TemplateManage->value, $templateFamily);

        if ($templateFamily->archived_at !== null) {
            return $this->error('Template family is already archived.', [], 422);
        }

        $templateFamily->update(['archived_at' => now()]);

        return $this->success(
            TemplateFamilyResource::make($templateFamily->fresh($this->versionRelations())),
            'Template family archived successfully.'
        );
    }

    public function destroy(TemplateFamily $templateFamily): JsonResponse
    {
        Gate::authorize(Permission::TemplateManage->value, $templateFamily);

        if ($templateFamily->archived_at === null) {
            $templateFamily->update(['archived_at' => now()]);
        }

        return $this->noContent('Template family archived successfully.');
    }

    public function versions(TemplateFamily $templateFamily): JsonResponse
    {
        Gate::authorize(Permission::TemplateManage->value, $templateFamily);

        $versions = $templateFamily->versions()
            ->with('variants')
            ->reorder()
            ->orderByDesc('version_number')
            ->get();

        $items = $versions->map(
            fn (TemplateVersion $version): array => (new TemplateVersionResource($version, 'history'))->resolve()
        )->all();

        return $this->success($items, 'Template versions retrieved successfully.');
    }

    public function showVersion(TemplateFamily $templateFamily, TemplateVersion $templateVersion): JsonResponse
    {
        Gate::authorize(Permission::TemplateManage->value, $templateFamily);

        $this->assertVersionBelongs($templateFamily, $templateVersion);

        return $this->success(
            new TemplateVersionResource($templateVersion->load('variants'), 'detail'),
            'Template version retrieved successfully.'
        );
    }

    public function storeVersion(Request $request, TemplateFamily $templateFamily): JsonResponse
    {
        Gate::authorize(Permission::TemplateManage->value, $templateFamily);

        $validated = $request->validate([
            'from_version_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $fromVersionId = isset($validated['from_version_id']) ? (int) $validated['from_version_id'] : null;
        $opened = $this->openDraft($templateFamily, $fromVersionId, $request->user());

        if (! $opened['created']) {
            return $this->draftConflict($opened['version']);
        }

        return $this->created(
            new TemplateVersionResource($opened['version'], 'detail'),
            'Template draft created successfully.'
        );
    }

    public function publish(
        Request $request,
        TemplateFamily $templateFamily,
        TemplateVersion $templateVersion,
    ): JsonResponse {
        Gate::authorize(Permission::TemplateManage->value, $templateFamily);

        $this->assertVersionBelongs($templateFamily, $templateVersion);

        $warnings = DB::transaction(function () use ($request, $templateFamily, $templateVersion): array {
            $draft = TemplateVersion::query()->whereKey($templateVersion->id)->lockForUpdate()->firstOrFail();

            if ($draft->status !== TemplateVersionStatus::Draft) {
                throw ValidationException::withMessages([
                    'version' => [__('errors.templates.version_published')],
                ]);
            }

            $draft->load('variants');
            if ($draft->variants->isEmpty()) {
                throw ValidationException::withMessages([
                    'version' => [__('errors.templates.publish_empty')],
                ]);
            }

            $warnings = TemplatePublishValidator::warnings($draft);

            $draft->status = TemplateVersionStatus::Published;
            $draft->published_at = now();
            $draft->published_by = $request->user()?->getKey();
            $draft->save();

            $this->logVersion($templateFamily, 'template.version.published', $draft, $request->user());

            return $warnings;
        });

        return $this->success(
            new TemplateFamilyResource($templateFamily->fresh($this->versionRelations())),
            'Template version published.',
            warnings: $warnings,
        );
    }

    public function destroyVersion(
        Request $request,
        TemplateFamily $templateFamily,
        TemplateVersion $templateVersion,
    ): JsonResponse {
        Gate::authorize(Permission::TemplateManage->value, $templateFamily);

        $this->assertVersionBelongs($templateFamily, $templateVersion);

        DB::transaction(function () use ($request, $templateFamily, $templateVersion): void {
            $version = TemplateVersion::query()->whereKey($templateVersion->id)->lockForUpdate()->firstOrFail();

            if ($version->status !== TemplateVersionStatus::Draft) {
                throw ValidationException::withMessages([
                    'version' => [__('errors.templates.version_published')],
                ]);
            }

            $this->logVersion($templateFamily, 'template.version.discarded', $version, $request->user());
            $version->delete();
        });

        return $this->noContent('Template draft discarded successfully.');
    }

    public function storeVariant(Request $request, TemplateFamily $templateFamily): JsonResponse
    {
        Gate::authorize(Permission::TemplateManage->value, $templateFamily);

        $validated = $this->variantRules($request, updating: false);

        $version = $this->ensureDraft($templateFamily, $request->user());

        if ($version->variants()->where('locale', $validated['locale'])->exists()) {
            throw ValidationException::withMessages([
                'locale' => ['A variant for this locale already exists on the family.'],
            ]);
        }

        $copyFrom = null;
        if (! empty($validated['copy_from_variant_id'])) {
            $copyFrom = TemplateVariant::query()->find($validated['copy_from_variant_id']);
            if ($copyFrom === null || $copyFrom->template_family_id !== $templateFamily->id) {
                throw ValidationException::withMessages([
                    'copy_from_variant_id' => [__('errors.templates.variant_mismatch')],
                ]);
            }
        }

        $payload = $validated;
        if ($copyFrom instanceof TemplateVariant) {
            if (! array_key_exists('subject', $payload)) {
                $payload['subject'] = $copyFrom->subject;
            }
            if (! array_key_exists('blocks', $payload) && ! array_key_exists('legacy_html', $payload)) {
                $payload['blocks'] = $copyFrom->blocks;
                $payload['legacy_html'] = $copyFrom->legacy_html;
            }
            if (! array_key_exists('body_text', $payload)) {
                $payload['body_text'] = $copyFrom->body_text;
            }
        }

        $this->createOrUpdateVariant($templateFamily, $version, $payload, $request->user()?->id);

        return $this->created(
            TemplateFamilyResource::make($templateFamily->fresh($this->versionRelations())),
            'Template variant created successfully.'
        );
    }

    public function updateVariant(
        Request $request,
        TemplateFamily $templateFamily,
        TemplateVariant $variant,
    ): JsonResponse {
        Gate::authorize(Permission::TemplateManage->value, $templateFamily);

        $this->assertVariantBelongs($templateFamily, $variant);
        $validated = $this->variantRules($request, updating: true);
        $target = $this->mutableVariant($templateFamily, $variant, $request->user());

        if (isset($validated['locale']) && $validated['locale'] !== $target->locale) {
            $exists = $target->version->variants()
                ->where('locale', $validated['locale'])
                ->where('id', '!=', $target->id)
                ->exists();
            if ($exists) {
                throw ValidationException::withMessages([
                    'locale' => ['A variant for this locale already exists on the family.'],
                ]);
            }
        }

        $this->applyVariantPayload($target, $validated, $request->user()?->id);

        return $this->success(
            TemplateFamilyResource::make($templateFamily->fresh($this->versionRelations())),
            'Template variant updated successfully.'
        );
    }

    public function destroyVariant(TemplateFamily $templateFamily, TemplateVariant $variant): JsonResponse
    {
        Gate::authorize(Permission::TemplateManage->value, $templateFamily);

        $this->assertVariantBelongs($templateFamily, $variant);
        $variant->loadMissing('version');

        if ($variant->version->status !== TemplateVersionStatus::Draft) {
            throw ValidationException::withMessages([
                'variant' => [__('errors.templates.version_published')],
            ]);
        }

        if ($variant->version->variants()->count() <= 1) {
            throw ValidationException::withMessages([
                'variant' => ['The last variant on a family cannot be deleted.'],
            ]);
        }

        $variant->delete();

        return $this->noContent('Template variant deleted successfully.');
    }

    public function preview(
        Request $request,
        TemplateFamily $templateFamily,
        TemplateVariant $variant,
    ): Response {
        Gate::authorize(Permission::TemplateManage->value, $templateFamily);

        $this->assertVariantBelongs($templateFamily, $variant);

        $validated = $request->validate([
            'contact_id' => ['required', 'integer', 'exists:contacts,id'],
            'contract_id' => ['sometimes', 'nullable', 'integer', 'exists:contracts,id'],
        ]);

        $contact = Contact::query()->findOrFail($validated['contact_id']);
        $contract = null;
        if (! empty($validated['contract_id'])) {
            $contract = Contract::query()->findOrFail($validated['contract_id']);
            if ($contract->contact_id !== $contact->id) {
                throw ValidationException::withMessages([
                    'contract_id' => ['Contract does not belong to the selected contact.'],
                ]);
            }
        }

        if ($templateFamily->channel === TemplateChannel::Document) {
            if ($contract === null) {
                throw ValidationException::withMessages([
                    'contract_id' => [__('errors.documents.preview_requires_contract')],
                ]);
            }

            $rendered = ContractDocumentRenderer::render($contract, $this->openedVariant($variant, $contact, null));

            return response($rendered['html'], 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
            ]);
        }

        $context = TemplateBuilderContext::for($contact, $contract);
        $rendered = EmailTemplateRenderer::render($this->openedVariant($variant, $contact, null), $context, previewMarkers: true);

        return response($rendered['html'], 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    public function testSend(
        Request $request,
        TemplateFamily $templateFamily,
        TemplateVariant $variant,
        EmailSender $sender,
    ): JsonResponse {
        Gate::authorize(Permission::TemplateManage->value, $templateFamily);

        $this->assertVariantBelongs($templateFamily, $variant);

        if ($templateFamily->channel === TemplateChannel::Document) {
            return $this->error('Test send is not available for document templates.', [], 422);
        }

        $validated = $request->validate([
            'to' => ['required', 'email', 'max:255'],
            'contact_id' => ['required', 'integer', 'exists:contacts,id'],
            'contract_id' => ['sometimes', 'nullable', 'integer', 'exists:contracts,id'],
            'site_id' => ['sometimes', 'nullable', 'integer', 'exists:sites,id'],
        ]);

        $contact = Contact::query()->findOrFail($validated['contact_id']);
        $contract = null;
        if (! empty($validated['contract_id'])) {
            $contract = Contract::query()->findOrFail($validated['contract_id']);
            if ($contract->contact_id !== $contact->id) {
                throw ValidationException::withMessages([
                    'contract_id' => ['Contract does not belong to the selected contact.'],
                ]);
            }
        }

        $site = ! empty($validated['site_id'])
            ? Site::query()->findOrFail($validated['site_id'])
            : Site::query()->orderBy('id')->first();

        if ($site === null) {
            return $this->error(__('errors.templates.test_send_failed'), [], 422);
        }

        $context = TemplateBuilderContext::for($contact, $contract);
        $opened = $this->openedVariant($variant, $contact, $site);
        $rendered = EmailTemplateRenderer::render($opened, $context, previewMarkers: false);

        $message = new EmailMessage(
            to: [new EmailAddress($validated['to'])],
            subject: $rendered['subject'] !== '' ? $rendered['subject'] : (string) $templateFamily->name,
            html: $rendered['html'],
            text: $rendered['text'],
        );

        $result = $sender->send(
            $message,
            $site,
            $contact,
            SendContext::system(
                ['template_family_id' => $templateFamily->id, 'template_variant_id' => $variant->id, 'test' => true],
                SendClass::Transactional,
            ),
            detail: [
                'token_warnings' => $rendered['warnings'],
                'test_send' => true,
                'template' => TemplateProvenance::from($opened, $contact, $site)->toArray(),
            ],
        );

        if ($result->wasSuppressed()) {
            return $this->error(__('errors.templates.test_send_failed'), [
                'to' => [$result->suppressedReason ?? 'suppressed'],
            ], 422);
        }

        return $this->success([
            'message_id' => $result->messageId,
            'provider_message_id' => $result->providerMessageId,
        ], 'Test email sent.');
    }

    public function sampleContexts(Request $request): JsonResponse
    {
        Gate::authorize(Permission::TemplateManage->value);

        $contacts = Contact::query()
            ->orderByDesc('updated_at')
            ->limit(25)
            ->get(['id', 'first_name', 'last_name', 'email', 'locale']);

        $items = $contacts->map(function (Contact $contact): array {
            $contracts = Contract::query()
                ->where('contact_id', $contact->id)
                ->orderByDesc('id')
                ->limit(5)
                ->get(['id', 'contact_id', 'currency', 'status']);

            return [
                'contact' => [
                    'id' => $contact->id,
                    'name' => trim($contact->first_name.' '.$contact->last_name),
                    'email' => $contact->email,
                    'locale' => $contact->locale,
                ],
                'contracts' => $contracts->map(fn (Contract $c) => [
                    'id' => $c->id,
                    'currency' => $c->currency,
                    'status' => $c->status?->value ?? $c->status,
                ])->values()->all(),
            ];
        })->values()->all();

        return $this->success($items, 'Sample contexts retrieved.');
    }

    /**
     * @return array<string, mixed>
     */
    private function variantRules(Request $request, bool $updating = false): array
    {
        $localeRule = $updating
            ? ['sometimes', 'required', 'string', Rule::in(SiteLocale::ALLOWED)]
            : ['required', 'string', Rule::in(SiteLocale::ALLOWED)];

        return $request->validate([
            'locale' => $localeRule,
            'subject' => ['sometimes', 'nullable', 'string', 'max:500'],
            'body_text' => ['sometimes', 'nullable', 'string'],
            'legacy_html' => ['sometimes', 'nullable', 'string'],
            'blocks' => ['sometimes', 'nullable', 'array'],
            'copy_from_variant_id' => ['sometimes', 'nullable', 'integer'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function createOrUpdateVariant(
        TemplateFamily $family,
        TemplateVersion $version,
        array $validated,
        ?int $employeeId,
    ): TemplateVariant {
        $variant = new TemplateVariant([
            'template_family_id' => $family->id,
            'template_version_id' => $version->id,
        ]);
        $this->applyVariantPayload($variant, $validated, $employeeId);

        return $variant;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function applyVariantPayload(TemplateVariant $variant, array $validated, ?int $employeeId): void
    {
        if (isset($validated['locale'])) {
            $variant->locale = $validated['locale'];
        }
        if (array_key_exists('subject', $validated)) {
            $variant->subject = $validated['subject'];
        }
        if (array_key_exists('body_text', $validated)) {
            $variant->body_text = $validated['body_text'];
        }

        if (array_key_exists('blocks', $validated) && $validated['blocks'] !== null) {
            $family = $variant->family ?? TemplateFamily::query()->findOrFail($variant->template_family_id);
            $channel = $family->channel instanceof TemplateChannel
                ? $family->channel
                : TemplateChannel::from((string) $family->channel);
            $purpose = $family->purpose instanceof TemplatePurpose
                ? $family->purpose
                : TemplatePurpose::from((string) $family->purpose);
            $doc = $this->validateBlocksForChannel($channel, $validated['blocks'], $purpose);
            $variant->blocks = $doc;
            $variant->legacy_html = null;
        } else {
            if (array_key_exists('blocks', $validated) && $validated['blocks'] === null) {
                $variant->blocks = null;
            }
            if (array_key_exists('legacy_html', $validated)) {
                $variant->legacy_html = $validated['legacy_html'];
            }
        }

        $variant->updated_by = $employeeId;
        $variant->save();
    }

    /**
     * @param  array<string, mixed>  $blocks
     * @return array{version: int, blocks: list<array{id: string, type: string, params: array<string, mixed>}>}
     */
    private function validateBlocksForChannel(
        TemplateChannel $channel,
        array $blocks,
        TemplatePurpose $purpose,
    ): array {
        return match ($channel) {
            TemplateChannel::Document => DocumentBlockDocument::validate($blocks, $purpose),
            default => EmailBlockDocument::validate($blocks),
        };
    }

    /**
     * Resolve the opened locale tab inside its own version, drafts included.
     */
    private function openedVariant(TemplateVariant $variant, Contact $contact, ?Site $site): TemplateVariant
    {
        $variant->loadMissing('version');
        $pinned = clone $contact;
        $pinned->locale = $variant->locale;

        return TemplateResolver::variantOf($variant->version, $pinned, $site);
    }

    private function assertVariantBelongs(TemplateFamily $family, TemplateVariant $variant): void
    {
        if ($variant->template_family_id !== $family->id) {
            abort(404);
        }
    }

    private function assertVersionBelongs(TemplateFamily $family, TemplateVersion $version): void
    {
        if ($version->template_family_id !== $family->id) {
            abort(404);
        }
    }

    /** @return list<string> */
    private function versionRelations(): array
    {
        return ['currentVersion.variants', 'draft.variants'];
    }

    private function ensureDraft(TemplateFamily $family, ?Model $causer): TemplateVersion
    {
        $existing = $this->findDraft($family);
        if ($existing instanceof TemplateVersion) {
            return $existing;
        }

        $opened = $this->openDraft($family, null, $causer);

        return $opened['version'];
    }

    private function mutableVariant(
        TemplateFamily $family,
        TemplateVariant $variant,
        ?Model $causer,
    ): TemplateVariant {
        $variant->loadMissing('version');
        $version = $variant->version;

        if ($version->status === TemplateVersionStatus::Draft) {
            return $variant;
        }

        if ($this->findDraft($family) instanceof TemplateVersion) {
            throw ValidationException::withMessages([
                'variant' => [__('errors.templates.version_published')],
            ]);
        }

        $currentId = $this->latestPublishedId($family);
        if ($currentId === null || $currentId !== $version->id) {
            throw ValidationException::withMessages([
                'variant' => [__('errors.templates.version_published')],
            ]);
        }

        $opened = $this->openDraft($family, $currentId, $causer);
        if (! $opened['created']) {
            throw ValidationException::withMessages([
                'variant' => [__('errors.templates.version_published')],
            ]);
        }

        $copy = $opened['version']->variants->firstWhere('locale', $variant->locale);
        if (! $copy instanceof TemplateVariant) {
            throw ValidationException::withMessages([
                'variant' => [__('errors.templates.variant_mismatch')],
            ]);
        }

        return $copy;
    }

    /**
     * @return array{version: TemplateVersion, created: bool}
     */
    private function openDraft(TemplateFamily $family, ?int $fromVersionId, ?Model $causer): array
    {
        try {
            return DB::transaction(function () use ($family, $fromVersionId, $causer): array {
                TemplateFamily::query()->whereKey($family->id)->lockForUpdate()->firstOrFail();

                $existing = $this->findDraft($family);
                if ($existing instanceof TemplateVersion) {
                    return [
                        'version' => $existing->load('variants'),
                        'created' => false,
                    ];
                }

                $source = $this->draftSource($family, $fromVersionId);
                $next = ((int) TemplateVersion::query()
                    ->where('template_family_id', $family->id)
                    ->max('version_number')) + 1;

                $draft = $family->versions()->create([
                    'version_number' => $next,
                    'status' => TemplateVersionStatus::Draft,
                    'based_on_version_id' => $source?->id,
                    'published_at' => null,
                    'created_by' => $causer?->getKey(),
                ]);

                if ($source instanceof TemplateVersion) {
                    foreach ($source->variants as $variant) {
                        $draft->variants()->create([
                            'template_family_id' => $family->id,
                            'locale' => $variant->locale,
                            'subject' => $variant->subject,
                            'blocks' => $variant->blocks,
                            'legacy_html' => $variant->legacy_html,
                            'body_text' => $variant->body_text,
                            'updated_by' => $causer?->getKey(),
                        ]);
                    }
                }

                $latestId = $this->latestPublishedId($family);
                $event = $source instanceof TemplateVersion && $latestId !== null && $source->id !== $latestId
                    ? 'template.version.restored'
                    : 'template.version.drafted';
                $this->logVersion($family, $event, $draft, $causer);

                return [
                    'version' => $draft->load('variants'),
                    'created' => true,
                ];
            });
        } catch (UniqueConstraintViolationException $exception) {
            $existing = $this->findDraft($family);
            if ($existing instanceof TemplateVersion) {
                return [
                    'version' => $existing->load('variants'),
                    'created' => false,
                ];
            }

            throw $exception;
        }
    }

    private function draftSource(TemplateFamily $family, ?int $fromVersionId): ?TemplateVersion
    {
        if ($fromVersionId !== null) {
            $source = TemplateVersion::query()
                ->whereKey($fromVersionId)
                ->where('template_family_id', $family->id)
                ->with('variants')
                ->first();

            if (! $source instanceof TemplateVersion || $source->status !== TemplateVersionStatus::Published) {
                throw ValidationException::withMessages([
                    'from_version_id' => [__('errors.templates.version_mismatch')],
                ]);
            }

            return $source;
        }

        $latestId = $this->latestPublishedId($family);
        if ($latestId === null) {
            return null;
        }

        return TemplateVersion::query()->with('variants')->find($latestId);
    }

    private function findDraft(TemplateFamily $family): ?TemplateVersion
    {
        return TemplateVersion::query()
            ->where('template_family_id', $family->id)
            ->where('status', TemplateVersionStatus::Draft)
            ->first();
    }

    private function latestPublishedId(TemplateFamily $family): ?int
    {
        $id = TemplateVersion::query()
            ->where('template_family_id', $family->id)
            ->where('status', TemplateVersionStatus::Published)
            ->orderByDesc('version_number')
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    private function draftConflict(TemplateVersion $draft): JsonResponse
    {
        return $this->success(
            new TemplateVersionResource($draft->loadMissing('variants'), 'draft'),
            __('errors.templates.draft_exists'),
            409,
        );
    }

    private function logVersion(
        TemplateFamily $family,
        string $event,
        TemplateVersion $version,
        mixed $causer,
    ): void {
        RecordsActivity::log(LogChannel::Comms, $event, $family, [
            'version_number' => $version->version_number,
            'based_on_version_id' => $version->based_on_version_id,
        ], $causer instanceof Model ? $causer : null);
    }
}
