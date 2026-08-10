<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Auth\Permission;

enum AttributeEntityType: string
{
    case Contact = 'contact';
    case Deal = 'deal';
    case Offer = 'offer';
    case Reservation = 'reservation';
    case Unit = 'unit';
    case Contract = 'contract';

    public function activityChannel(): LogChannel
    {
        return $this === self::Unit ? LogChannel::Facility : LogChannel::Crm;
    }

    /**
     * Permission required to read this entity type's custom attribute values.
     * Reuses the entity's own view ability — custom properties are just fields
     * on the entity, not a separate settings-gated concern.
     */
    public function viewPermission(): Permission
    {
        return match ($this) {
            self::Contact => Permission::ContactView,
            self::Deal => Permission::DealManage,
            self::Offer => Permission::OfferManage,
            self::Reservation => Permission::ReservationManage,
            self::Unit => Permission::UnitView,
            self::Contract => Permission::ContractView,
        };
    }

    /**
     * Permission required to set this entity type's custom attribute values.
     * Contract has no generic "manage" permission, so this reuses ContractSign —
     * the same choice the panel's overview-edit permission map already makes.
     */
    public function managePermission(): Permission
    {
        return match ($this) {
            self::Contact => Permission::ContactManage,
            self::Deal => Permission::DealManage,
            self::Offer => Permission::OfferManage,
            self::Reservation => Permission::ReservationManage,
            self::Unit => Permission::UnitManage,
            self::Contract => Permission::ContractSign,
        };
    }
}
