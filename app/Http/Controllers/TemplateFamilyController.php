<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TemplateChannel;
use App\Enums\TemplatePurpose;
use App\Http\Resources\TemplateFamilyResource;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Site;
use App\Models\TemplateFamily;
use App\Models\TemplateVariant;
use App\Support\Communications\EmailTemplateRenderer;
use App\Support\Communications\EmailBlockDocument;
use App\Support\Communications\Messages\EmailAddress;
use App\Support\Communications\Messages\EmailMessage;
use App\Support\Communications\SendClass;
use App\Support\Communications\SendContext;
use App\Support\Communications\Senders\EmailSender;
use App\Support\Communications\SiteLocale;
use App\Support\Communications\TemplateBuilderContext;
use App\Support\Documents\ContractDocumentRenderer;
use App\Support\Documents\DocumentBlockDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TemplateFamilyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'channel' => ['sometimes', 'nullable', Rule::enum(TemplateChannel::class)],
            'purpose' => ['sometimes', 'nullable', Rule::enum(TemplatePurpose::class)],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'nullable', Rule::in(['active', 'archived', 'all'])],
        ]);

        $query = TemplateFamily::query()->with('variants')->latest();

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

        return $this->paginated(
            $query->paginate($this->perPage())->through(
                fn (TemplateFamily $family) => TemplateFamilyResource::make($family)
            ),
            'Template families retrieved successfully.'
        );
    }

    public function store(Request $request): JsonResponse
    {
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

            TemplateVariant::query()->create([
                'template_family_id' => $family->id,
                'locale' => $validated['locale'] ?? 'en',
                'subject' => $validated['subject'] ?? $validated['name'],
                'blocks' => $blocksDoc,
                'legacy_html' => $blocksDoc !== null ? null : ($validated['legacy_html'] ?? null),
                'body_text' => $validated['body_text'] ?? null,
                'updated_by' => $request->user()?->id,
            ]);

            return $family;
        });

        return $this->created(
            TemplateFamilyResource::make($family->load('variants')),
            'Template family created successfully.'
        );
    }

    public function show(TemplateFamily $templateFamily): JsonResponse
    {
        return $this->success(
            TemplateFamilyResource::make($templateFamily->load('variants')),
            'Template family retrieved successfully.'
        );
    }

    public function update(Request $request, TemplateFamily $templateFamily): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:128'],
            'purpose' => ['sometimes', Rule::enum(TemplatePurpose::class)],
            'channel' => ['sometimes', Rule::enum(TemplateChannel::class)],
        ]);

        $templateFamily->update($validated);

        return $this->success(
            TemplateFamilyResource::make($templateFamily->fresh('variants')),
            'Template family updated successfully.'
        );
    }

    public function archive(TemplateFamily $templateFamily): JsonResponse
    {
        if ($templateFamily->archived_at !== null) {
            return $this->error('Template family is already archived.', [], 422);
        }

        $templateFamily->update(['archived_at' => now()]);

        return $this->success(
            TemplateFamilyResource::make($templateFamily->fresh('variants')),
            'Template family archived successfully.'
        );
    }

    public function destroy(TemplateFamily $templateFamily): JsonResponse
    {
        if ($templateFamily->archived_at === null) {
            $templateFamily->update(['archived_at' => now()]);
        }

        return $this->noContent('Template family archived successfully.');
    }

    public function storeVariant(Request $request, TemplateFamily $templateFamily): JsonResponse
    {
        $validated = $this->variantRules($request, updating: false);

        if ($templateFamily->variants()->where('locale', $validated['locale'])->exists()) {
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

        $this->createOrUpdateVariant($templateFamily, $payload, $request->user()?->id);

        return $this->created(
            TemplateFamilyResource::make($templateFamily->fresh('variants')),
            'Template variant created successfully.'
        );
    }

    public function updateVariant(
        Request $request,
        TemplateFamily $templateFamily,
        TemplateVariant $variant,
    ): JsonResponse {
        $this->assertVariantBelongs($templateFamily, $variant);
        $validated = $this->variantRules($request, updating: true);

        if (isset($validated['locale']) && $validated['locale'] !== $variant->locale) {
            $exists = $templateFamily->variants()
                ->where('locale', $validated['locale'])
                ->where('id', '!=', $variant->id)
                ->exists();
            if ($exists) {
                throw ValidationException::withMessages([
                    'locale' => ['A variant for this locale already exists on the family.'],
                ]);
            }
        }

        $this->applyVariantPayload($variant, $validated, $request->user()?->id);

        return $this->success(
            TemplateFamilyResource::make($templateFamily->fresh('variants')),
            'Template variant updated successfully.'
        );
    }

    public function destroyVariant(TemplateFamily $templateFamily, TemplateVariant $variant): JsonResponse
    {
        $this->assertVariantBelongs($templateFamily, $variant);

        if ($templateFamily->variants()->count() <= 1) {
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

            $rendered = ContractDocumentRenderer::render($contract, $variant);

            return response($rendered['html'], 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
            ]);
        }

        $context = TemplateBuilderContext::for($contact, $contract);
        $rendered = EmailTemplateRenderer::render($variant, $context, previewMarkers: true);

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
        $rendered = EmailTemplateRenderer::render($variant, $context, previewMarkers: false);

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
        array $validated,
        ?int $employeeId,
    ): TemplateVariant {
        $variant = new TemplateVariant(['template_family_id' => $family->id]);
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

    private function assertVariantBelongs(TemplateFamily $family, TemplateVariant $variant): void
    {
        if ($variant->template_family_id !== $family->id) {
            abort(404);
        }
    }
}
