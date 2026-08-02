<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TemplateChannel;
use App\Enums\TemplatePurpose;
use App\Http\Resources\TemplateFamilyResource;
use App\Models\TemplateFamily;
use App\Models\TemplateVariant;
use App\Support\Communications\LegacyEmailBlocksHtml;
use App\Support\Communications\SiteLocale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            'blocks.*.type' => ['required_with:blocks', 'string', 'max:50'],
            'blocks.*.props' => ['nullable', 'array'],
        ]);

        $family = DB::transaction(function () use ($validated, $request): TemplateFamily {
            $family = TemplateFamily::query()->create([
                'channel' => $validated['channel'],
                'name' => $validated['name'],
                'purpose' => $validated['purpose'] ?? TemplatePurpose::General,
            ]);

            $locale = $validated['locale'] ?? 'en';
            $legacyHtml = $validated['legacy_html'] ?? null;
            if ($legacyHtml === null && ! empty($validated['blocks'])) {
                $legacyHtml = LegacyEmailBlocksHtml::fromBlocks($validated['blocks']);
            }

            TemplateVariant::query()->create([
                'template_family_id' => $family->id,
                'locale' => $locale,
                'subject' => $validated['subject'] ?? $validated['name'],
                'legacy_html' => $legacyHtml,
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
        $validated = $this->variantRules($request, $templateFamily);

        if ($templateFamily->variants()->where('locale', $validated['locale'])->exists()) {
            throw ValidationException::withMessages([
                'locale' => ['A variant for this locale already exists on the family.'],
            ]);
        }

        $this->createOrUpdateVariant($templateFamily, $validated, $request->user()?->id);

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
        $validated = $this->variantRules($request, $templateFamily, updating: true);

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

    /**
     * @return array<string, mixed>
     */
    private function variantRules(Request $request, TemplateFamily $family, bool $updating = false): array
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
            'blocks.*.type' => ['required_with:blocks', 'string', 'max:50'],
            'blocks.*.props' => ['nullable', 'array'],
            'blocks.*.params' => ['nullable', 'array'],
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
        if (array_key_exists('legacy_html', $validated)) {
            $variant->legacy_html = $validated['legacy_html'];
        }
        if (array_key_exists('blocks', $validated)) {
            $blocks = $validated['blocks'];
            // Transitional: old editor block arrays freeze to legacy_html.
            if (is_array($blocks) && $blocks !== [] && isset($blocks[0]['type'], $blocks[0]['props'])) {
                $variant->legacy_html = LegacyEmailBlocksHtml::fromBlocks($blocks);
                $variant->blocks = null;
            } else {
                $variant->blocks = $blocks;
            }
        }
        $variant->updated_by = $employeeId;
        $variant->save();
    }

    private function assertVariantBelongs(TemplateFamily $family, TemplateVariant $variant): void
    {
        if ($variant->template_family_id !== $family->id) {
            abort(404);
        }
    }
}
