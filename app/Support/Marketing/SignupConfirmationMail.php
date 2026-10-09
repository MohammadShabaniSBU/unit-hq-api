<?php

declare(strict_types=1);

namespace App\Support\Marketing;

use App\Enums\TemplateChannel;
use App\Enums\TemplatePurpose;
use App\Models\Contact;
use App\Models\SignupForm;
use App\Models\TemplateVariant;
use App\Support\Automation\RunContext;
use App\Support\Automation\SubjectTokenBag;
use App\Support\Automation\TokenResolver;
use App\Support\Communications\EmailTemplateRenderer;
use App\Support\Communications\Messages\EmailAddress;
use App\Support\Communications\Messages\EmailMessage;
use App\Support\Communications\SendContext;
use App\Support\Communications\Senders\EmailSender;
use Illuminate\Support\Facades\Log;

/**
 * Transactional confirmation. Template purpose system, token {{confirm_url}}.
 * Built-in copy when the form has no published system template.
 */
final class SignupConfirmationMail
{
    public static function send(SignupForm $form, Contact $contact, string $token, string $locale): void
    {
        $email = trim((string) $contact->email);
        if ($email === '') {
            return;
        }

        $site = $form->site;
        if ($site === null) {
            return;
        }

        $url = self::url($token);
        $rendered = self::render($form, $contact, $url, $locale);

        try {
            app(EmailSender::class)->send(
                new EmailMessage(
                    to: [new EmailAddress($email, trim($contact->first_name.' '.$contact->last_name) ?: null)],
                    subject: $rendered['subject'],
                    html: $rendered['html'],
                    text: $rendered['text'],
                    tags: ['signup-confirmation'],
                ),
                $site,
                $contact,
                SendContext::system([
                    'signup_form_id' => $form->id,
                    'signup_confirmation' => true,
                ]),
            );
        } catch (\Throwable $exception) {
            Log::warning('signup confirmation email failed', [
                'signup_form_id' => $form->id,
                'contact_id' => $contact->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public static function url(string $token): string
    {
        return rtrim((string) config('app.panel_url'), '/').'/f/confirm/'.$token;
    }

    /**
     * @return array{subject: string, html: string, text: string}
     */
    private static function render(SignupForm $form, Contact $contact, string $url, string $locale): array
    {
        $bag = SubjectTokenBag::forContact($contact);
        $bag['confirm_url'] = $url;
        $context = new RunContext(subjectBag: $bag);

        $variant = self::variant($form, $locale);
        if ($variant !== null) {
            $rendered = EmailTemplateRenderer::render($variant, $context);

            return [
                'subject' => $rendered['subject'] !== '' ? $rendered['subject'] : self::fallbackSubject($locale),
                'html' => $rendered['html'],
                'text' => $rendered['text'],
            ];
        }

        $subject = self::fallbackSubject($locale);
        $html = TokenResolver::resolve(
            '<p><a href="{{confirm_url}}">'.$subject.'</a></p>',
            $context,
        );

        return [
            'subject' => $subject,
            'html' => $html,
            'text' => $subject.' '.$url,
        ];
    }

    private static function variant(SignupForm $form, string $locale): ?TemplateVariant
    {
        $family = $form->confirmationTemplate;
        if ($family === null || $family->archived_at !== null) {
            return null;
        }

        $purpose = $family->purpose instanceof TemplatePurpose
            ? $family->purpose
            : TemplatePurpose::tryFrom((string) $family->purpose);
        $channel = $family->channel instanceof TemplateChannel
            ? $family->channel
            : TemplateChannel::tryFrom((string) $family->channel);

        if ($purpose !== TemplatePurpose::System || $channel !== TemplateChannel::Email) {
            return null;
        }

        $version = $family->currentVersion;
        if ($version === null) {
            return null;
        }

        return $version->variants()->where('locale', $locale)->first()
            ?? $version->variants()->where('locale', 'en')->first()
            ?? $version->variants()->first();
    }

    private static function fallbackSubject(string $locale): string
    {
        return match ($locale) {
            'es' => 'Confirma tu suscripción',
            'fr' => 'Confirmez votre inscription',
            default => 'Confirm your subscription',
        };
    }
}
