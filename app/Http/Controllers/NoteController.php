<?php

namespace App\Http\Controllers;

use App\Http\Resources\NoteResource;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\Employee;
use App\Models\Reservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Support\Auth\Permission;
use Illuminate\Support\Facades\Gate;

class NoteController extends Controller
{
    private const TYPE_MAP = [
        'contact'     => Contact::class,
        'deal'        => Deal::class,
        'reservation' => Reservation::class,
        'contract'    => Contract::class,
    ];

    public function store(Request $request): JsonResponse
    {
        Gate::authorize(Permission::ContactManage->value);

        $validated = $request->validate([
            'type'    => ['required', 'string', Rule::in(array_keys(self::TYPE_MAP))],
            'id'      => ['required', 'integer'],
            'content' => ['required', 'string'],
        ]);

        $modelClass = self::TYPE_MAP[$validated['type']];
        $notable = $modelClass::query()->findOrFail($validated['id']);

        $employee = Employee::query()->first();

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
