<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccessGrantState;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Cache of what the reconciliation engine (S15-02) has projected to the provider.
 * Never a source of truth — DesiredAccess computes desire; this records apply state.
 *
 * @property int               $id
 * @property int               $access_point_id
 * @property int               $contact_id
 * @property int               $contract_id
 * @property string|null       $provider_grant_id
 * @property AccessGrantState  $state
 * @property string|null       $last_error
 * @property string|null       $pin
 * @property Carbon|null       $pin_shown_at
 * @property Carbon|null       $applied_at
 * @property Carbon|null       $revoked_at
 * @property Carbon            $created_at
 * @property Carbon            $updated_at
 *
 * @property-read AccessPoint  $accessPoint
 * @property-read Contact      $contact
 * @property-read Contract     $contract
 */
class AccessGrant extends Model
{
    use HasFactory;

    protected $fillable = [
        'access_point_id',
        'contact_id',
        'contract_id',
        'provider_grant_id',
        'state',
        'last_error',
        'pin',
        'pin_shown_at',
        'applied_at',
        'revoked_at',
    ];

    protected $hidden = [
        'pin',
    ];

    protected function casts(): array
    {
        return [
            'state' => AccessGrantState::class,
            'pin' => 'encrypted',
            'pin_shown_at' => 'datetime',
            'applied_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AccessPoint, AccessGrant> */
    public function accessPoint(): BelongsTo
    {
        return $this->belongsTo(AccessPoint::class);
    }

    /** @return BelongsTo<Contact, AccessGrant> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return BelongsTo<Contract, AccessGrant> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * Store a PIN from a provider grant. Never log the plaintext.
     */
    public function storePin(string $pin): void
    {
        $this->forceFill([
            'pin' => $pin,
            'pin_shown_at' => null,
        ])->save();
    }

    /**
     * Reveal the PIN once to an operator. Subsequent calls return null.
     */
    public function revealPinOnce(): ?string
    {
        if ($this->pin === null || $this->pin === '') {
            return null;
        }

        if ($this->pin_shown_at !== null) {
            return null;
        }

        $pin = $this->pin;
        $this->forceFill(['pin_shown_at' => now()])->save();

        return $pin;
    }

    public function hasPin(): bool
    {
        return $this->pin !== null && $this->pin !== '';
    }

    public function pinWasShown(): bool
    {
        return $this->pin_shown_at !== null;
    }
}

