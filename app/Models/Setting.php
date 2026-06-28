<?php

namespace App\Models;

use App\Enums\SettingKey;
use App\Settings\BillingSettings;
use App\Settings\GeneralSettings;
use App\Settings\LeasingSettings;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Carbon;

/**
 * Tenant-scoped settings store. Each row holds one setting type with a typed JSON payload.
 *
 * @property int         $id
 * @property SettingKey  $name
 * @property array       $payload
 * @property Carbon      $created_at
 * @property Carbon      $updated_at
 */
class Setting extends TenantModel
{
    use HasFactory;

    protected $fillable = [
        'name',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'name'    => SettingKey::class,
            'payload' => 'array',
        ];
    }

    public static function general(): GeneralSettings
    {
        $setting = static::where('name', SettingKey::General)->first();

        return $setting
            ? GeneralSettings::fromArray($setting->payload)
            : GeneralSettings::default();
    }

    public static function setGeneral(GeneralSettings $settings): void
    {
        static::updateOrCreate(
            ['name' => SettingKey::General->value],
            ['payload' => $settings->toArray()]
        );
    }

    public static function billing(): BillingSettings
    {
        $setting = static::where('name', SettingKey::Billing)->first();

        return $setting
            ? BillingSettings::fromArray($setting->payload)
            : BillingSettings::default();
    }

    public static function setBilling(BillingSettings $settings): void
    {
        static::updateOrCreate(
            ['name' => SettingKey::Billing->value],
            ['payload' => $settings->toArray()]
        );
    }

    public static function leasing(): LeasingSettings
    {
        $setting = static::where('name', SettingKey::Leasing)->first();

        return $setting
            ? LeasingSettings::fromArray($setting->payload)
            : LeasingSettings::default();
    }

    public static function setLeasing(LeasingSettings $settings): void
    {
        static::updateOrCreate(
            ['name' => SettingKey::Leasing->value],
            ['payload' => $settings->toArray()]
        );
    }
}
