<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\CampaignRecipientStatus;
use App\Enums\CampaignSendStatus;
use App\Enums\CampaignSkipReason;
use App\Enums\CampaignStatus;
use App\Enums\CampaignType;
use App\Enums\ContactChannelType;
use App\Enums\TemplateChannel;
use App\Enums\TemplatePurpose;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignSend;
use App\Models\ConsentText;
use App\Models\Contact;
use App\Models\ContactChannel;
use App\Models\Employee;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\Setting;
use App\Models\SubscriptionList;
use App\Models\TemplateFamily;
use App\Support\Communications\Channel;
use App\Support\Communications\EmailBlockDocument;
use App\Support\Communications\MessageDirection;
use App\Support\Communications\MessageSource;
use App\Support\Communications\MessageStatus;
use App\Support\Marketing\ConsentChannel;
use App\Support\Marketing\ConsentSource;
use App\Support\Marketing\ConsentState;
use App\Support\Marketing\ConsentWriter;
use Carbon\CarbonImmutable;
use Database\Factories\TemplateFamilyFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Grants marketing and ads consent for every contact, then plants a few
 * already-sent campaigns and newsletter issues so the report is not empty.
 * No provider send. No network.
 */
final class MarketingDemo
{
    private const ANCHOR_NAME = 'Oferta de primavera';

    private const SAMPLE = 40;

    public static function apply(): void
    {
        self::seedTexts();
        self::grantAll();
        self::broadcasts();
    }

    private static function seedTexts(): void
    {
        foreach (self::texts() as $text) {
            ConsentText::query()->firstOrCreate(
                [
                    'key' => $text['key'],
                    'version' => $text['version'],
                    'locale' => $text['locale'],
                ],
                $text,
            );
        }
    }

    /**
     * @return list<array{key: string, version: int, locale: string, body: string, created_by: null}>
     */
    private static function texts(): array
    {
        return [
            [
                'key' => 'newsletter',
                'version' => 1,
                'locale' => 'en',
                'body' => 'I agree to receive the occasional newsletter by email.',
                'created_by' => null,
            ],
            [
                'key' => 'newsletter',
                'version' => 1,
                'locale' => 'es',
                'body' => 'Acepto recibir el boletín ocasional por email.',
                'created_by' => null,
            ],
            [
                'key' => 'newsletter',
                'version' => 1,
                'locale' => 'fr',
                'body' => 'J’accepte de recevoir la newsletter occasionnelle par e-mail.',
                'created_by' => null,
            ],
            [
                'key' => 'promotions',
                'version' => 1,
                'locale' => 'en',
                'body' => 'I agree to receive offers and promotions by email and SMS.',
                'created_by' => null,
            ],
            [
                'key' => 'promotions',
                'version' => 1,
                'locale' => 'es',
                'body' => 'Acepto recibir ofertas y promociones por email y SMS.',
                'created_by' => null,
            ],
            [
                'key' => 'promotions',
                'version' => 1,
                'locale' => 'fr',
                'body' => 'J’accepte de recevoir des offres et promotions par e-mail et SMS.',
                'created_by' => null,
            ],
        ];
    }

    private static function grantAll(): void
    {
        $lists = self::lists();
        $texts = self::textIds();

        Contact::query()->with('channels')->chunkById(200, function (Collection $contacts) use ($lists, $texts): void {
            foreach ($contacts as $contact) {
                if (! $contact instanceof Contact) {
                    continue;
                }
                self::grantContact($contact, $lists, $texts);
            }
        });
    }

    /**
     * @param  array<string, SubscriptionList>  $lists
     * @param  array<string, array<string, int>>  $texts
     */
    private static function grantContact(Contact $contact, array $lists, array $texts): void
    {
        $locale = $contact->locale;
        $advertising = $lists['advertising'] ?? null;
        $adsText = self::textId($texts, 'advertising', $locale);
        if ($advertising instanceof SubscriptionList && $adsText !== null) {
            self::grant($contact, ConsentChannel::Ads, $advertising, $adsText);
        }

        $email = trim((string) $contact->email);
        if ($email === '') {
            return;
        }

        $newsletter = $lists['newsletter'] ?? null;
        $newsletterText = self::textId($texts, 'newsletter', $locale);
        if ($newsletter instanceof SubscriptionList && $newsletterText !== null) {
            self::grant($contact, ConsentChannel::Email, $newsletter, $newsletterText);
        }

        $promotions = $lists['promotions'] ?? null;
        $promotionsText = self::textId($texts, 'promotions', $locale);
        if (! $promotions instanceof SubscriptionList || $promotionsText === null) {
            return;
        }

        self::grant($contact, ConsentChannel::Email, $promotions, $promotionsText);

        if (self::smsAddress($contact) !== null) {
            self::grant($contact, ConsentChannel::Sms, $promotions, $promotionsText);
        }
    }

