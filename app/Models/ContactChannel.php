<?php

namespace App\Models;

use App\Enums\ContactChannelType;
use App\Enums\LogChannel;
use App\Models\Concerns\LogsDirtyActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Communication channel for a contact (phone, SMS, WhatsApp, secondary email).
 * Portal authentication uses contacts.email — not rows in this table.
 *
 * @property int                 $id
 * @property int                 $contact_id
 * @property ContactChannelType  $type
 * @property string              $value
 * @property string|null         $label
 * @property bool                $is_primary
 * @property bool                $opted_in
 * @property Carbon              $created_at
 * @property Carbon              $updated_at
 *
 * @property-read Contact $contact
 */
class ContactChannel extends Model
{
    use HasFactory, LogsDirtyActivity;

    protected function activityLogChannel(): LogChannel
    {
        return LogChannel::Crm;
    }

    protected $fillable = [
        'contact_id',
        'type',
        'value',
        'label',
        'is_primary',
        'opted_in',
    ];

    protected function casts(): array
    {
        return [
            'type'       => ContactChannelType::class,
            'is_primary' => 'boolean',
            'opted_in'   => 'boolean',
        ];
    }

    /** @return BelongsTo<Contact, ContactChannel> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
