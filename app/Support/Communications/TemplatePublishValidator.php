<?php

declare(strict_types=1);

namespace App\Support\Communications;

use App\Enums\TemplateChannel;
use App\Enums\TemplatePurpose;
use App\Models\TemplateVariant;
use App\Models\TemplateVersion;
use App\Support\Automation\RunContext;
use App\Support\Automation\TokenResolver;
use App\Support\Documents\DocumentBlockDocument;
use Illuminate\Validation\ValidationException;

/**
 * Publish-time render check. Fatal validation blocks. Unresolved tokens do not.
 */
final class TemplatePublishValidator
{
    /**
     * @return list<array{variant_id: int, locale: string, tokens: list<string>}>
     */
    public static function warnings(TemplateVersion $version): array
    {
        $version->loadMissing(['family', 'variants']);
        $family = $version->family;
        $channel = $family->channel instanceof TemplateChannel
            ? $family->channel
            : TemplateChannel::from((string) $family->channel);
        $purpose = $family->purpose instanceof TemplatePurpose
            ? $family->purpose
            : TemplatePurpose::from((string) $family->purpose);
        $context = self::sampleContext($purpose);

        $warnings = [];
        foreach ($version->variants as $variant) {
            try {
                $tokens = self::tokenWarnings($channel, $variant, $context, $purpose);
            } catch (ValidationException $exception) {
                $reason = collect($exception->errors())->flatten()->implode(' ');
                throw ValidationException::withMessages([
                    'variants' => [__('errors.templates.publish_failed', [
                        'variant' => (string) $variant->id,
                        'locale' => $variant->locale,
                        'reason' => $reason,
                    ])],
                ]);
            }

            if ($tokens !== []) {
                $warnings[] = [
                    'variant_id' => $variant->id,
                    'locale' => $variant->locale,
                    'tokens' => $tokens,
                ];
            }
        }

        return $warnings;
    }

    /**
     * @return list<string>
     */
    private static function tokenWarnings(
        TemplateChannel $channel,
        TemplateVariant $variant,
        RunContext $context,
        TemplatePurpose $purpose,
    ): array {
        return match ($channel) {
            TemplateChannel::Email => EmailTemplateRenderer::render($variant, $context)['warnings'],
            TemplateChannel::Sms => SmsTemplateRenderer::render($variant, $context)['warnings'],
            TemplateChannel::Document => self::documentWarnings($variant, $context, $purpose),
        };
    }

    /**
     * Structural rules match ContractDocumentRenderer (validateForRender).
     * Publish does not render a PDF.
     *
     * @return list<string>
     */
    private static function documentWarnings(
        TemplateVariant $variant,
        RunContext $context,
        TemplatePurpose $purpose,
    ): array {
        $doc = DocumentBlockDocument::validateForRender($variant->blocks, $purpose);
        $warnings = [];

        foreach ($doc['blocks'] as $block) {
            foreach ($block['params'] as $value) {
                if (! is_string($value) || $value === '') {
                    continue;
                }

                foreach (TokenResolver::resolveCollectingWarnings($value, $context)['warnings'] as $path) {
                    if (! in_array($path, $warnings, true)) {
                        $warnings[] = $path;
                    }
                }
            }
        }

        return $warnings;
    }

    private static function sampleContext(TemplatePurpose $purpose): RunContext
    {
        $bag = [
            'contact' => [
                'id' => 1,
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'name' => 'Ada Lovelace',
                'email' => 'ada@example.com',
                'company' => 'Analytical Engines',
            ],
            'pay_link' => 'https://example.test/pay',
        ];

        if ($purpose === TemplatePurpose::Debt || $purpose === TemplatePurpose::Contract) {
            $bag['contract'] = [
                'id' => 1,
                'balance_owed' => '0.00',
                'currency' => 'EUR',
                'unit_name' => 'A1',
                'unit_rate' => '10.00',
            ];
        }

        if ($purpose === TemplatePurpose::Lead || $purpose === TemplatePurpose::Offer) {
            $bag['deal'] = [
                'id' => 1,
                'status' => 'open',
            ];
        }

        return new RunContext(subjectBag: $bag);
    }
}
