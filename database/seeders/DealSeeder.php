<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\DealStatus;
use App\Enums\StorageReason;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Offer;
use App\Models\OfferOption;
use App\Models\UnitClassRate;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * CRM pipeline seed (deals / offers / options).
 * Reservation holds and occupancy facts are owned by OccupancySeeder —
 * this seeder must not create reservations that bypass the guards.
 */
class DealSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $rates = UnitClassRate::query()
            ->with(['unitClass', 'price', 'site'])
            ->get();

        Contact::query()->each(function (Contact $contact) use ($rates): void {
            $deals = Deal::factory()
                ->count(fake()->numberBetween(1, 2))
                ->create([
                    'contact_id'     => $contact->id,
                    'storage_reason' => fake()->randomElement(StorageReason::cases())->value,
                ]);

            foreach ($deals as $deal) {
                if (! fake()->boolean(60)) {
                    continue;
                }

                $offerStatus = $this->offerStatusForDeal($deal->status);

                $offer = Offer::query()->create([
                    'deal_id'         => $deal->id,
                    'contact_id'      => $contact->id,
                    'token'           => Str::random(32),
                    'status'          => $offerStatus,
                    'expires_at'      => now()->addDays(30),
                    'sent_at'         => $this->sentAt($offerStatus),
                    'first_viewed_at' => $this->firstViewedAt($offerStatus),
                    'accepted_at'     => $this->acceptedAt($offerStatus),
                ]);

                $availableRates = $rates->values()->shuffle();
                $optionCount = fake()->numberBetween(1, min(3, $availableRates->count()));
                $selectedRates = $availableRates->take($optionCount);

                foreach ($selectedRates->values() as $index => $rate) {
                    OfferOption::query()->create([
                        'offer_id'           => $offer->id,
                        'unit_class_rate_id' => $rate->id,
                        'unit_id'            => null,
                        'label'              => $rate->unitClass->label,
                        'description'        => fake()->optional(0.5)->sentence(),
                        'display_order'      => $index,
                        'selected_at'        => ($offerStatus === 'accepted' && $index === 0) ? now() : null,
                    ]);
                }
            }
        });
    }

    private function offerStatusForDeal(DealStatus $dealStatus): string
    {
        return match ($dealStatus) {
            DealStatus::OfferSent => 'sent',
            DealStatus::OfferViewed, DealStatus::Negotiating => 'viewed',
            DealStatus::ClosedWon => 'accepted',
            DealStatus::ClosedLost, DealStatus::Unresponsive => fake()->randomElement(['sent', 'viewed']),
            default => 'draft',
        };
    }

    private function sentAt(string $offerStatus): ?\DateTimeInterface
    {
        if (in_array($offerStatus, ['sent', 'viewed', 'accepted'], true)) {
            return now()->subDays(fake()->numberBetween(5, 20));
        }

        return null;
    }

    private function firstViewedAt(string $offerStatus): ?\DateTimeInterface
    {
        if (in_array($offerStatus, ['viewed', 'accepted'], true)) {
            return now()->subDays(fake()->numberBetween(3, 4));
        }

        return null;
    }

    private function acceptedAt(string $offerStatus): ?\DateTimeInterface
    {
        if ($offerStatus === 'accepted') {
            return now()->subDays(fake()->numberBetween(1, 2));
        }

        return null;
    }
}
