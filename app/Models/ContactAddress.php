<?php

namespace App\Models;

use App\Enums\ContactAddressType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Structured address for a contact (home, work, billing, other).
 *
 * @property int                $id
 * @property int                $contact_id
 * @property ContactAddressType $type
 * @property string|null        $line1
 * @property string|null        $line2
 * @property string|null        $city
 * @property string|null        $state
 * @property string|null        $postal_code
 * @property int|null           $country_id
 * @property string|null        $label
 * @property bool               $is_primary
 * @property Carbon             $created_at
 * @property Carbon             $updated_at
 *
 * @property-read Contact       $contact
 * @property-read Country|null  $country
 */
class ContactAddress extends TenantModel
{
    use HasFactory;

    protected $fillable = [
        'contact_id',
        'type',
        'line1',
        'line2',
        'city',
        'state',
        'postal_code',
        'country_id',
        'label',
        'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'type'       => ContactAddressType::class,
            'is_primary' => 'boolean',
        ];
    }

    /** @return BelongsTo<Contact, ContactAddress> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return BelongsTo<Country, ContactAddress> */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }
}
