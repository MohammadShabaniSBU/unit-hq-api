<?php

namespace App\Http\Controllers;

use App\Enums\AttributeEntityType;
use App\Http\Resources\NoteResource;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\Offer;
use App\Models\Reservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Gate;

class NoteController extends Controller
{
    private const TYPE_MAP = [
        'contact'     => Contact::class,
        'deal'        => Deal::class,
        'offer'       => Offer::class,
        'reservation' => Reservation::class,
        'contract'    => Contract::class,
    ];

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type'    => ['required', 'string', Rule::in(array_keys(self::TYPE_MAP))],
            'id'      => ['required', 'integer'],
            'content' => ['required', 'string'],
        ]);

        $modelClass = self::TYPE_MAP[$validated['type']];
        $notable = $modelClass::query()->findOrFail($validated['id']);

        // Reuses AttributeEntityType's permission map — a note's write permission
        // is the same "can manage this record" permission custom fields use.
        $type = AttributeEntityType::from($validated['type']);
        Gate::authorize($type->managePermission()->value, $notable);

        $employee = $request->user();

        if ($employee === null) {
            throw ValidationException::withMessages([
                'content' => ['No employee record found to attribute this note.'],
            ]);
        }

        $note = $notable->notes()->create([
            'content'     => $validated['content'],
            'employee_id' => $employee->id,
        ]);

        return $this->created(
            NoteResource::make($note->load('employee')),
            'Note created successfully.'
        );
    }
}
