<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Enums\AttributeEntityType;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\Employee;
use App\Models\Offer;
use App\Models\Reservation;
use App\Support\Auth\SubjectSite;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class CreateNote implements Tool, Approvable
{
    use InteractsWithApprovals;

    private const TYPE_MAP = [
        'contact' => Contact::class,
        'deal' => Deal::class,
        'offer' => Offer::class,
        'reservation' => Reservation::class,
        'contract' => Contract::class,
    ];

    public function __construct(private readonly Employee $employee) {}

    public function description(): Stringable|string
    {
        return 'Add a note to a contact, deal, offer, reservation, or contract.';
    }

    public function handle(Request $request): Stringable|string
    {
        $type = $request['notable_type'] ?? null;

        if (! isset(self::TYPE_MAP[$type])) {
            return json_encode([
                'success' => false,
                'error' => "Unsupported notable_type '{$type}'.",
            ]);
        }

        $modelClass = self::TYPE_MAP[$type];
        $notable = $modelClass::query()->find($request['notable_id']);

        if ($notable === null) {
            return json_encode([
                'success' => false,
                'error' => "No {$type} found with that ID.",
            ]);
        }

        $permission = AttributeEntityType::from($type)->managePermission();

        if (! $this->employee->allowsPermission($permission, SubjectSite::for($notable))) {
            return json_encode([
                'success' => false,
                'error' => "You do not have permission to add a note to this {$type}.",
            ]);
        }

        $note = $notable->notes()->create([
            'content' => $request['content'],
            'employee_id' => $this->employee->id,
        ]);

        return json_encode([
            'success' => true,
            'message' => 'Note added successfully.',
            'note_id' => $note->id,
            'notable_type' => $type,
            'notable_id' => $notable->id,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'notable_type' => $schema->string()
                ->description('Type of record to attach the note to')
                ->enum(array_keys(self::TYPE_MAP))
                ->required(),
            'notable_id' => $schema->integer()
                ->description('ID of the record to attach the note to')
                ->required(),
            'content' => $schema->string()
                ->description('Note content')
                ->required(),
        ];
    }
}