    private static function grant(Contact $contact, ConsentChannel $channel, SubscriptionList $list, int $textId): void
    {
        if (ConsentState::isGranted($contact->id, $channel, $list->id)) {
            return;
        }

        ConsentWriter::grant(
            $contact,
            $channel,
            $list,
            ConsentSource::Import,
            $textId,
            [
                'import_batch' => 'demo-world',
                'original_source' => 'demo',
            ],
            occurredAt: Carbon::parse($contact->created_at ?? now()),
        );
    }

    private static function broadcasts(): void
    {
        if (Campaign::query()->where('name', self::ANCHOR_NAME)->exists()) {
            return;
        }

        $lists = self::lists();
        $promotions = $lists['promotions'] ?? null;
        $newsletter = $lists['newsletter'] ?? null;
        if (! $promotions instanceof SubscriptionList || ! $newsletter instanceof SubscriptionList) {
            return;
        }

        /** @var Collection<int, Contact> $contacts */
        $contacts = Contact::query()
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->with('channels')
            ->orderBy('id')
            ->limit(self::SAMPLE)
            ->get();
        if ($contacts->isEmpty()) {
            return;
        }

        $offer = self::emailFamily(
            'Demo oferta email',
            TemplatePurpose::Marketing,
            'Una oferta para tu trastero',
            'Oferta de primavera',
            '<p>Este mes el primer mes tiene un descuento si reservas online.</p>',
        );
        $reminder = self::emailFamily(
            'Demo recordatorio email',
            TemplatePurpose::Marketing,
            'Seguimos con sitio libre',
            'Recordatorio',
            '<p>Aún hay unidades libres en tu centro. Responde a este email si quieres una oferta.</p>',
        );
        $sms = self::smsFamily();
        $bulletin = self::emailFamily(
            'Demo boletín',
            TemplatePurpose::Newsletter,
            'Novedades de Keevaris',
            'Boletín',
            '<p>Horarios, obras y una nota del equipo del centro.</p>',
        );

        $anchor = CarbonImmutable::parse(CastExecutor::SIM_END)->startOfDay()->setTime(9, 0);
        /** @var array<string, MessageThread> $threads */
        $threads = [];

        self::sent(
            CampaignType::Newsletter,
            'Boletín de febrero',
            $newsletter,
            1,
            $anchor->subMonths(5),
            [['channel' => Channel::Email, 'family' => $bulletin]],
            $contacts,
            unsubscribe: true,
            threads: $threads,
        );
        self::sent(
            CampaignType::Campaign,
            self::ANCHOR_NAME,
            $promotions,
            null,
            $anchor->subMonths(3),
            [['channel' => Channel::Email, 'family' => $offer]],
            $contacts,
            unsubscribe: false,
            threads: $threads,
        );
        self::sent(
            CampaignType::Newsletter,
            'Boletín de abril',
            $newsletter,
            2,
            $anchor->subMonths(3)->addDays(4),
            [['channel' => Channel::Email, 'family' => $bulletin]],
            $contacts,
            unsubscribe: false,
            threads: $threads,
        );
        self::sent(
            CampaignType::Campaign,
            'Recordatorio SMS',
            $promotions,
            null,
            $anchor->subMonths(2),
            [
                ['channel' => Channel::Email, 'family' => $reminder],
                ['channel' => Channel::Sms, 'family' => $sms],
            ],
            $contacts,
            unsubscribe: false,
            threads: $threads,
        );
        self::sent(
            CampaignType::Newsletter,
            'Boletín de junio',
            $newsletter,
            3,
            $anchor->subMonth(),
            [['channel' => Channel::Email, 'family' => $bulletin]],
            $contacts,
            unsubscribe: false,
            threads: $threads,
        );
        self::scheduled(
            'Boletín de julio',
            $newsletter,
            4,
            $anchor->subDays(7),
            $bulletin,
        );
    }

