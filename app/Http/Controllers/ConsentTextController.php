<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ConsentText;
use App\Models\Employee;
use App\Support\Auth\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ConsentTextController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize(Permission::ConsentManage->value);

        $texts = ConsentText::query()
            ->orderBy('key')
            ->orderBy('locale')
            ->orderByDesc('version')
            ->get()
            ->map(fn (ConsentText $text): array => $this->payload($text))
            ->all();

        return $this->success($texts, 'Consent texts retrieved successfully.');
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize(Permission::ConsentManage->value);

        /** @var Employee $employee */
        $employee = $request->user();

        $validated = $request->validate([
            'key' => ['required', 'string', 'max:64'],
            'locale' => ['required', 'string', 'max:5'],
            'body' => ['required', 'string'],
        ]);

        $text = DB::transaction(function () use ($validated, $employee): ConsentText {
            $current = ConsentText::query()
                ->where('key', $validated['key'])
                ->where('locale', $validated['locale'])
                ->orderByDesc('version')
                ->lockForUpdate()
                ->value('version');

            return ConsentText::query()->create([
                'key' => $validated['key'],
                'locale' => $validated['locale'],
                'version' => ((int) $current) + 1,
                'body' => $validated['body'],
                'created_by' => $employee->id,
            ]);
        });

        return $this->created($this->payload($text), 'Consent text recorded.');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ConsentText $text): array
    {
        return [
            'id' => $text->id,
            'key' => $text->key,
            'version' => $text->version,
            'locale' => $text->locale,
            'body' => $text->body,
            'created_at' => $text->created_at->toIso8601String(),
        ];
    }
}
