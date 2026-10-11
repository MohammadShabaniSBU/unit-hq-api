<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Communications\Channel;
use App\Support\Marketing\ConsentSource;
use App\Support\Marketing\SignupFormPurpose;
use Database\Factories\SignupFormFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Public marketing signup form. Membership is consent events, never a stored count.
 *
 * @property int $id
 * @property string $public_token
 * @property string $name
 * @property int|null $site_id
 * @property list<int> $subscription_list_ids
 * @property SignupFormPurpose $purpose
 * @property array<string, mixed>|null $enquiry_fields
 * @property string $channel
 * @property list<array{key: string, required: bool}> $fields
 * @property string $consent_text_key
 * @property bool $double_opt_in
 * @property int|null $confirmation_template_family_id
 * @property string|null $success_redirect_url
 * @property list<string>|null $allowed_origins
 * @property Carbon|null $archived_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class SignupForm extends Model
{
    /** @use HasFactory<SignupFormFactory> */
    use HasFactory;

    protected $fillable = [
        'public_token',
        'name',
        'purpose',
        'enquiry_fields',
        'site_id',
        'subscription_list_ids',
        'channel',
        'fields',
        'consent_text_key',
        'double_opt_in',
        'confirmation_template_family_id',
        'success_redirect_url',
        'allowed_origins',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'purpose' => SignupFormPurpose::class,
            'enquiry_fields' => 'array',
            'subscription_list_ids' => 'array',
            'fields' => 'array',
            'double_opt_in' => 'boolean',
            'allowed_origins' => 'array',
            'archived_at' => 'datetime',
        ];
    }

    public static function mintPublicToken(): string
    {
        do {
            $token = Str::random(48);
        } while (self::query()->where('public_token', $token)->exists());

        return $token;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** @param  Builder<static>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /**
     * Submissions are form-sourced consent events that name this form.
     * One submit writes one event per list.
     */
    public function submissionsCount(): int
    {
        return ConsentEvent::query()
            ->where('source', ConsentSource::Form->value)
            ->where('evidence->form_id', $this->id)
            ->count();
    }

    /**
     * A submission is a form consent event, a touch, or an enquiry activity
     * that names this form. Purpose cannot change after one exists.
     */
    public function hasSubmissions(): bool
    {
        if ($this->submissionsCount() > 0) {
            return true;
        }

        if (MarketingTouch::query()->where('signup_form_id', $this->id)->exists()) {
            return true;
        }

        return Activity::query()
            ->whereIn('description', ['deal.created', 'deal.web_enquiry'])
            ->where('properties->signup_form_id', $this->id)
            ->exists();
    }

    /**
     * Deals opened by this form, plus later enquiries matched onto an open deal.
     * deal.created is also written on the contact, so only the deal subject counts.
     */
    public function enquiryCount(): int
    {
        return Activity::query()
            ->where('properties->signup_form_id', $this->id)
            ->where(function (Builder $query): void {
                $query->where(function (Builder $created): void {
                    $created->where('description', 'deal.created')
                        ->where('subject_type', 'deal');
                })->orWhere('description', 'deal.web_enquiry');
            })
            ->count();
    }

    public function marketingChannel(): Channel
    {
        return Channel::tryFrom($this->channel) ?? Channel::Email;
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<TemplateFamily, $this> */
    public function confirmationTemplate(): BelongsTo
    {
        return $this->belongsTo(TemplateFamily::class, 'confirmation_template_family_id');
    }

    /** @return HasMany<SignupConfirmation, $this> */
    public function confirmations(): HasMany
    {
        return $this->hasMany(SignupConfirmation::class);
    }
}
