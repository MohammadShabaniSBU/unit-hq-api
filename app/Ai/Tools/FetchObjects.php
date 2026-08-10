<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Enums\AttributeEntityType;
use App\Http\Resources\AttributeValueResource;
use App\Models\AttributeDefinition;
use App\Models\AttributeValue;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\Delinquency;
use App\Models\Employee;
use App\Models\MessageThread;
use App\Models\Offer;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Unit;
use App\Support\Auth\Permission;
use App\Support\Auth\SubjectSite;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Single read tool over every object type the copilot needs, instead of one
 * class per model. A registry (below) keeps the schema flat and per-type
 * filtering explicit, rather than accepting an arbitrary dynamic filter bag.
 */
class FetchObjects implements Tool
{
    private const MODELS = [
        'contact' => Contact::class,
        'deal' => Deal::class,
        'offer' => Offer::class,
        'reservation' => Reservation::class,
        'contract' => Contract::class,
        'payment' => Payment::class,
        'delinquency' => Delinquency::class,
        'message_thread' => MessageThread::class,
    ];

    private const PERMISSIONS = [
        'contact' => Permission::ContactView,
        'deal' => Permission::DealManage,
        'offer' => Permission::OfferManage,
        'reservation' => Permission::ReservationManage,
        'contract' => Permission::ContractView,
        'payment' => Permission::PaymentView,
        'delinquency' => Permission::DelinquencyView,
        'message_thread' => Permission::InboxView,
    ];

    private const CUSTOM_PROPERTY_MODELS = [
        'contact' => Contact::class,
        'deal' => Deal::class,
        'offer' => Offer::class,
        'reservation' => Reservation::class,
        'unit' => Unit::class,
        'contract' => Contract::class,
    ];

    public function __construct(private readonly Employee $employee) {}

    public function description(): Stringable|string
    {
        return 'Search and retrieve CRM records: contacts, deals, offers, reservations, contracts, payments, '
            .'overdue/delinquency cases, message threads, or an entity\'s custom property values.';
    }

    public function handle(Request $request): Stringable|string
    {
        $objectType = $request['object_type'] ?? null;

        if ($objectType === 'custom_property') {
            return $this->fetchCustomProperties($request);
        }

        if (! isset(self::MODELS[$objectType])) {
            return json_encode([
                'success' => false,
                'error' => "Unsupported object_type '{$objectType}'.",
            ]);
        }

        $permission = self::PERMISSIONS[$objectType];

        if (! $this->employee->allowsPermission($permission)) {
            return json_encode([
                'success' => false,
                'error' => "You do not have permission to view {$objectType} records.",
            ]);
        }

        $modelClass = self::MODELS[$objectType];
        $query = $modelClass::query()->visibleTo($this->employee, $permission);

        $this->applyFilters($query, $objectType, $request);

        $limit = min((int) ($request['limit'] ?? 10), 50);
        $records = $query->latest()->limit($limit)->get();

        return json_encode([
            'success' => true,
            'object_type' => $objectType,
            'total' => $records->count(),
            'results' => $this->shape($objectType, $records),
        ]);
    }