    /**
     * @param  list<array{channel: Channel, family: TemplateFamily}>  $channels
     * @param  Collection<int, Contact>  $contacts
     * @param  array<string, MessageThread>  $threads
     */
    private static function sent(
        CampaignType $type,
        string $name,
        SubscriptionList $list,
        ?int $issue,
        CarbonImmutable $at,
        array $channels,
        Collection $contacts,
        bool $unsubscribe,
        array &$threads,
    ): void {
        $campaign = self::campaign($type, $name, $list, $issue, CampaignStatus::Sent, $at, $contacts->count());

        foreach ($channels as $channel) {
            $send = self::send(
                $campaign,
                $channel['channel'],
                $channel['family'],
                CampaignSendStatus::Completed,
                $at,
            );
            self::fill($send, $contacts, $channel['channel'], $at, $unsubscribe, $threads);
        }
    }

    private static function scheduled(
        string $name,
        SubscriptionList $list,
        int $issue,
        CarbonImmutable $at,
        TemplateFamily $family,
    ): void {
        $campaign = self::campaign(
            CampaignType::Newsletter,
            $name,
            $list,
            $issue,
            CampaignStatus::Scheduled,
            $at,
            0,
        );
        self::send($campaign, Channel::Email, $family, CampaignSendStatus::Scheduled, $at);
    }

