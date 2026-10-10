<?php

declare(strict_types=1);

namespace App\Support\Marketing;

use App\Enums\CampaignRecipientStatus;
use App\Enums\CampaignSkipReason;
use App\Enums\CampaignStatus;
use App\Enums\CampaignType;
use App\Jobs\SendCampaignBatch;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignSend;
use App\Models\Contact;
use App\Models\Setting;
use App\Models\Site;
use App\Models\TemplateVariant;
use App\Models\TemplateVersion;
use App\Support\Automation\RunContext;
use App\Support\Automation\SubjectTokenBag;
use App\Support\Communications\Channel;
use App\Support\Communications\EmailTemplateRenderer;
use App\Support\Communications\Exceptions\ChannelNotConfigured;
use App\Support\Communications\Exceptions\ProviderRequestFailed;
use App\Support\Communications\Messages\EmailAddress;
use App\Support\Communications\Messages\EmailMessage;
use App\Support\Communications\Messages\SmsMessage;
use App\Support\Communications\Results\SendResult;
use App\Support\Communications\SendClass;
use App\Support\Communications\SendContext;
use App\Support\Communications\Senders\EmailSender;
use App\Support\Communications\Senders\SmsSender;
use App\Support\Communications\SmsTemplateRenderer;
use App\Support\Communications\TemplateProvenance;
use App\Support\Communications\TemplateResolver;
use App\Support\Time\SiteClock;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Claims each recipient, runs the send-time gates, then delivers.
 * A closed send window defers the rest of the batch. It never skips.
 */
final class CampaignBatchSender
{
    /**
     * Sync re-enters a delayed job immediately. While the window is still
     * closed that would recurse, so a nested deferral does not dispatch again.
     */
    private static int $deferDepth = 0;

    public function __construct(
        private readonly CampaignSendGate $gate,
        private readonly EmailSender $email,
        private readonly SmsSender $sms,
        private readonly CampaignSendCompletion $completion,
    ) {}

    /**
     * @param  list<int>  $recipientIds
     */
    public function send(int $campaignSendId, array $recipientIds, int $accountId, int $rateLimitPerMinute): void
    {
        $send = CampaignSend::query()->with(['campaign', 'templateVersion'])->find($campaignSendId);
        if ($send === null || $send->campaign === null) {
            return;
        }

        $ids = array_values(array_map(intval(...), $recipientIds));
        $deferred = [];

        foreach ($ids as $index => $recipientId) {
            $outcome = $this->processOne($send, $recipientId, $accountId, $rateLimitPerMinute);
            if ($outcome === 'stop') {
                break;
            }
            if ($outcome === 'defer') {
                $deferred = array_merge([$recipientId], array_slice($ids, $index + 1));
                break;
            }
        }

        if ($deferred !== []) {
            $this->defer($send, $deferred, $accountId, $rateLimitPerMinute);
        }

        $this->completion->completeSendIfIdle($send);
    }

