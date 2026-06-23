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
use App\Models\UnitClassRate;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DealSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $latestRateIds = UnitClassRate::query()
            ->selectRaw('MAX(id) as id')
            ->groupBy('unit_class_id')
            ->pluck('id');

        $ratesByUnitClass = UnitClassRate::query()
            ->with(['unitClass', 'price'])
            ->whereIn('id', $latestRateIds)
            ->get()
            ->keyBy('unit_class_id');

        Contact::query()->each(function (Contact $contact) use ($ratesByUnitClass) {
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

                $availableRates = $ratesByUnitClass->values()->shuffle();
                $optionCount = fake()->numberBetween(1, min(3, $availableRates->count()));
                $selectedRates = $availableRates->take($optionCount);
                $selectedOption = null;
                $selectedRate = null;

                foreach ($selectedRates->values() as $index => $rate) {
                    $isFirst = $index === 0;

                    $option = OfferOption::create([
                        'offer_id'           => $offer->id,
                        'unit_class_rate_id' => $rate->id,
                        'label'              => $rate->unitClass->label,
                        'description'        => fake()->optional(0.5)->sentence(),
                        'display_order'      => $index,
                        'selected_at'        => ($offerStatus === 'accepted' && $isFirst) ? now() : null,
                    ]);

                    if ($offerStatus === 'accepted' && $isFirst) {
                        $selectedOption = $option;
                        $selectedRate = $rate;
                    }
                }

                if ($offerStatus === 'accepted' && $selectedOption !== null && $selectedRate !== null) {
                    $unit = Unit::query()
                        ->where('unit_class_id', $selectedRate->unit_class_id)
                        ->where('site_id', $selectedRate->site_id)
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
