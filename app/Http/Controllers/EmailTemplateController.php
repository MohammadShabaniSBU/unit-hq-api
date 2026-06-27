<?php

namespace App\Http\Controllers;

use App\Http\Resources\EmailTemplateResource;
use App\Models\EmailBlock;
use App\Models\EmailTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailTemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = EmailTemplate::query()->with('emailBlocks')->latest();

        if ($request->filled('search')) {
            $search = $request->string('search')->trim()->value();
            $query->where('name', 'like', "%{$search}%");
        }

        return $this->paginated(
            $query->paginate($this->perPage())->through(fn (EmailTemplate $template) => EmailTemplateResource::make($template)),
            'Email templates retrieved successfully.'
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'              => ['required', 'string', 'max:255'],
            'blocks'            => ['nullable', 'array'],
            'blocks.*.type'     => ['required', 'string', 'max:50'],
            'blocks.*.props'    => ['nullable', 'array'],
        ]);

        $template = EmailTemplate::query()->create(['name' => $validated['name']]);

        $this->syncBlocks($template, $validated['blocks'] ?? []);

        return $this->created(
            EmailTemplateResource::make($template->load('emailBlocks')),
            'Email template created successfully.'
        );
    }

    public function show(EmailTemplate $emailTemplate): JsonResponse
    {
        return $this->success(
            EmailTemplateResource::make($emailTemplate->load('emailBlocks')),
            'Email template retrieved successfully.'
        );
    }

    public function update(Request $request, EmailTemplate $emailTemplate): JsonResponse
    {
        $validated = $request->validate([
            'name'              => ['sometimes', 'required', 'string', 'max:255'],
            'blocks'            => ['sometimes', 'nullable', 'array'],
            'blocks.*.type'     => ['required_with:blocks', 'string', 'max:50'],
            'blocks.*.props'    => ['nullable', 'array'],
        ]);

        if (isset($validated['name'])) {
            $emailTemplate->update(['name' => $validated['name']]);
        }

        if (array_key_exists('blocks', $validated)) {
            $this->syncBlocks($emailTemplate, $validated['blocks'] ?? []);
        }

        return $this->success(
            EmailTemplateResource::make($emailTemplate->load('emailBlocks')),
            'Email template updated successfully.'
        );
    }

    public function destroy(EmailTemplate $emailTemplate): JsonResponse
    {
        $emailTemplate->delete();

        return $this->noContent('Email template deleted successfully.');
    }

    /** @param array<int, array<string, mixed>> $blocks */
    private function syncBlocks(EmailTemplate $template, array $blocks): void
    {
        $template->emailBlocks()->delete();

        if (empty($blocks)) {
            return;
        }

        $records = array_map(fn (array $block, int $index) => [
            'email_template_id' => $template->id,
            'type'              => $block['type'],
            'props'             => json_encode($block['props'] ?? []),
            'order'             => $index,
            'created_at'        => now(),
            'updated_at'        => now(),
        ], $blocks, array_keys($blocks));

        EmailBlock::insert($records);
    }
}
