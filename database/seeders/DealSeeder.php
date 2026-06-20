<?php

namespace Database\Seeders;

use App\Enums\DealStatus;
use App\Enums\ReservationStatus;
use App\Enums\StorageReason;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Offer;
use App\Models\OfferOption;
use App\Models\Reservation;
use App\Models\Unit;
use App\Models\UnitClass;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DealSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $unitClassesWithPrice = UnitClass::query()
            ->whereNotNull('current_price_id')
            ->with('currentPrice')
            ->get();

        Contact::query()->each(function (Contact $contact) use ($unitClassesWithPrice) {
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

                $offer = Offer::create([
                    'deal_id'         => $deal->id,
                    'contact_id'      => $contact->id,
                    'token'           => Str::random(32),
                    'status'          => $offerStatus,
                    'expires_at'      => now()->addDays(30),
                    'sent_at'         => $this->sentAt($offerStatus),
                    'first_viewed_at' => $this->firstViewedAt($offerStatus),
                    'accepted_at'     => $this->acceptedAt($offerStatus),
                ]);

                $optionCount = fake()->numberBetween(1, min(3, $unitClassesWithPrice->count()));
                $selectedClasses = $unitClassesWithPrice->shuffle()->take($optionCount);
                $selectedOption = null;

                foreach ($selectedClasses->values() as $index => $unitClass) {
                    $isFirst = $index === 0;

                    $option = OfferOption::create([
                        'offer_id'      => $offer->id,
                        'unit_class_id' => $unitClass->id,
                        'price_id'      => $unitClass->current_price_id,
                        'label'         => $unitClass->label,
                        'description'   => fake()->optional(0.5)->sentence(),
                        'display_order' => $index,
                        'selected_at'   => ($offerStatus === 'accepted' && $isFirst) ? now() : null,
                    ]);

                    if ($offerStatus === 'accepted' && $isFirst) {
                        $selectedOption = $option;
                        $selectedUnitClass = $unitClass;
                    }
                }

                if ($offerStatus === 'accepted' && $selectedOption !== null) {
                    $unit = Unit::query()
                        ->where('unit_class_id', $selectedUnitClass->id)
                        ->inRandomOrder()
                        ->first();

                    if ($unit) {
                        Reservation::create([
                            'unit_id'         => $unit->id,
                            'contact_id'      => $contact->id,
                            'deal_id'         => $deal->id,
                            'offer_option_id' => $selectedOption->id,
                            'status'          => ReservationStatus::Confirmed,
                            'expires_at'      => now()->addDays(14),
                        ]);
                    }
                }
            }
        });
    }

    private function offerStatusForDeal(DealStatus $dealStatus): string
    {
        return match ($dealStatus) {
            DealStatus::OfferSent                        => 'sent',
            DealStatus::OfferViewed, DealStatus::Negotiating => 'viewed',
            DealStatus::ClosedWon                        => 'accepted',
            DealStatus::ClosedLost, DealStatus::Unresponsive => fake()->randomElement(['sent', 'viewed']),
            default                                      => 'draft',
        };
    }

    private function sentAt(string $offerStatus): ?\DateTimeInterface
    {
        if (in_array($offerStatus, ['sent', 'viewed', 'accepted'])) {
            return now()->subDays(fake()->numberBetween(5, 20));
        }

        return null;
    }

    private function firstViewedAt(string $offerStatus): ?\DateTimeInterface
    {
        if (in_array($offerStatus, ['viewed', 'accepted'])) {
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
