<?php

declare(strict_types=1);

namespace App\Support\Templates;

use App\Enums\TemplatePurpose;
use App\Support\Automation\RunContext;

/**
 * Single list of template tokens: menu, publish samples, and (later) the assistant.
 *
 * Samples are unformatted strings. Money stays a string (invariant 10). The renderer
 * formats money and dates. sampleContext accepts a locale and does not apply it.
 */
final class TokenCatalog
{
    /**
     * @return list<TokenDefinition>
     */
    public static function all(): array
    {
        return self::definitions();
    }

    /**
     * @return list<TokenDefinition>
     */
    public static function forPurpose(TemplatePurpose $purpose): array
    {
        return array_values(array_filter(
            self::all(),
            fn (TokenDefinition $token): bool => $token->availableFor($purpose),
        ));
    }

    public static function find(string $path): ?TokenDefinition
    {
        foreach (self::all() as $token) {
            if ($token->path === $path) {
                return $token;
            }
        }

        return null;
    }

    public static function isAvailable(string $path, TemplatePurpose $purpose): bool
    {
        foreach (self::all() as $token) {
            if ($token->path === $path) {
                return $token->availableFor($purpose);
            }
        }

        return false;
    }

    /**
     * Nested subject bag for the given purpose. Only available tokens are present.
     *
     * @return array<string, mixed>
     */
    public static function sampleBag(TemplatePurpose $purpose): array
    {
        $bag = [];

        foreach (self::forPurpose($purpose) as $token) {
            self::put($bag, $token->path, self::typedSample($token));
        }

        return $bag;
    }

    public static function sampleContext(TemplatePurpose $purpose, string $locale): RunContext
    {
        // Locale is part of the contract. Values stay as stored; the renderer formats them.
        unset($locale);

        return new RunContext(subjectBag: self::sampleBag($purpose));
    }

    /**
     * @return list<TokenDefinition>
     */
    private static function definitions(): array
    {
        $contact = self::everyPurpose();
        $contract = [
            TemplatePurpose::General,
            TemplatePurpose::Debt,
            TemplatePurpose::System,
            TemplatePurpose::Announcement,
            TemplatePurpose::Contract,
        ];
        $deal = [
            TemplatePurpose::General,
            TemplatePurpose::Lead,
            TemplatePurpose::Offer,
        ];
        $payLink = [
            TemplatePurpose::Debt,
            TemplatePurpose::System,
            TemplatePurpose::Contract,
        ];
        $marketing = [
            TemplatePurpose::Marketing,
            TemplatePurpose::Newsletter,
            TemplatePurpose::Announcement,
            TemplatePurpose::Contract,
        ];
        $newsletter = [TemplatePurpose::Newsletter];

        return [
            self::text('contact.first_name', TokenGroup::Contact, 'Ada', $contact),
            self::text('contact.last_name', TokenGroup::Contact, 'Lovelace', $contact),
            self::text('contact.name', TokenGroup::Contact, 'Ada Lovelace', $contact),
            self::text('contact.email', TokenGroup::Contact, 'ada@example.com', $contact),
            self::text('contact.company', TokenGroup::Contact, 'Analytical Engines', $contact),
            self::number('contact.id', TokenGroup::Contact, '1', $contact),
            self::number('contract.id', TokenGroup::Contract, '1', $contract),
            self::money('contract.balance_owed', '0.00', $contract),
            self::text('contract.currency', TokenGroup::Contract, 'EUR', $contract),
            self::text('contract.unit_name', TokenGroup::Contract, 'A1', $contract),
            self::money('contract.unit_rate', '10.00', $contract),
            self::number('deal.id', TokenGroup::Deal, '1', $deal),
            self::text('deal.status', TokenGroup::Deal, 'open', $deal),
            self::url('pay_link', TokenGroup::Links, 'https://example.test/pay', $payLink),
            self::url('preferences_url', TokenGroup::Marketing, 'https://example.test/preferences/sample', $marketing),
            self::url('unsubscribe_url', TokenGroup::Marketing, 'https://example.test/unsubscribe/sample', $marketing),
            self::number('newsletter.issue_number', TokenGroup::Newsletter, '1', $newsletter),
            self::text('newsletter.name', TokenGroup::Newsletter, 'Newsletter', $newsletter),
        ];
    }

    /**
     * @param  list<TemplatePurpose>  $purposes
     */
    private static function text(string $path, TokenGroup $group, string $sample, array $purposes): TokenDefinition
    {
        return self::define($path, $group, TokenType::Text, $sample, $purposes);
    }

    /**
     * @param  list<TemplatePurpose>  $purposes
     */
    private static function money(string $path, string $sample, array $purposes): TokenDefinition
    {
        return self::define($path, TokenGroup::Contract, TokenType::Money, $sample, $purposes);
    }

    /**
     * @param  list<TemplatePurpose>  $purposes
     */
    private static function number(string $path, TokenGroup $group, string $sample, array $purposes): TokenDefinition
    {
        return self::define($path, $group, TokenType::Number, $sample, $purposes);
    }

    /**
     * @param  list<TemplatePurpose>  $purposes
     */
    private static function url(string $path, TokenGroup $group, string $sample, array $purposes): TokenDefinition
    {
        return self::define($path, $group, TokenType::Url, $sample, $purposes);
    }

    /**
     * @param  list<TemplatePurpose>  $purposes
     */
    private static function define(
        string $path,
        TokenGroup $group,
        TokenType $type,
        string $sample,
        array $purposes,
    ): TokenDefinition {
        $key = str_replace('.', '_', $path);

        return new TokenDefinition(
            path: $path,
            group: $group,
            type: $type,
            labelKey: 'templates.tokens.'.$key,
            descriptionKey: 'templates.tokens.'.$key.'_help',
            sample: $sample,
            purposes: $purposes,
        );
    }

    /**
     * @return list<TemplatePurpose>
     */
    private static function everyPurpose(): array
    {
        return TemplatePurpose::cases();
    }

    /**
     * @param  array<string, mixed>  $bag
     */
    private static function put(array &$bag, string $path, int|string $value): void
    {
        $parts = explode('.', $path);
        if (count($parts) === 1) {
            $bag[$parts[0]] = $value;

            return;
        }

        if (! isset($bag[$parts[0]]) || ! is_array($bag[$parts[0]])) {
            $bag[$parts[0]] = [];
        }

        $bag[$parts[0]][$parts[1]] = $value;
    }

    private static function typedSample(TokenDefinition $token): int|string
    {
        return $token->type === TokenType::Number
            ? (int) $token->sample
            : $token->sample;
    }
}
