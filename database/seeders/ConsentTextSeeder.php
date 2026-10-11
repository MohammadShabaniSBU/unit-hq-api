<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ConsentText;
use Illuminate\Database\Seeder;

/**
 * Version 1 of the advertising consent text. Later versions are new rows
 * from Settings → Marketing → Consent texts.
 */
class ConsentTextSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->texts() as $text) {
            ConsentText::query()->firstOrCreate(
                [
                    'key' => $text['key'],
                    'version' => $text['version'],
                    'locale' => $text['locale'],
                ],
                $text,
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function texts(): array
    {
        return [
            [
                'key' => 'advertising',
                'version' => 1,
                'locale' => 'en',
                'body' => 'I agree that my contact details may be used to measure advertising and to personalise ads, including sharing a hashed email or phone number with the advertising platforms when I enquire or move in.',
                'created_by' => null,
            ],
            [
                'key' => 'advertising',
                'version' => 1,
                'locale' => 'es',
                'body' => 'Acepto que mis datos de contacto se usen para medir la publicidad y personalizar anuncios, incluido compartir un email o teléfono con hash con las plataformas publicitarias cuando consulte o me mude.',
                'created_by' => null,
            ],
            [
                'key' => 'advertising',
                'version' => 1,
                'locale' => 'fr',
                'body' => 'J’accepte que mes coordonnées soient utilisées pour mesurer la publicité et personnaliser les annonces, y compris le partage d’un e-mail ou d’un numéro de téléphone haché avec les régies lorsque je demande un devis ou emménage.',
                'created_by' => null,
            ],
        ];
    }
}