    private static function campaign(
        CampaignType $type,
        string $name,
        SubscriptionList $list,
        ?int $issue,
        CampaignStatus $status,
        CarbonImmutable $at,
        int $estimated,
    ): Campaign {
        $employeeId = Employee::query()->orderBy('id')->value('id');
        $employeeId = $employeeId === null ? null : (int) $employeeId;
        $when = Carbon::parse($at);

        return Campaign::query()->create([
            'type' => $type,
            'name' => $name,
            'status' => $status,
            'subscription_list_id' => $list->id,
            'segment_id' => null,
            'site_ids' => [],
            'audience_snapshot' => self::snapshot($list),
            'estimated_recipients' => $estimated,
            'newsletter_issue_number' => $issue,
            'ignore_send_window' => false,
            'approval_required' => false,
            'submitted_by' => $employeeId,
            'submitted_at' => $when->copy()->subDay(),
            'approved_by' => $employeeId,
            'approved_at' => $when->copy()->subDay(),
            'created_by' => $employeeId,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function snapshot(SubscriptionList $list): array
    {
        $settings = Setting::marketing();

        return [
            'list_id' => $list->id,
            'segment_filter' => null,
            'site_ids' => [],
            'exclusions' => [
                'delinquent' => $settings->excludeDelinquent,
                'playbook_kinds' => array_values($settings->excludeActivePlaybookKinds),
            ],
            'frequency_cap' => [
                'count' => $settings->frequencyCapCount,
                'days' => $settings->frequencyCapDays,
                'newsletter_bypasses_cap' => $settings->newsletterBypassesCap,
            ],
        ];
    }

    private static function send(
        Campaign $campaign,
        Channel $channel,
        TemplateFamily $family,
        CampaignSendStatus $status,
        CarbonImmutable $at,
    ): CampaignSend {
        $when = Carbon::parse($at);
        $done = $status === CampaignSendStatus::Completed;

        return CampaignSend::query()->create([
            'campaign_id' => $campaign->id,
            'channel' => $channel,
            'template_family_id' => $family->id,
            'template_version_id' => self::versionId($family),
            'scheduled_at' => $when,
            'status' => $status,
            'materialised_at' => $done ? $when : null,
            'completed_at' => $done ? $when->copy()->addHours(2) : null,
        ]);
    }

    /**
     * @param  Collection<int, Contact>  $contacts
     * @param  array<string, MessageThread>  $threads
     */
    private static function fill(
        CampaignSend $send,
        Collection $contacts,
        Channel $channel,
        CarbonImmutable $at,
        bool $unsubscribe,
        array &$threads,
    ): void {
        $total = $contacts->count();
        $index = 0;
        $unsubscribed = 0;

        foreach ($contacts as $contact) {
            $address = self::address($contact, $channel);
            $outcome = self::outcome($index, $total);
            $position = $index;
            $index++;
            if ($address === null) {
                continue;
            }

            $processed = Carbon::parse($at)->addHours($position % 6);
            if ($outcome !== 'sent') {
                CampaignRecipient::query()->create([
                    'campaign_send_id' => $send->id,
                    'contact_id' => $contact->id,
                    'address' => $address,
                    'status' => $outcome === 'failed'
                        ? CampaignRecipientStatus::Failed
                        : CampaignRecipientStatus::Skipped,
                    'skip_reason' => match ($outcome) {
                        'skipped_cap' => CampaignSkipReason::FrequencyCapped,
                        'skipped_delinquent' => CampaignSkipReason::ExcludedDelinquent,
                        default => null,
                    },
                    'processed_at' => $processed,
                    'error' => $outcome === 'failed' ? 'demo provider failure' : null,
                    'attempts' => $outcome === 'failed' ? 3 : 0,
                ]);

                continue;
            }

            $message = self::message($contact, $channel, $address, $processed, $position, $threads);
            CampaignRecipient::query()->create([
                'campaign_send_id' => $send->id,
                'contact_id' => $contact->id,
                'address' => $address,
                'status' => CampaignRecipientStatus::Sent,
                'message_id' => $message->id,
                'claimed_at' => $processed->copy()->subMinute(),
                'processed_at' => $processed,
                'attempts' => 1,
            ]);

            if (! $unsubscribe || $channel !== Channel::Email || $unsubscribed >= 2) {
                continue;
            }

            self::withdrawAndRestore($contact, $message, $processed);
            $unsubscribed++;
        }
    }

    /**
     * @param  array<string, MessageThread>  $threads
     */
    private static function message(
        Contact $contact,
        Channel $channel,
        string $address,
        Carbon $processed,
        int $index,
        array &$threads,
    ): Message {
        $thread = self::thread($contact, $channel, $processed, $threads);
        $opened = $index % 2 === 0;
        $clicked = $index % 4 === 0;
        $events = [
            [
                'status' => 'delivered',
                'occurred_at' => $processed->copy()->subMinutes(2)->toIso8601String(),
            ],
        ];
        if ($opened) {
            $events[] = [
                'status' => 'opened',
                'occurred_at' => $processed->copy()->addMinutes(15)->toIso8601String(),
            ];
        }
        if ($clicked) {
            $events[] = [
                'status' => 'clicked',
                'occurred_at' => $processed->copy()->addMinutes(40)->toIso8601String(),
            ];
        }

        return Message::query()->create([
            'message_thread_id' => $thread->id,
            'direction' => MessageDirection::Outbound,
            'status' => MessageStatus::Delivered,
            'body_text' => $channel === Channel::Sms
                ? 'Keevaris: aún hay sitio libre. Responde SI si quieres la oferta.'
                : 'Novedades y una oferta de tu centro Keevaris.',
            'from_address' => $channel === Channel::Sms ? 'Keevaris' : 'noticias@demo.keevaris.test',
            'to_address' => $address,
            'source' => MessageSource::Campaign,
            'delivery_events' => $events,
            'sent_at' => $processed,
            'auto_generated' => true,
        ]);
    }

    /**
     * @param  array<string, MessageThread>  $threads
     */
    private static function thread(Contact $contact, Channel $channel, Carbon $at, array &$threads): MessageThread
    {
        $key = $contact->id.':'.$channel->value;
        $existing = $threads[$key] ?? null;
        if ($existing instanceof MessageThread) {
            return $existing;
        }

        $thread = MessageThread::query()->create([
            'contact_id' => $contact->id,
            'channel' => $channel->value,
            'subject' => $channel === Channel::Email ? 'Keevaris' : null,
            'last_message_at' => $at,
            'unread_count' => 0,
        ]);
        $threads[$key] = $thread;

        return $thread;
    }

    private static function withdrawAndRestore(Contact $contact, Message $message, Carbon $processed): void
    {
        $list = SubscriptionList::query()->where('key', 'newsletter')->first();
        if (! $list instanceof SubscriptionList) {
            return;
        }

        $textId = self::textId(self::textIds(), 'newsletter', $contact->locale);
        if ($textId === null) {
            return;
        }

        $when = $processed->copy()->addHour();
        ConsentWriter::withdraw(
            $contact,
            ConsentChannel::Email,
            $list,
            ConsentSource::OneClick,
            ['message_id' => $message->id],
            occurredAt: $when,
        );
        ConsentWriter::grant(
            $contact,
            ConsentChannel::Email,
            $list,
            ConsentSource::Import,
            $textId,
            [
                'import_batch' => 'demo-world',
                'original_source' => 'demo',
            ],
            occurredAt: $when->copy()->addMinute(),
        );
    }

    private static function outcome(int $index, int $total): string
    {
        if ($total < 8) {
            return 'sent';
        }

        $fromEnd = $total - $index;
        if ($fromEnd === 1) {
            return 'failed';
        }
        if ($fromEnd === 2) {
            return 'skipped_delinquent';
        }
        if ($fromEnd === 3) {
            return 'skipped_cap';
        }

        return 'sent';
    }

    private static function address(Contact $contact, Channel $channel): ?string
    {
        if ($channel === Channel::Email) {
            $email = trim((string) $contact->email);

            return $email === '' ? null : $email;
        }

        if ($channel === Channel::Sms) {
            return self::smsAddress($contact);
        }

        return null;
    }

    private static function smsAddress(Contact $contact): ?string
    {
        $channel = $contact->channels->first(
            static fn (ContactChannel $row): bool => $row->type === ContactChannelType::Sms,
        );
        if (! $channel instanceof ContactChannel) {
            return null;
        }

        $value = trim($channel->value);

        return $value === '' ? null : $value;
    }

    private static function emailFamily(
        string $name,
        TemplatePurpose $purpose,
        string $subject,
        string $heading,
        string $html,
    ): TemplateFamily {
        $blocks = EmailBlockDocument::validate([
            'version' => 1,
            'blocks' => [
                [
                    'id' => 'heading',
                    'type' => 'heading',
                    'params' => ['text' => $heading, 'level' => 1],
                ],
                [
                    'id' => 'body',
                    'type' => 'paragraph',
                    'params' => ['html' => $html],
                ],
            ],
        ]);

        return TemplateFamilyFactory::published([
            'name' => $name,
            'channel' => TemplateChannel::Email,
            'purpose' => $purpose,
        ], [
            ['locale' => 'es', 'subject' => $subject, 'blocks' => $blocks],
            ['locale' => 'en', 'subject' => $subject, 'blocks' => $blocks],
        ]);
    }

    private static function smsFamily(): TemplateFamily
    {
        return TemplateFamilyFactory::published([
            'name' => 'Demo recordatorio SMS',
            'channel' => TemplateChannel::Sms,
            'purpose' => TemplatePurpose::Marketing,
        ], [[
            'locale' => 'es',
            'body_text' => 'Keevaris: aún hay sitio libre. Responde SI si quieres la oferta.',
        ]]);
    }

    private static function versionId(TemplateFamily $family): int
    {
        $family->load('currentVersion');
        $id = $family->currentVersion?->id;
        if ($id === null) {
            throw new RuntimeException("Demo template {$family->name} has no published version.");
        }

        return (int) $id;
    }

    /**
     * @return array<string, SubscriptionList>
     */
    private static function lists(): array
    {
        return SubscriptionList::query()
            ->whereIn('key', ['advertising', 'newsletter', 'promotions'])
            ->get()
            ->keyBy('key')
            ->all();
    }

    /**
     * @return array<string, array<string, int>>
     */
    private static function textIds(): array
    {
        $map = [];
        $rows = ConsentText::query()->where('version', 1)->get(['id', 'key', 'locale']);
        foreach ($rows as $row) {
            $map[$row->key][$row->locale] = (int) $row->id;
        }

        return $map;
    }

    /**
     * @param  array<string, array<string, int>>  $texts
     */
    private static function textId(array $texts, string $key, ?string $locale): ?int
    {
        $locale = ($locale !== null && $locale !== '') ? $locale : 'en';

        return $texts[$key][$locale] ?? $texts[$key]['en'] ?? null;
    }
}
