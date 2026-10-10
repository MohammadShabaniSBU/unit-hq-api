<?php

declare(strict_types=1);

namespace App\Support\Marketing;

use App\Enums\AttributeEntityType;
use App\Enums\CampaignType;
use App\Enums\TemplatePurpose;
use App\Models\Campaign;
use App\Models\CampaignSend;
use App\Models\Contact;
use App\Models\Employee;
use App\Models\Setting;
use App\Models\Site;
use App\Models\TemplateFamily;
use App\Models\TemplateVersion;
use App\Support\Auth\Permission;
use App\Support\Automation\RunContext;
use App\Support\Automation\SubjectTokenBag;
use App\Support\Communications\Channel;
use App\Support\Communications\Exceptions\ChannelNotConfigured;
use App\Support\Communications\Messages\SmsMessage;
use App\Support\Communications\ProviderResolver;
use App\Support\Communications\SendClass;
use App\Support\Communications\SmsTemplateRenderer;
use App\Support\Communications\TemplateResolver;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Checks a draft can be scheduled and pins each send to the current
 * published template version. Approve re-runs the content and provider checks.
 */
final class CampaignScheduleValidator
{
    public function __construct(
        private readonly ProviderResolver $providers,
        private readonly CampaignAudienceQuery $audience,
    ) {}

    public function prepare(Campaign $campaign, Employee $submitter): CampaignScheduleResult
    {
        $campaign->load(['sends.templateFamily.currentVersion', 'subscriptionList', 'segment']);
        $pins = $this->assertReady($campaign);
        $settings = Setting::marketing();
        $estimate = $this->audience->estimate($campaign);
        $lacksApprove = $submitter->siteIdsFor(Permission::CampaignApprove) === [];
        $approvalRequired = $campaign->type === CampaignType::Announcement
            ? $settings->announcementApprovalRequired
            : ($estimate > $settings->approvalThreshold || $lacksApprove);

        return new CampaignScheduleResult(
            snapshot: $this->audience->snapshot($campaign, $settings),
            estimatedRecipients: $estimate,
            approvalRequired: $approvalRequired,
            warnings: $this->smsWarnings($campaign),
            pins: $pins,
        );
    }

    /**
     * Steps 2–5 again. The published version may have moved since submit.
     *
     * @return array<int, int>
     */
    public function repin(Campaign $campaign): array
    {
        $campaign->load(['sends.templateFamily.currentVersion', 'subscriptionList', 'segment']);

        return $this->assertReady($campaign, checkSchedule: false);
    }

    /**
     * @return array<int, int>
     */
    private function assertReady(Campaign $campaign, bool $checkSchedule = true): array
    {
        if ($campaign->sends->isEmpty()) {
            throw ValidationException::withMessages([
                'sends' => [__('errors.campaigns.send_required')],
            ]);
        }

        $this->assertSegment($campaign);

        $pins = [];
        foreach ($campaign->sends as $send) {
            if ($checkSchedule) {
                $this->assertSchedule($send);
            }
            $version = $this->assertFamily($campaign, $send);
            $this->assertProvider($campaign, $send->channel);
            $this->assertListChannel($campaign, $send->channel);
            $pins[$send->id] = $version->id;
        }

        return $pins;
    }

    private function assertSchedule(CampaignSend $send): void
    {
        $at = $send->scheduled_at;
        if ($at instanceof Carbon && $at->lt(now()->copy()->addMinutes(2))) {
            throw ValidationException::withMessages([
                'scheduled_at' => [__('errors.campaigns.scheduled_at')],
            ]);
        }
    }

    private function assertFamily(Campaign $campaign, CampaignSend $send): TemplateVersion
    {
        $family = $send->templateFamily;
        if (! $family instanceof TemplateFamily || $family->archived_at !== null) {
            throw ValidationException::withMessages([
                'template_family_id' => [__('errors.campaigns.template_unsendable')],
            ]);
        }

        if ($family->channel->value !== $send->channel->value) {
            throw ValidationException::withMessages([
                'channel' => [__('errors.campaigns.channel_mismatch')],
            ]);
        }

        $purpose = $family->purpose instanceof TemplatePurpose
            ? $family->purpose->value
            : (string) $family->purpose;
        if (! in_array($purpose, $campaign->type->purposeAllowlist(), true)) {
            throw ValidationException::withMessages([
                'template_family_id' => [__('errors.campaigns.purpose_mismatch')],
            ]);
        }

        $version = $family->currentVersion;
        if (! $version instanceof TemplateVersion) {
            throw ValidationException::withMessages([
                'template_family_id' => [__('errors.campaigns.template_unsendable')],
            ]);
        }

        $version->loadMissing('variants');
        if ($version->variants->isEmpty()) {
            throw ValidationException::withMessages([
                'template_family_id' => [__('errors.campaigns.template_unsendable')],
            ]);
        }

        return $version;
    }

