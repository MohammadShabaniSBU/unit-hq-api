<?php

namespace Database\Seeders;

use App\Enums\StorageReason;
use App\Models\Contact;
use App\Models\Deal;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DealSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        Contact::query()->each(function (Contact $contact) {
            Deal::factory()
                ->count(fake()->numberBetween(1, 2))
                ->create([
                    'contact_id'     => $contact->id,
                    'storage_reason' => fake()->randomElement(StorageReason::cases())->value,
                ]);
        });
    }
}
