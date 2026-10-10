<?php

declare(strict_types=1);

namespace App\Support\Marketing;

use App\Enums\CampaignType;
use App\Enums\TemplatePurpose;
use App\Models\Campaign;
use App\Models\CampaignSend;
use App\Models\Contact;
use App\Models\Employee;
use App\Models\Site;
use App\Models\TemplateVersion;
use App\Support\Automation\RunContext;
use App\Support\Automation\SubjectTokenBag;
use App\Support\Communications\Channel;
use App\Support\Communications\EmailTemplateRenderer;
use App\Support\Communications\Messages\EmailAddress;
use App\Support\Communications\Messages\EmailMessage;
use App\Support\Communications\Messages\SmsMessage;
use App\Support\Communications\MessageSource;
use App\Support\Communications\SendClass;
use App\Support\Communications\SendContext;
use App\Support\Communications\Senders\EmailSender;
use App\Support\Communications\Senders\SmsSender;
use App\Support\Communications\SmsTemplateRenderer;
use App\Support\Communications\TemplateProvenance;
use App\Support\Communications\TemplateResolver;
use Illuminate\Validation\ValidationException;

/**
 * Renders the current published template and delivers it to employees.
 * Never writes campaign_recipients.
 */
final class CampaignTestSend
{
    public function __construct(
        private readonly EmailSender $email,
        private readonly SmsSender $sms,
    ) {}

    /**
     * @param  list<string>  $recipients
     */
    public function send(Campaign $campaign, Channel $channel, array $recipients, ?int $asContactId): int
    {
        if ($recipients === [] || count($recipients) > 5) {
            throw ValidationException::withMessages([
                'to' => [__('errors.campaigns.test_recipients')],
            ]);
        }

        $this->assertEmployees($channel, $recipients);

        $campaign->loadMissing('sends.templateFamily.currentVersion');
        $send = $campaign->sends->first(fn (CampaignSend $row): bool => $row->channel === $channel);
        if (! $send instanceof CampaignSend) {
            throw ValidationException::withMessages([
                'channel' => [__('errors.campaigns.send_required')],
            ]);
        }

        $version = $send->templateFamily?->currentVersion;
        if (! $version instanceof TemplateVersion) {
            throw ValidationException::withMessages([
                'template_family_id' => [__('errors.campaigns.template_unsendable')],
            ]);
        }

        $contact = null;
        if ($asContactId !== null) {
            $contact = Contact::query()->find($asContactId);
            if (! $contact instanceof Contact) {
                throw ValidationException::withMessages([
                    'as_contact_id' => [__('errors.campaigns.contact_missing')],
                ]);
            }
        }

        $site = $this->site($campaign);
        if (! $site instanceof Site) {
            throw ValidationException::withMessages([
                'site_ids' => [__('errors.campaigns.site_missing')],
            ]);
        }

        $renderContact = $contact ?? new Contact(['locale' => 'en']);
        $variant = TemplateResolver::variantOf($version, $renderContact, $site);
        $class = $campaign->type === CampaignType::Announcement
            ? SendClass::Transactional
            : SendClass::Marketing;
        $purpose = $send->templateFamily?->purpose instanceof TemplatePurpose
            ? $send->templateFamily->purpose
            : TemplatePurpose::Marketing;
        $bag = $contact instanceof Contact
            ? SubjectTokenBag::forContact($contact)
            : SubjectTokenBag::sample($purpose);
        $bag = SubjectTokenBag::mergeNewsletter($bag, $campaign, (string) ($renderContact->locale ?? ''));
        $run = new RunContext(subjectBag: $bag);
        $context = new SendContext(
            MessageSource::Campaign,
            $class,
            [
                'campaign_id' => $campaign->id,
                'campaign_send_id' => $send->id,
                'campaign_recipient_id' => null,
                'subscription_list_id' => $campaign->subscription_list_id,
            ],
        );
        $detail = [
            'test' => true,
            'template' => TemplateProvenance::from($variant, $renderContact, $site)->toArray(),
        ];
        $renderClass = $contact instanceof Contact ? $class : SendClass::Transactional;

        foreach ($recipients as $address) {
            if ($channel === Channel::Email) {
                $rendered = EmailTemplateRenderer::render(
                    $variant,
                    $run,
                    $send->subject_override,
                    false,
                    $renderClass,
                    $site,
                    $contact,
                    $campaign->subscription_list_id,
                );
                if ($campaign->type === CampaignType::Announcement) {
                    $footer = AnnouncementFooter::render($site);
                    $appended = MarketingFooter::appendEmail($rendered['html'], $rendered['text'], $footer);
                    $rendered['html'] = $appended['html'];
                    $rendered['text'] = $appended['text'];
                }
                $this->email->send(
                    new EmailMessage(
                        to: [new EmailAddress($address)],
                        subject: $rendered['subject'],
                        html: $rendered['html'],
                        text: $rendered['text'],
                    ),
                    $site,
                    $contact,
                    $context,
                    detail: $detail,
                );

                continue;
            }

            $rendered = SmsTemplateRenderer::render($variant, $run);
            $body = $rendered['text'];
            if ($campaign->type === CampaignType::Announcement) {
                $footer = AnnouncementFooter::render($site);
                if ($footer['text'] !== '') {
                    $body = trim($body) === '' ? $footer['text'] : rtrim($body)."\n".$footer['text'];
                }
            } elseif ($renderClass === SendClass::Marketing && $contact instanceof Contact) {
                $footer = MarketingFooter::render($site, $contact, Channel::Sms, $campaign->subscription_list_id, $address);
                if ($footer['text'] !== '') {
                    $body = trim($body) === '' ? $footer['text'] : rtrim($body)."\n".$footer['text'];
                }
            }
            $this->sms->send(
                new SmsMessage(to: $address, body: $body),
                $site,
                $contact,
                $context,
                detail: $detail,
            );
        }

        return count($recipients);
    }

    /**
     * @param  list<string>  $recipients
     */
    private function assertEmployees(Channel $channel, array $recipients): void
    {
        if ($channel !== Channel::Email) {
            return;
        }

        $known = Employee::query()
            ->whereNull('deactivated_at')
            ->pluck('email')
            ->map(static fn (mixed $email): string => strtolower(trim((string) $email)))
            ->all();

        foreach ($recipients as $address) {
            if (! in_array(strtolower(trim($address)), $known, true)) {
                throw ValidationException::withMessages([
                    'to' => [__('errors.campaigns.test_recipient')],
                ]);
            }
        }
    }

    private function site(Campaign $campaign): ?Site
    {
        $siteIds = array_values(array_map(intval(...), $campaign->site_ids ?? []));
        $query = Site::query()->active()->orderBy('id');
        if ($siteIds !== []) {
            $query->whereIn('id', $siteIds);
        }

        return $query->first();
    }
}
