<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Comms;

use Database\Seeders\Demo\Crowd\DemoRng;

/**
 * Spanish-weighted inbound bodies + attachment stubs for demo texture.
 */
final class ContentLibrary
{
    /** @var list<string> */
    private const EMAIL_BODIES = [
        'Hola, ¿tienen disponibilidad cerca del aeropuerto?',
        'Buenos días, me interesa un trastero de unos 4 m².',
        '¿Pueden enviarme la oferta otra vez? No la encuentro.',
        'Gracias por la info — ¿el precio incluye seguro?',
        'Hola, adjunto mi DNI para el contrato.',
        '¿Se puede pagar por transferencia SEPA?',
        'Necesito acceso el sábado por la mañana, ¿es posible?',
        'El código de la puerta no me funciona esta mañana.',
        'Prometo pagar el viernes sin falta.',
        '¿Pueden bajar un poco el precio si firmo hoy?',
        'Thanks — still comparing two sizes, will decide soon.',
        'Hola, ¿hacen mudanza o solo alquiler de unidades?',
        'Recibí un SMS de cobro — ¿pueden confirmar el importe?',
        'Quiero ampliar a una unidad más grande el mes que viene.',
        'Por favor, envíenme el enlace de pago otra vez.',
        'He dejado unas cajas mal etiquetadas, disculpen.',
        '¿Hay cámaras en el pasillo de la planta 2?',
        'Buenas, mi compañero también necesita acceso.',
        'Cancelen la visita de mañana, gracias.',
        'Perfecto, firmamos la oferta esta tarde.',
    ];

    /** @var list<string> */
    private const SMS_BODIES = [
        'Hola, ¿tienen sitio libre?',
        'Ok, pago el viernes',
        '¿Me pasan el enlace?',
        'Gracias!',
        'No puedo hoy, mañana sí',
        'Unit too small — options?',
        'Recibido, gracias',
        '¿Horario de apertura?',
        'Código no funciona',
        'Llamadme porfa',
        'Still interested',
        'Pago hecho ya',
        '¿Precio SS4?',
        'Necesito factura',
        'Visita ok el jueves',
        'No me llega el email',
        'Prometo poner al día',
        'Cambio de teléfono: nuevo',
        '¿WhatsApp mejor?',
        'Listo, firmado',
    ];

    /** @var list<string> */
    private const WHATSAPP_BODIES = [
        'Hola! ¿Siguen con la oferta?',
        'Sí, me interesa el enlace de pago',
        'Perfecto, gracias 👍',
        '¿Puedo pasar hoy a ver la unidad?',
        'El candado no abre',
        'Pago prometido para el lunes',
        '¿Tienen más grande?',
        'Ok, firmo mañana',
        'Necesito el contrato en PDF',
        'Buenos días, soy el inquilino de SS-12',
        '¿Me confirman el recibo?',
        'Gracias por la ayuda',
        'Sigo mirando precios',
        'Ya pagué por la app',
        '¿Horario del sábado?',
        'Pueden llamarme?',
        'Todo bien, sin novedad',
        'Quiero dar de baja el seguro',
        'Adjunto foto del daño en el pasillo',
        'Listo, hecho',
    ];

    /** @var list<string> */
    private const OFFER_REPLIES = [
        'Gracias por la oferta — ¿pueden mejorar un poco el precio?',
        'Me gusta la opción 1, pero ¿hay algo un poco más barato?',
        'Recibido. ¿El depósito se puede fraccionar?',
        'Interesante. ¿Incluye el primer mes de seguro?',
        'Thanks for the offer — can you hold the unit until Friday?',
        '¿Pueden enviarme una segunda opción más pequeña?',
    ];

    public function __construct(private readonly DemoRng $rng) {}

    public function emailBody(string $intent = 'general'): string
    {
        $pool = $intent === 'offer_reply' ? self::OFFER_REPLIES : self::EMAIL_BODIES;
        $base = $this->rng->pick($pool);

        return $base.' (ref '.$this->rng->int(1000, 9999).')';
    }

    public function smsBody(): string
    {
        return $this->rng->pick(self::SMS_BODIES).' #'.$this->rng->int(10, 99);
    }

    public function whatsappBody(): string
    {
        return $this->rng->pick(self::WHATSAPP_BODIES);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function dniAttachment(): array
    {
        return [[
            'Name' => 'dni-scan.pdf',
            'Content' => base64_encode('%PDF-1.4 demo DNI placeholder'),
            'ContentType' => 'application/pdf',
            'ContentLength' => 32,
            'ContentID' => '',
        ]];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function photoAttachment(): array
    {
        // Minimal 1x1 PNG
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        );

        return [[
            'Name' => 'pasillo.jpg',
            'Content' => base64_encode($png !== false ? $png : 'img'),
            'ContentType' => 'image/png',
            'ContentLength' => 68,
            'ContentID' => '',
        ]];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function oversizeAttachment(): array
    {
        // Compact payload with an oversized ContentLength so the inbound path
        // exercises the size gate without allocating multi‑MB in the seeder.
        $blob = str_repeat('X', 4096);

        return [[
            'Name' => 'huge-inventory.bin',
            'Content' => base64_encode($blob),
            'ContentType' => 'application/octet-stream',
            'ContentLength' => 25_000_000,
            'ContentID' => '',
        ]];
    }
}
