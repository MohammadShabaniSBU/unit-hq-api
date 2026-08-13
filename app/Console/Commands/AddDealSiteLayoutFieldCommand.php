<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AttributeEntityType;
use App\Enums\LayoutFieldType;
use App\Models\AttributeGroup;
use App\Models\LayoutField;
use Illuminate\Console\Command;

class AddDealSiteLayoutFieldCommand extends Command
{
    protected $signature = 'layout:add-deal-site';

    protected $description = 'Place the read-only Site native field on the Deal details overview card (idempotent)';

    public function handle(): int
    {
        $entityType = AttributeEntityType::Deal;

        $alreadyPlaced = LayoutField::query()
            ->where('entity_type', $entityType)
            ->where('native_field_key', 'site_id')
            ->exists();

        if ($alreadyPlaced) {
            $this->info('Deal site_id layout field already exists — nothing to do.');

            return self::SUCCESS;
        }

        $group = AttributeGroup::query()
            ->where('entity_type', $entityType)
            ->where('key', 'deal_details')
            ->first();

        if ($group === null) {
            $this->warn('Deal details group not found. Run DefaultAttributeLayoutSeeder first.');

            return self::FAILURE;
        }

        $statusField = LayoutField::query()
            ->where('group_id', $group->id)
            ->where('native_field_key', 'status')
            ->first();

        if ($statusField !== null) {
            $insertAt = $statusField->display_order + 1;

            LayoutField::query()
                ->where('group_id', $group->id)
                ->where('display_order', '>=', $insertAt)
                ->increment('display_order');
        } else {
            $insertAt = ((int) LayoutField::query()
                ->where('group_id', $group->id)
                ->max('display_order')) + 1;
        }

        LayoutField::query()->create([
            'group_id' => $group->id,
            'entity_type' => $entityType,
            'display_order' => $insertAt,
            'field_type' => LayoutFieldType::Native,
            'native_field_key' => 'site_id',
            'attribute_definition_id' => null,
        ]);

        $this->info("Added site_id to Deal details at display_order {$insertAt}.");

        return self::SUCCESS;
    }
}
