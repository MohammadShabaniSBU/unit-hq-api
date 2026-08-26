<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\ForbiddenClaimKey;
use App\Support\Ai\Enums\HandoffReason;
use App\Support\Ai\Guards\CannedReply;
use App\Support\Ai\Guards\ForbiddenClaimGuard;
use App\Support\Ai\Guards\KeywordMatcher;
use App\Support\Ai\Tools\FactBag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Ai\DispatchesAgentTools;
use Tests\TestCase;

class ForbiddenClaimGuardTest extends TestCase
{
    use DispatchesAgentTools;
    use RefreshDatabase;

    #[Test]
    public function payment_confirmation_is_blocked(): void
    {
        $this->assertBlocked('Your payment has been received this morning.');
    }

    #[Test]
    public function fee_waiver_is_blocked(): void
    {
        $this->assertBlocked("I've waived the late fee for you.");
    }

    #[Test]
    public function access_grant_is_blocked(): void
    {
        $this->assertBlocked('You can get in now, the lock is off.');
    }

    #[Test]
    public function legal_advice_is_blocked(): void
    {
        $this->assertBlocked('You are not liable for that damage.');
    }

    #[Test]
    public function contract_mutation_is_blocked(): void
    {
        $this->assertBlocked("I've updated your contract to the new rate.");
    }

    #[Test]
    #[DataProvider('commitmentPatternsProvider')]
    public function commitment_patterns_redraft_unlicensed(string $locale, string $draft): void
    {
        $this->assertRedrafts($draft, $locale);
    }

    #[Test]
    #[DataProvider('commitmentPatternsProvider')]
    public function commitment_patterns_pass_when_licensed(string $locale, string $draft): void
    {
        $ctx = $this->writeContext(AgentPrincipal::anonymous(null, $locale), 'sales')
            ->withLicensedClaims([ForbiddenClaimKey::AvailabilityGuarantee]);

        $verdict = app(ForbiddenClaimGuard::class)->check($draft, new FactBag, $ctx);

        $this->assertTrue($verdict->passed, $draft);
        $this->assertNull($verdict->retry);
    }

    #[Test]
    #[DataProvider('offerQuestionProvider')]
    public function closing_offer_questions_are_not_claims(string $locale, string $draft): void
    {
        $verdict = app(ForbiddenClaimGuard::class)->check(
            $draft,
            new FactBag,
            $this->writeContext(AgentPrincipal::anonymous(null, $locale), 'sales'),
        );

        $this->assertTrue($verdict->passed, $draft);
        $this->assertNull($verdict->retry);
    }

    #[Test]
    public function typographic_apostrophe_matches_ascii_pattern(): void
    {
        $this->assertRedrafts("I\u{2019}ll create a reservation for Monday.", 'en');
    }

    #[Test]
    public function licensed_alternatives_do_not_match_their_own_claim_group(): void
    {
        foreach (['en', 'es', 'fr'] as $locale) {
            foreach (ForbiddenClaimKey::cases() as $key) {
                $alternative = CannedReply::licensedAlternative($key, $locale);
                $this->assertNotSame('', $alternative, $locale.' '.$key->value);
                $phrases = config("ai-handoff.forbidden_claims.{$locale}.{$key->value}");
                $this->assertIsArray($phrases);
                /** @var list<string> $phrases */
                $this->assertNull(
                    KeywordMatcher::firstMatch($alternative, $phrases),
                    $locale.' '.$key->value.': '.$alternative,
                );
            }
        }
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function commitmentPatternsProvider(): array
    {
        return [
            'en move forward a' => ['en', "I'll move forward with a reservation for next Monday."],
            'en move forward the' => ['en', "I'll move forward with the reservation now."],
            'en create a' => ['en', "I'll create a reservation for you."],
            'en create the' => ['en', "I'll create the reservation today."],
            'en will create' => ['en', 'I will create a reservation this afternoon.'],
            'en created' => ['en', "I've created a reservation already."],
            'en make' => ['en', "I'll make a reservation for Monday."],
            'en made' => ['en', "I've made a reservation under your name."],
            'en place' => ['en', "I'll place a reservation on that class."],
            'en set up' => ['en', "I'll set up a reservation for the small unit."],
            'en reserve' => ['en', "I'll reserve it until Friday."],
            'en will reserve' => ['en', 'I will reserve the unit now.'],
            'en held it' => ['en', "I've held it for you until Friday."],
            'en i will hold' => ['en', "I'll hold the unit overnight."],
            'en its held' => ['en', "It's held until you confirm."],
            'en it is held' => ['en', 'It is held for Monday.'],
            'en reserved for you' => ['en', 'The small unit is reserved for you.'],
            'en held for you' => ['en', 'A unit is held for you until Friday.'],
            'es te lo reservo' => ['es', 'Te lo reservo hasta el viernes.'],
            'es se lo reservo' => ['es', 'Se lo reservo ahora mismo.'],
            'es queda reservado' => ['es', 'Queda reservado a su nombre.'],
            'es voy a hacer' => ['es', 'Voy a hacer la reserva para el lunes.'],
            'es he hecho' => ['es', 'He hecho la reserva esta mañana.'],
            'es voy a crear' => ['es', 'Voy a crear la reserva enseguida.'],
            'es avanzar' => ['es', 'Vamos a avanzar con la reserva el lunes.'],
            'es seguir adelante' => ['es', 'Podemos seguir adelante con la reserva.'],
            'fr je vous le reserve' => ['fr', 'Je vous le réserve jusqu’à vendredi.'],
            'fr pour vous' => ['fr', 'Je le réserve pour vous dès maintenant.'],
            'fr vais faire' => ['fr', 'Je vais faire la réservation pour lundi.'],
            'fr ai fait' => ['fr', 'J’ai fait la réservation ce matin.'],
            'fr vais creer' => ['fr', 'Je vais créer la réservation tout de suite.'],
            'fr proceder' => ['fr', 'Nous allons procéder à la réservation.'],
            'fr avancer' => ['fr', 'On peut avancer avec la réservation lundi.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function offerQuestionProvider(): array
    {
        return [
            'en' => ['en', 'Would you like me to create a reservation?'],
            'es' => ['es', '¿Quieres que haga la reserva?'],
            'fr' => ['fr', 'Souhaitez-vous que je fasse la réservation ?'],
        ];
    }

    private function assertBlocked(string $draft): void
    {
        $verdict = app(ForbiddenClaimGuard::class)->check(
            $draft,
            new FactBag,
            $this->writeContext(AgentPrincipal::anonymous(null, 'en'), 'support'),
        );

        $this->assertFalse($verdict->passed, $draft);
        $this->assertNull($verdict->retry, $draft);
        $this->assertSame('forbidden_claim', $verdict->blockedBy);
        $this->assertSame(HandoffReason::UnsupportedIntent, $verdict->handoffReason);
    }

    private function assertRedrafts(string $draft, string $locale): void
    {
        $verdict = app(ForbiddenClaimGuard::class)->check(
            $draft,
            new FactBag,
            $this->writeContext(AgentPrincipal::anonymous(null, $locale), 'sales'),
        );

        $this->assertFalse($verdict->passed, $draft);
        $this->assertNotNull($verdict->retry, $draft);
        $this->assertSame('forbidden_claim', $verdict->blockedBy);
        $this->assertSame(HandoffReason::UnsupportedIntent, $verdict->handoffReason);
        $this->assertSame('deny', $verdict->events[0]['verdict'] ?? null);
    }
}
