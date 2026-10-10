<?php

declare(strict_types=1);

namespace App\Support\Marketing;

use App\Enums\CampaignType;
use App\Enums\TemplatePurpose;
use App\Models\Campaign;
use App\Models\CampaignSend;
use App\Models\Contact;
use App\Models\Site;
use App\Models\TemplateVersion;
use App\Support\Automation\RunContext;
use App\Support\Automation\SubjectTokenBag;
use App\Support\Communications\Channel;
use App\Support\Communications\EmailTemplateRenderer;
use App\Support\Communications\Messages\SmsMessage;
use App\Support\Communications\SendClass;
use App\Support\Communications\SmsTemplateRenderer;
use App\Support\Communications\TemplateResolver;
use Illuminate\Validation\ValidationException;

/**
 * Read-only render of a campaign send for the panel.
 * Uses the pinned version when one is set, otherwise the current published version.
 */
final class CampaignContentPreview
{
    public function __construct(
        private readonly CampaignAudienceQuery $audience,
    ) {}

    /**
     * @return array{channel: string, subject: string|null, html: string|null, text: string, sms_segments: int|null, version_number: int|null}
     */
    public function render(Campaign $campaign, Channel $channel, ?int $contactId): array
    {
        $campaign->loadMissing(['sends.templateFamily.currentVersion', 'sends.templateVersion']);
        $send = $campaign->sends->first(fn (CampaignSend $row): bool => $row->channel === $channel);
        if (! $send instanceof CampaignSend) {
            throw ValidationException::withMessages([
                'channel' => [__('errors.campaigns.send_required')],
            ]);
        }

        $version = $send->templateVersion ?? $send->templateFamily?->currentVersion;
        if (! $version instanceof TemplateVersion) {
            throw ValidationException::withMessages([
                'template_family_id' => [__('errors.campaigns.template_unsendable')],
            ]);
        }

        $contact = $this->contact($campaign, $channel, $contactId);
        $site = $this->site($campaign);
        if (! $site instanceof Site) {
            throw ValidationException::withMessages([
                'site_ids' => [__('errors.campaigns.site_missing')],
            ]);
        }
        $site->loadMissing('legalEntity');

        $class = $campaign->type === CampaignType::Announcement
            ? SendClass::Transactional
            : SendClass::Marketing;
        if ($class === SendClass::Marketing && ! $contact instanceof Contact) {
            throw ValidationException::withMessages([
                'contact_id' => [__('errors.campaigns.contact_missing')],
            ]);
        }

        $renderContact = $contact ?? new Contact(['locale' => 'en']);
        $variant = TemplateResolver::variantOf($version, $renderContact, $site);
        $purpose = $send->templateFamily?->purpose instanceof TemplatePurpose
            ? $send->templateFamily->purpose
            : TemplatePurpose::Marketing;
        $bag = $contact instanceof Contact
            ? SubjectTokenBag::forContact($contact)
            : SubjectTokenBag::sample($purpose);
        $bag = SubjectTokenBag::mergeNewsletter($bag, $campaign, (string) ($renderContact->locale ?? ''));
        $run = new RunContext(subjectBag: $bag);

        if ($channel === Channel::Email) {
            $rendered = EmailTemplateRenderer::render(
                $variant,
                $run,
                $send->subject_override,
                true,
                $class,
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

            return [
                'channel' => $channel->value,
                'subject' => $rendered['subject'],
                'html' => $rendered['html'],
                'text' => $rendered['text'],
                'sms_segments' => null,
                'version_number' => $version->version_number,
            ];
        }

        $rendered = SmsTemplateRenderer::render($variant, $run);
        $body = $rendered['text'];
        if ($campaign->type === CampaignType::Announcement) {
            $footer = AnnouncementFooter::render($site);
            if ($footer['text'] !== '') {
                $body = trim($body) === '' ? $footer['text'] : rtrim($body)."\n".$footer['text'];
            }
        } elseif ($contact instanceof Contact) {
            $footer = MarketingFooter::render($site, $contact, Channel::Sms, $campaign->subscription_list_id);
            if ($footer['text'] !== '') {
                $body = trim($body) === '' ? $footer['text'] : rtrim($body)."\n".$footer['text'];
            }
        }

        return [
            'channel' => $channel->value,
            'subject' => null,
            'html' => null,
            'text' => $body,
            'sms_segments' => (new SmsMessage('0', $body))->segmentCount(),
            'version_number' => $version->version_number,
        ];
    }

    private function contact(Campaign $campaign, Channel $channel, ?int $contactId): ?Contact
    {
        if ($contactId !== null) {
            $contact = Contact::query()->find($contactId);
            if (! $contact instanceof Contact) {
                throw ValidationException::withMessages([
                    'contact_id' => [__('errors.campaigns.contact_missing')],
                ]);
            }

            return $contact;
        }

        $candidate = $this->audience->candidates($campaign, $channel)->orderBy('contacts.id')->first();

        return $candidate instanceof Contact ? $candidate : null;
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
