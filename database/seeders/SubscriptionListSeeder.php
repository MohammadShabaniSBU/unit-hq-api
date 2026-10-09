<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\SubscriptionList;
use Illuminate\Database\Seeder;

/**
 * System lists every deployment needs before a campaign exists.
 * Idempotent on key. Task 02 adds list CRUD on this table; do not recreate it.
 */
class SubscriptionListSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->lists() as $list) {
            SubscriptionList::query()->firstOrCreate(
                ['key' => $list['key']],
                $list,
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lists(): array
    {
        return [
            [
                'key' => 'promotions',
                'labels' => [
                    'en' => 'Offers & promotions',
                    'es' => 'Ofertas y promociones',
                    'fr' => 'Offres et promotions',
                ],
                'descriptions' => [
                    'en' => 'Offers and promotions for similar storage services.',
                    'es' => 'Ofertas y promociones de servicios de almacenamiento similares.',
                    'fr' => 'Offres et promotions pour des services de stockage similaires.',
                ],
                'kind' => 'promotions',
                'channels' => ['email', 'sms'],
                'site_id' => null,
                'is_public' => true,
                'requires_double_opt_in' => true,
                'is_system' => true,
                'archived_at' => null,
            ],
            [
                'key' => 'newsletter',
                'labels' => [
                    'en' => 'Newsletter',
                    'es' => 'Boletín',
                    'fr' => 'Newsletter',
                ],
                'descriptions' => [
                    'en' => 'Occasional news from the storage company.',
                    'es' => 'Noticias ocasionales de la empresa de almacenamiento.',
                    'fr' => 'Actualités occasionnelles de l’entreprise de stockage.',
                ],
                'kind' => 'newsletter',
                'channels' => ['email'],
                'site_id' => null,
                'is_public' => true,
                'requires_double_opt_in' => true,
                'is_system' => true,
                'archived_at' => null,
            ],
        ];
    }
}
