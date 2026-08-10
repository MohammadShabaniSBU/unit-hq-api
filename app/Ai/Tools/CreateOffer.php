<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Models\Employee;
use App\Models\Offer;
use App\Models\Unit;
use App\Support\Auth\Permission;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class CreateOffer implements Tool, Approvable
{
    use InteractsWithApprovals;

    public function __construct(private readonly Employee $employee) {}

    public function description(): Stringable|string
    {
        return 'Create a new offer for a deal, optionally with one or more pricing options the contact can choose between.';
    }

    public function handle(Request $request): Stringable|string
    {
        if (! $this->employee->allowsPermission(Permission::OfferManage)) {
            return json_encode([
                'success' => false,
                'error' => 'You do not have permission to create offers.',
            ]);
        }

        $options = $request['options'] ?? [];

        $offer = DB::transaction(function () use ($request, $options) {
            $attributes = [
                'deal_id' => $request['deal_id'],
                'contact_id' => $request['contact_id'],
                'token' => Str::random(64),
                'expires_at' => $request['expires_at'],
            ];

            // Omit rather than pass null — the column has a DB-level default
            // ('draft') that only applies when the key is absent from the insert.
            if ($request->has('status') && $request['status']) {
                $attributes['status'] = $request['status'];
            }

            $offer = Offer::query()->create($attributes);

            foreach ($options as $optionData) {
                $optionData['unit_id'] = Unit::resolveUnitIdForRate((int) $optionData['unit_class_rate_id']);
                $offer->options()->create($optionData);
            }

            return $offer;
        });

        return json_encode([
            'success' => true,
            'message' => 'Offer created successfully.',
            'offer_id' => $offer->id,
            'token' => $offer->token,
            'status' => $offer->status,
            'expires_at' => $offer->expires_at?->format('Y-m-d'),
            'option_count' => count($options),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'deal_id' => $schema->integer()
                ->description('ID of the deal this offer belongs to')
                ->required(),
            'contact_id' => $schema->integer()
                ->description('ID of the contact receiving this offer')
                ->required(),
            'expires_at' => $schema->string()
                ->description('Offer expiry date (YYYY-MM-DD format)')
                ->required(),
            'status' => $schema->string()
                ->description('Offer status')
                ->enum(Offer::STATUSES)
                ->nullable(),
            'options' => $schema->array()
                ->items($schema->object([
                    'unit_class_rate_id' => $schema->integer()
                        ->description('ID of the unit class rate for this pricing option')
                        ->required(),
                    'label' => $schema->string()
                        ->description('Label shown to the contact for this option')
                        ->required(),
                    'description' => $schema->string()
                        ->description('Longer description of this option')
                        ->nullable(),
                    'display_order' => $schema->integer()
                        ->description('Display order of this option, starting at 0')
                        ->required(),
                    'discount_id' => $schema->integer()
                        ->description('ID of a discount to apply to this option')
                        ->nullable(),
                ]))
                ->description('Pricing options the contact can choose between')
                ->nullable(),
        ];
    }
}
