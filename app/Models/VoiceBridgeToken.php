<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\VoiceBridgeTokenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Per-number Vocal Bridge credential. Path token identifies the row; secret(s)
 * authenticate the header. Secrets are encrypted at rest and never serialized.
 *
 * @property int $id
 * @property string $token
 * @property string $secret
 * @property string|null $secret_previous
 * @property int $site_id
 * @property string|null $label
 * @property Carbon|null $revoked_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Site $site
 */
class VoiceBridgeToken extends Model
{
    /** @use HasFactory<VoiceBridgeTokenFactory> */
    use HasFactory;

    protected $fillable = [
        'token',
        'secret',
        'secret_previous',
        'site_id',
        'label',
        'revoked_at',
    ];

    protected $hidden = [
        'secret',
        'secret_previous',
    ];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'secret_previous' => 'encrypted',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (VoiceBridgeToken $token): void {
            if ($token->token === null || $token->token === '') {
                $token->token = Str::random(40);
            }
        });
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return HasMany<VoiceSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(VoiceSession::class);
    }
}