    /**
     * @return 'ok'|'stop'|'defer'
     */
    private function processOne(CampaignSend $send, int $recipientId, int $accountId, int $rateLimitPerMinute): string
    {
        if (! $this->claim($recipientId)) {
            return 'ok';
        }

        $campaign = $send->campaign;
        if ($campaign === null) {
            $this->releaseClaim($recipientId, false);

            return 'stop';
        }

        $status = $this->campaignStatus($campaign->id);
        if ($status === CampaignStatus::Cancelled) {
            $this->skip($recipientId, CampaignSkipReason::Cancelled);

            return 'stop';
        }
        if ($status !== CampaignStatus::Sending) {
            $this->releaseClaim($recipientId, false);

            return 'stop';
        }

        $recipient = CampaignRecipient::query()->with(['contact', 'site'])->find($recipientId);
        if ($recipient === null) {
            return 'ok';
        }

        $reason = $this->gate->reason($campaign, $send->channel, (int) $recipient->contact_id);
        if ($reason !== null) {
            $this->skip($recipientId, $reason);

            return 'ok';
        }

        $site = $recipient->site;
        $contact = $recipient->contact;
        if (! $site instanceof Site || ! $contact instanceof Contact || trim((string) $recipient->address) === '') {
            $this->skip($recipientId, $site instanceof Site ? CampaignSkipReason::NoAddress : CampaignSkipReason::ChannelNotConfigured);

            return 'ok';
        }

        $settings = Setting::general();
        if (! $campaign->ignore_send_window && ! SiteClock::withinWindow($site, $settings->sendWindowStart, $settings->sendWindowEnd)) {
            $this->releaseClaim($recipientId, false);

            return 'defer';
        }

        $version = $send->templateVersion;
        if (! $version instanceof TemplateVersion) {
            $this->skip($recipientId, CampaignSkipReason::TemplateNotResolvable);

            return 'ok';
        }

        try {
            $variant = TemplateResolver::variantOf($version, $contact, $site);
        } catch (RuntimeException) {
            $this->skip($recipientId, CampaignSkipReason::TemplateNotResolvable);

            return 'ok';
        }

        $class = CampaignSendGate::sendClass($campaign);
        $context = SendContext::campaign($recipient, $class);
        $detail = [
            'template' => TemplateProvenance::from($variant, $contact, $site)->toArray(),
        ];
        $run = new RunContext(subjectBag: SubjectTokenBag::mergeNewsletter(
            SubjectTokenBag::forContact($contact),
            $campaign,
            (string) ($contact->locale ?? ''),
        ));

        try {
            $result = $send->channel === Channel::Email
                ? $this->sendEmail($send, $recipient, $contact, $site, $variant, $run, $context, $class, $detail)
                : $this->sendSms($send, $recipient, $contact, $site, $variant, $run, $context, $class, $detail);
        } catch (ProviderRequestFailed|ChannelNotConfigured $exception) {
            $this->failOrRetry($send, $recipient, $accountId, $rateLimitPerMinute, $exception);

            return 'ok';
        }

        if ($result->wasSuppressed()) {
            $this->skip($recipientId, CampaignSkipReason::Suppressed, $result->messageId);

            return 'ok';
        }

        CampaignRecipient::query()->whereKey($recipientId)->update([
            'status' => CampaignRecipientStatus::Sent->value,
            'message_id' => $result->messageId,
            'processed_at' => now(),
            'skip_reason' => null,
            'error' => null,
            'updated_at' => now(),
        ]);

        return 'ok';
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function sendEmail(
        CampaignSend $send,
        CampaignRecipient $recipient,
        Contact $contact,
        Site $site,
        TemplateVariant $variant,
        RunContext $run,
        SendContext $context,
        SendClass $class,
        array $detail,
    ): SendResult {
        $campaign = $send->campaign;
        $rendered = EmailTemplateRenderer::render(
            $variant,
            $run,
            $send->subject_override,
            false,
            $class,
            $site,
            $contact,
            $campaign?->subscription_list_id,
        );

        if ($campaign?->type === CampaignType::Announcement) {
            $footer = AnnouncementFooter::render($site);
            $appended = MarketingFooter::appendEmail($rendered['html'], $rendered['text'], $footer);
            $rendered['html'] = $appended['html'];
            $rendered['text'] = $appended['text'];
        }

        return $this->email->send(
            new EmailMessage(
                to: [new EmailAddress((string) $recipient->address)],
                subject: $rendered['subject'],
                html: $rendered['html'],
                text: $rendered['text'],
            ),
            $site,
            $contact,
            $context,
            detail: $detail,
        );
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function sendSms(
        CampaignSend $send,
        CampaignRecipient $recipient,
        Contact $contact,
        Site $site,
        TemplateVariant $variant,
        RunContext $run,
        SendContext $context,
        SendClass $class,
        array $detail,
    ): SendResult {
        $rendered = SmsTemplateRenderer::render($variant, $run);
        $body = $rendered['text'];
        $campaign = $send->campaign;
        if ($campaign?->type === CampaignType::Announcement) {
            $footer = AnnouncementFooter::render($site);
            if ($footer['text'] !== '') {
                $body = trim($body) === '' ? $footer['text'] : rtrim($body)."\n".$footer['text'];
            }
        } elseif ($class === SendClass::Marketing && $campaign !== null) {
            $footer = MarketingFooter::render($site, $contact, Channel::Sms, $campaign->subscription_list_id, (string) $recipient->address);
            if ($footer['text'] !== '') {
                $body = trim($body) === '' ? $footer['text'] : rtrim($body)."\n".$footer['text'];
            }
        }

        return $this->sms->send(
            new SmsMessage(to: (string) $recipient->address, body: $body),
            $site,
            $contact,
            $context,
            detail: $detail,
        );
    }

    private function failOrRetry(
        CampaignSend $send,
        CampaignRecipient $recipient,
        int $accountId,
        int $rateLimitPerMinute,
        ProviderRequestFailed|ChannelNotConfigured $exception,
    ): void {
        $attempts = (int) $recipient->attempts;
        if ($attempts >= 3) {
            CampaignRecipient::query()->whereKey($recipient->id)->update([
                'status' => CampaignRecipientStatus::Failed->value,
                'error' => mb_substr($exception->getMessage(), 0, 2000),
                'processed_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        $this->releaseClaim((int) $recipient->id, true);
        $delay = $attempts <= 1 ? now()->addMinute() : now()->addMinutes(5);
        SendCampaignBatch::dispatch($send->id, [(int) $recipient->id], $accountId, $rateLimitPerMinute)->delay($delay);
    }

    /**
     * @param  list<int>  $recipientIds
     */
    private function defer(CampaignSend $send, array $recipientIds, int $accountId, int $rateLimitPerMinute): void
    {
        if ($recipientIds === [] || self::$deferDepth > 0) {
            return;
        }

        $recipient = CampaignRecipient::query()->with('site')->find($recipientIds[0]);
        $site = $recipient?->site;
        $settings = Setting::general();
        $opening = SiteClock::nextWindowOpening(
            $site instanceof Site ? $site : null,
            $settings->sendWindowStart,
            $settings->sendWindowEnd,
        );

        self::$deferDepth++;
        try {
            SendCampaignBatch::dispatch($send->id, $recipientIds, $accountId, $rateLimitPerMinute)->delay($opening);
        } finally {
            self::$deferDepth--;
        }
    }

    private function claim(int $recipientId): bool
    {
        $updated = DB::table('campaign_recipients')
            ->where('id', $recipientId)
            ->where('status', CampaignRecipientStatus::Pending->value)
            ->update([
                'status' => CampaignRecipientStatus::Claimed->value,
                'claimed_at' => now(),
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => now(),
            ]);

        return $updated > 0;
    }

    private function releaseClaim(int $recipientId, bool $keepAttempt): void
    {
        $update = [
            'status' => CampaignRecipientStatus::Pending->value,
            'claimed_at' => null,
            'updated_at' => now(),
        ];
        if (! $keepAttempt) {
            $update['attempts'] = DB::raw('CASE WHEN attempts > 0 THEN attempts - 1 ELSE 0 END');
        }

        DB::table('campaign_recipients')
            ->where('id', $recipientId)
            ->where('status', CampaignRecipientStatus::Claimed->value)
            ->update($update);
    }

    private function skip(int $recipientId, CampaignSkipReason $reason, ?int $messageId = null): void
    {
        CampaignRecipient::query()->whereKey($recipientId)->update([
            'status' => CampaignRecipientStatus::Skipped->value,
            'skip_reason' => $reason->value,
            'message_id' => $messageId,
            'processed_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function campaignStatus(int $campaignId): ?CampaignStatus
    {
        $raw = Campaign::query()->whereKey($campaignId)->value('status');

        return $raw instanceof CampaignStatus ? $raw : CampaignStatus::tryFrom((string) $raw);
    }
}
