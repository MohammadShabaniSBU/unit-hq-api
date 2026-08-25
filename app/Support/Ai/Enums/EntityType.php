<?php

declare(strict_types=1);

namespace App\Support\Ai\Enums;

/**
 * Identity types a tool result may name.
 *
 * Morph-overlapping cases use the Relation::morphMap alias verbatim.
 * Non-morph additions (not on the morph map) are an explicit closed list:
 * site, unit_class, discount, size_guide.
 */
enum EntityType: string
{
    case Site = 'site';
    case UnitClass = 'unit_class';
    case Contact = 'contact';
    case Deal = 'deal';
    case Offer = 'offer';
    case Reservation = 'reservation';
    case Contract = 'contract';
    case Discount = 'discount';
    case SizeGuide = 'size_guide';
    case Task = 'task';
    case Note = 'note';
    case Invoice = 'invoice';
    case Unit = 'unit';

    /**
     * Cases that are not Relation::morphMap aliases.
     *
     * @return list<self>
     */
    public static function nonMorphAdditions(): array
    {
        return [
            self::Site,
            self::UnitClass,
            self::Discount,
            self::SizeGuide,
        ];
    }
}