    private function assertProvider(Campaign $campaign, Channel $channel): void
    {
        $class = $campaign->type === CampaignType::Announcement
            ? SendClass::Transactional
            : SendClass::Marketing;
        $siteIds = array_values(array_unique(array_map(intval(...), $campaign->site_ids ?? [])));
        $sites = $siteIds === []
            ? Site::query()->active()->orderBy('id')->get()
            : Site::query()->whereIn('id', $siteIds)->orderBy('id')->get();

        if ($siteIds !== [] && $sites->count() !== count($siteIds)) {
            throw ValidationException::withMessages([
                'site_ids' => [__('errors.campaigns.site_missing')],
            ]);
        }

        foreach ($sites as $site) {
            if ($site->isArchived()) {
                throw ValidationException::withMessages([
                    'site_ids' => [__('errors.campaigns.site_archived')],
                ]);
            }
        }

        try {
            if ($sites->isEmpty()) {
                $this->providers->resolve($channel, null, $class);

                return;
            }

            foreach ($sites as $site) {
                $this->providers->resolve($channel, $site, $class);
            }
        } catch (ChannelNotConfigured $exception) {
            throw ValidationException::withMessages([
                'channel' => [$exception->getMessage()],
            ]);
        }
    }

    private function assertListChannel(Campaign $campaign, Channel $channel): void
    {
        if ($campaign->type === CampaignType::Announcement) {
            return;
        }

        $list = $campaign->subscriptionList;
        if ($list === null || $list->isArchived() || ! $list->allows($channel)) {
            throw ValidationException::withMessages([
                'channel' => [__('errors.campaigns.list_channel')],
            ]);
        }
    }

    private function assertSegment(Campaign $campaign): void
    {
        if ($campaign->segment_id === null) {
            return;
        }

        $segment = $campaign->segment;
        if (
            $segment === null
            || $segment->isArchived()
            || $segment->entity_type !== AttributeEntityType::Contact->value
        ) {
            throw ValidationException::withMessages([
                'segment_id' => [__('errors.campaigns.segment_invalid')],
            ]);
        }
    }

    /**
     * @return array{approval_required: bool, approval_reasons: list<string>}
     */
    public function approvalPreview(Campaign $campaign, Employee $actor, int $estimate): array
    {
        $settings = Setting::marketing();
        $reasons = [];

        if ($campaign->type === CampaignType::Announcement) {
            if ($settings->announcementApprovalRequired) {
                $reasons[] = 'announcement_setting';
            }
        } else {
            if ($estimate > $settings->approvalThreshold) {
                $reasons[] = 'threshold';
            }
            if ($actor->siteIdsFor(Permission::CampaignApprove) === []) {
                $reasons[] = 'lacks_approve';
            }
        }

        return [
            'approval_required' => $reasons !== [],
            'approval_reasons' => $reasons,
        ];
    }

    public function smsSegmentCount(Campaign $campaign): ?int
    {
        $campaign->loadMissing('sends.templateFamily.currentVersion');

        foreach ($campaign->sends as $send) {
            if ($send->channel !== Channel::Sms) {
                continue;
            }

            $version = $send->templateFamily?->currentVersion;
            if (! $version instanceof TemplateVersion) {
                return null;
            }

            $contact = $this->audience->candidates($campaign, Channel::Sms)->orderBy('contacts.id')->first();
            if (! $contact instanceof Contact) {
                $contact = new Contact(['locale' => 'en']);
            }

            $site = $this->sampleSite($campaign);
            $variant = TemplateResolver::variantOf($version, $contact, $site);
            $purpose = $send->templateFamily?->purpose instanceof TemplatePurpose
                ? $send->templateFamily->purpose
                : TemplatePurpose::Marketing;
            $bag = $contact->exists
                ? SubjectTokenBag::forContact($contact)
                : SubjectTokenBag::sample($purpose);
            $bag = SubjectTokenBag::mergeNewsletter($bag, $campaign, (string) ($contact->locale ?? ''));
            $rendered = SmsTemplateRenderer::render($variant, new RunContext(subjectBag: $bag));
            $body = $rendered['text'];

            if ($campaign->type === CampaignType::Announcement && $site instanceof Site) {
                $footer = AnnouncementFooter::render($site);
                if ($footer['text'] !== '') {
                    $body = trim($body) === '' ? $footer['text'] : rtrim($body)."\n".$footer['text'];
                }
            } elseif ($campaign->type !== CampaignType::Announcement && $site !== null && $contact->exists) {
                $footer = MarketingFooter::render(
                    $site,
                    $contact,
                    Channel::Sms,
                    $campaign->subscription_list_id,
                );
                if ($footer['text'] !== '') {
                    $body = trim($body) === '' ? $footer['text'] : rtrim($body)."\n".$footer['text'];
                }
            }

            return (new SmsMessage('0', $body))->segmentCount();
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function smsWarnings(Campaign $campaign): array
    {
        $segments = $this->smsSegmentCount($campaign);
        if ($segments === null || $segments <= 2) {
            return [];
        }

        return [__('errors.campaigns.sms_segments', ['count' => $segments])];
    }

    private function sampleSite(Campaign $campaign): ?Site
    {
        $siteIds = array_values(array_map(intval(...), $campaign->site_ids ?? []));
        $query = Site::query()->active()->orderBy('id');
        if ($siteIds !== []) {
            $query->whereIn('id', $siteIds);
        }

        return $query->first();
    }
}
