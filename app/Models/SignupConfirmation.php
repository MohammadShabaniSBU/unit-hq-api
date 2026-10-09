<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Double opt-in token. The raw token is emailed once; only the hash is stored.
 *
 * @property int $id
 * @property int $signup_form_id
 * @property int $contact_id
 * @property string $token_hash
 * @property list<int> $consent_event_ids
 * @property Carbon $expires_at
 * @property Carbon|null $confirmed_at
 * @property Carbon $created_at
 */
class SignupConfirmation extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'signup_form_id',
        'contact_id',
        'token_hash',
        'consent_event_ids',
        'expires_at',
        'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'consent_event_ids' => 'array',
            'expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** @return BelongsTo<SignupForm, $this> */
    public function form(): BelongsTo
    {
        return $this->belongsTo(SignupForm::class, 'signup_form_id');
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
