<?php

declare(strict_types=1);

namespace App\Support\Communications\Messages;

/**
 * Outbound SMS payload. segmentCount() implements GSM-7 vs UCS-2 segmentation
 * (160/153 single/concatenated vs 70/67) so operators can see cost before a bulk send.
 */
final readonly class SmsMessage
{
    public function __construct(
        public string $to,
        public string $body,
        public ?string $from = null,
    ) {}

    public function withSender(?string $from): self
    {
        return new self(
            to: $this->to,
            body: $this->body,
            from: $from ?? $this->from,
        );
    }

    public function segmentCount(): int
    {
        $length = $this->encodingLength($this->body);

        if ($length === 0) {
            return 0;
        }

        if ($this->isGsm7($this->body)) {
            return $length <= 160 ? 1 : (int) ceil($length / 153);
        }

        return $length <= 70 ? 1 : (int) ceil($length / 67);
    }

    private function isGsm7(string $body): bool
    {
        // GSM 03.38 basic + extension table characters.
        static $gsm7 = null;

        if ($gsm7 === null) {
            $basic = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞ ÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?"
                ."¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
            $extended = "^{}\\[~]|€";
            $gsm7 = $basic.$extended;
        }

        $chars = mb_str_split($body, 1, 'UTF-8');

        foreach ($chars as $char) {
            if (! str_contains($gsm7, $char)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Character count for segmentation: GSM-7 extension chars ({ } \ [ ~ ] | € ^)
     * consume two septets.
     */
    private function encodingLength(string $body): int
    {
        if (! $this->isGsm7($body)) {
            return mb_strlen($body, 'UTF-8');
        }

        $extended = ['^', '{', '}', '\\', '[', '~', ']', '|', '€'];
        $length = 0;

        foreach (mb_str_split($body, 1, 'UTF-8') as $char) {
            $length += in_array($char, $extended, true) ? 2 : 1;
        }

        return $length;
    }
}