    /** @param  Builder<*>  $query */
    private function applyFilters(Builder $query, string $objectType, Request $request): void
    {
        $search = $request['search'] ?? null;
        $contactId = $request['contact_id'] ?? null;
        $dealId = $request['deal_id'] ?? null;
        $contractId = $request['contract_id'] ?? null;
        $status = $request['status'] ?? null;

        switch ($objectType) {
            case 'contact':
                if ($search) {
                    $query->where(function ($q) use ($search) {
                        $q->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhere('company', 'like', "%{$search}%");
                    });
                }
                if ($status) {
                    $query->where('status', $status);
                }
                break;

            case 'deal':
                $query->with('contact');
                if ($search) {
                    $query->whereHas('contact', function ($q) use ($search) {
                        $q->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
                }
                if ($contactId) {
                    $query->where('contact_id', $contactId);
                }
                if ($status) {
                    $query->where('status', $status);
                }
                break;

            case 'offer':
                if ($contactId) {
                    $query->where('contact_id', $contactId);
                }
                if ($dealId) {
                    $query->where('deal_id', $dealId);
                }
                if ($status) {
                    $query->where('status', $status);
                }
                break;

            case 'reservation':
                if ($contactId) {
                    $query->where('contact_id', $contactId);
                }
                if ($dealId) {
                    $query->where('deal_id', $dealId);
                }
                if ($status) {
                    $query->where('status', $status);
                }
                break;

            case 'contract':
                if ($contactId) {
                    $query->where('contact_id', $contactId);
                }
                if ($dealId) {
                    $query->where('deal_id', $dealId);
                }
                if ($status) {
                    $query->where('status', $status);
                }
                break;

            case 'payment':
                // No direct contact_id column — join through the contract.
                if ($contractId) {
                    $query->where('contract_id', $contractId);
                }
                if ($contactId) {
                    $query->whereHas('contract', fn ($q) => $q->where('contact_id', $contactId));
                }
                break;

            case 'delinquency':
                // No direct contact_id column — join through the contract.
                if ($contractId) {
                    $query->where('contract_id', $contractId);
                }
                if ($contactId) {
                    $query->whereHas('contract', fn ($q) => $q->where('contact_id', $contactId));
                }
                break;

            case 'message_thread':
                if ($contactId) {
                    $query->where('contact_id', $contactId);
                }
                break;
        }
    }

    /**
     * @param  Collection<int, \Illuminate\Database\Eloquent\Model>  $records
     * @return list<array<string, mixed>>
     */
    private function shape(string $objectType, Collection $records): array
    {
        return $records->map(fn ($record) => match ($objectType) {
            'contact' => [
                'id' => $record->id,
                'name' => "{$record->first_name} {$record->last_name}",
                'email' => $record->email,
                'company' => $record->company,
                'status' => $record->status?->value,
            ],
            'deal' => [
                'id' => $record->id,
                'contact_id' => $record->contact_id,
                'contact_name' => $record->contact !== null ? "{$record->contact->first_name} {$record->contact->last_name}" : null,
                'status' => $record->status?->value,
                'expected_move_in' => $record->expected_move_in?->format('Y-m-d'),
                'desired_size' => $record->desired_size,
            ],
            'offer' => [
                'id' => $record->id,
                'deal_id' => $record->deal_id,
                'contact_id' => $record->contact_id,
                'status' => $record->status,
                'expires_at' => $record->expires_at?->format('Y-m-d'),
                'token' => $record->token,
            ],
            'reservation' => [
                'id' => $record->id,
                'contact_id' => $record->contact_id,
                'deal_id' => $record->deal_id,
                'unit_id' => $record->unit_id,
                'status' => $record->status?->value,
                'expires_at' => $record->expires_at?->format('Y-m-d'),
            ],
            'contract' => [
                'id' => $record->id,
                'contact_id' => $record->contact_id,
                'deal_id' => $record->deal_id,
                'status' => $record->status?->value,
            ],
            'payment' => [
                'id' => $record->id,
                'contract_id' => $record->contract_id,
                'amount' => $record->amount,
                'currency' => $record->currency,
                'method' => $record->method?->value ?? $record->method,
                'received_on' => $record->received_on?->format('Y-m-d'),
            ],
            'delinquency' => [
                'id' => $record->id,
                'contract_id' => $record->contract_id,
                'opened_on' => $record->opened_on?->format('Y-m-d'),
                'cured_on' => $record->cured_on?->format('Y-m-d'),
                'anchor_due_date' => $record->anchor_due_date?->format('Y-m-d'),
            ],
            'message_thread' => [
                'id' => $record->id,
                'contact_id' => $record->contact_id,
                'channel' => $record->channel?->value,
                'subject' => $record->subject,
                'last_message_at' => $record->last_message_at?->format('Y-m-d H:i'),
                'unread_count' => $record->unread_count,
            ],
            default => ['id' => $record->id],
        })->all();
    }

    private function fetchCustomProperties(Request $request): string
    {
        $type = $request['entity_type'] ?? null;

        if (! isset(self::CUSTOM_PROPERTY_MODELS[$type])) {
            return json_encode([
                'success' => false,
                'error' => "Unsupported entity_type '{$type}' for custom properties.",
            ]);
        }

        $entityId = $request['entity_id'] ?? null;

        if (! $entityId) {
            return json_encode([
                'success' => false,
                'error' => 'entity_id is required when object_type is custom_property.',
            ]);
        }

        $entityType = AttributeEntityType::from($type);
        $modelClass = self::CUSTOM_PROPERTY_MODELS[$type];
        $entity = $modelClass::query()->find($entityId);

        if ($entity === null) {
            return json_encode([
                'success' => false,
                'error' => "No {$type} found with that ID.",
            ]);
        }

        if (! $this->employee->allowsPermission($entityType->viewPermission(), SubjectSite::for($entity))) {
            return json_encode([
                'success' => false,
                'error' => "You do not have permission to view custom properties on this {$type}.",
            ]);
        }

        $definitionIds = AttributeDefinition::query()
            ->where('entity_type', $entityType)
            ->pluck('id');

        $values = AttributeValue::query()
            ->with(['definition.options', 'options'])
            ->where('entity_id', $entityId)
            ->whereIn('definition_id', $definitionIds)
            ->get();

        return json_encode([
            'success' => true,
            'object_type' => 'custom_property',
            'total' => $values->count(),
            'results' => AttributeValueResource::collection($values)->resolve(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'object_type' => $schema->string()
                ->description('The kind of record to fetch')
                ->enum([...array_keys(self::MODELS), 'custom_property'])
                ->required(),
            'search' => $schema->string()
                ->description('Free-text search (name/email/company) — only applies to contact and deal')
                ->nullable(),
            'contact_id' => $schema->integer()
                ->description('Filter by contact ID — applies to deal, offer, reservation, contract, payment, delinquency, message_thread')
                ->nullable(),
            'deal_id' => $schema->integer()
                ->description('Filter by deal ID — applies to offer, reservation, contract')
                ->nullable(),
            'contract_id' => $schema->integer()
                ->description('Filter by contract ID — applies to payment, delinquency')
                ->nullable(),
            'status' => $schema->string()
                ->description('Filter by status — applies to contact, deal, offer, reservation, contract')
                ->nullable(),
            'entity_type' => $schema->string()
                ->description('Required when object_type is custom_property: the type of record the custom attribute belongs to')
                ->enum(array_keys(self::CUSTOM_PROPERTY_MODELS))
                ->nullable(),
            'entity_id' => $schema->integer()
                ->description('Required when object_type is custom_property: ID of the record the custom attribute belongs to')
                ->nullable(),
            'limit' => $schema->integer()
                ->description('Maximum number of records to return (max 50)')
                ->default(10)
                ->nullable(),
        ];
    }
}
