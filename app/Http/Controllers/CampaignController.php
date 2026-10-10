<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CampaignRecipientStatus;
use App\Enums\CampaignSendStatus;
use App\Enums\CampaignSkipReason;
use App\Enums\CampaignStatus;
use App\Enums\CampaignType;
use App\Http\Resources\CampaignResource;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignSend;
use App\Models\Contact;
use App\Models\Employee;
use App\Models\SubscriptionList;
use App\Models\TemplateFamily;
use App\Support\Auth\Permission;
use App\Support\Communications\Channel;
use App\Support\Communications\Exceptions\CampaignImmutable;
use App\Support\Marketing\CampaignAudienceQuery;
use App\Support\Marketing\CampaignContentPreview;
use App\Support\Marketing\CampaignLifecycle;
use App\Support\Marketing\CampaignReport;
use App\Support\Marketing\CampaignScheduleValidator;
use App\Support\Marketing\CampaignTestSend;
use App\Support\Marketing\SubscriptionListKind;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CampaignController extends Controller
{
    public function __construct(
        private readonly CampaignLifecycle $lifecycle,
        private readonly CampaignAudienceQuery $audience,
        private readonly CampaignTestSend $testSend,
        private readonly CampaignReport $reports,
        private readonly CampaignScheduleValidator $schedule,
        private readonly CampaignContentPreview $contentPreview,
    ) {}

    public function newsletters(Request $request, SubscriptionList $subscriptionList): JsonResponse
    {
        Gate::authorize(Permission::CampaignView->value);
        $this->assertNewsletterList($subscriptionList);

        $employee = $this->employee($request);
        $campaigns = Campaign::query()
            ->where('subscription_list_id', $subscriptionList->id)
            ->where('type', CampaignType::Newsletter)
            ->visibleTo($employee)
            ->with('sends')
            ->orderByRaw('CASE WHEN newsletter_issue_number IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('newsletter_issue_number')
            ->orderByDesc('id')
            ->get();

        $counts = $this->recipientCountsByCampaign($campaigns);

        $issues = $campaigns->map(function (Campaign $campaign) use ($counts): array {
            $completed = $campaign->status === CampaignStatus::Sent
                ? $campaign->sends->max('completed_at')
                : null;
            $scheduled = $campaign->sends->min('scheduled_at');

            return [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'status' => $campaign->status->value,
                'newsletter_issue_number' => $campaign->newsletter_issue_number,
                'sent_at' => $completed === null ? null : Carbon::parse($completed)->toDateTimeString(),
                'scheduled_at' => $scheduled === null ? null : Carbon::parse($scheduled)->toDateTimeString(),
                'counts' => $this->recipientCounts($counts[$campaign->id] ?? []),
            ];
        })->values()->all();

        return $this->success($issues, 'Newsletter issues retrieved successfully.');
    }

    public function nextNewsletter(Request $request, SubscriptionList $subscriptionList): JsonResponse
    {
        Gate::authorize(Permission::CampaignManage->value);
        $this->assertNewsletterList($subscriptionList);

        $employee = $this->employee($request);
        $source = Campaign::query()
            ->where('subscription_list_id', $subscriptionList->id)
            ->where('type', CampaignType::Newsletter)
            ->where('status', CampaignStatus::Sent)
            ->visibleTo($employee, Permission::CampaignManage)
            ->orderByDesc('newsletter_issue_number')
            ->orderByDesc('id')
            ->first();

        if (! $source instanceof Campaign) {
            throw ValidationException::withMessages([
                'subscription_list_id' => [__('errors.campaigns.newsletter_none_sent')],
            ]);
        }

        $copy = $this->lifecycle->duplicate($source, $employee);

        return $this->created(
            new CampaignResource($copy->load('sends.templateVersion')),
            'Newsletter issue created successfully.',
        );
    }

    public function index(Request $request): JsonResponse
    {
        Gate::authorize(Permission::CampaignView->value);

        $employee = $this->employee($request);
        $validated = $request->validate([
            'type' => ['sometimes', 'nullable', Rule::enum(CampaignType::class)],
            'status' => ['sometimes', 'nullable', Rule::enum(CampaignStatus::class)],
            'awaiting_me' => ['sometimes', 'boolean'],
        ]);

        $query = Campaign::query()
            ->with(['sends.templateVersion', 'sends.templateFamily'])
            ->visibleTo($employee)
            ->orderByDesc('id');

        if (! empty($validated['type'])) {
            $query->where('type', $validated['type']);
        }

        if ($request->boolean('awaiting_me')) {
            Gate::authorize(Permission::CampaignApprove->value);
            $query->visibleTo($employee, Permission::CampaignApprove)
                ->where('status', CampaignStatus::PendingApproval)
                ->where('submitted_by', '!=', $employee->id);
        } elseif (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $paginator = $query->paginate($this->perPage());
        $this->reports->decorate($paginator->getCollection());

        return $this->paginated(
            $paginator->through(
                fn (Campaign $campaign) => new CampaignResource($campaign),
            ),
            'Campaigns retrieved successfully.',
        );
    }

    public function approvalCount(Request $request): JsonResponse
    {
        Gate::authorize(Permission::CampaignApprove->value);
        $employee = $this->employee($request);

        $count = Campaign::query()
            ->visibleTo($employee, Permission::CampaignApprove)
            ->where('status', CampaignStatus::PendingApproval)
            ->where('submitted_by', '!=', $employee->id)
            ->count();

        return $this->success(['count' => $count], 'Campaign approval count retrieved successfully.');
    }

    public function show(Campaign $campaign): JsonResponse
    {
        Gate::authorize(Permission::CampaignView->value);
        $this->assertVisible($this->employee(request()), $campaign, Permission::CampaignView);
        $campaign->load(['sends.templateVersion', 'sends.templateFamily', 'creator']);
        $payload = $this->reports->payload($campaign);
        $this->reports->applyListAttributes($campaign, $payload['total']);

        return $this->success(
            new CampaignResource($campaign),
            'Campaign retrieved successfully.',
        );
    }

    public function report(Campaign $campaign): JsonResponse
    {
        Gate::authorize(Permission::CampaignView->value);
        $this->assertVisible($this->employee(request()), $campaign, Permission::CampaignView);

        return $this->success(
            $this->reports->payload($campaign),
            'Campaign report retrieved successfully.',
        );
    }

    public function recipients(Request $request, Campaign $campaign): JsonResponse
    {
        Gate::authorize(Permission::CampaignView->value);
        $this->assertVisible($this->employee($request), $campaign, Permission::CampaignView);

        $validated = $request->validate([
            'status' => ['sometimes', 'nullable', Rule::enum(CampaignRecipientStatus::class)],
            'skip_reason' => ['sometimes', 'nullable', Rule::enum(CampaignSkipReason::class)],
            'q' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);

        $campaign->loadMissing('sends');
        $sendChannels = $campaign->sends->mapWithKeys(
            fn (CampaignSend $send): array => [$send->id => $send->channel->value],
        );

        $query = CampaignRecipient::query()
            ->with('contact:id,first_name,last_name,email')
            ->whereIn('campaign_send_id', $sendChannels->keys())
            ->orderBy('id');

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (! empty($validated['skip_reason'])) {
            $query->where('skip_reason', $validated['skip_reason']);
        }
        if (! empty($validated['q'])) {
            $term = '%'.addcslashes((string) $validated['q'], '%_\\').'%';
            $query->whereHas('contact', function ($contact) use ($term): void {
                $contact->where('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term)
                    ->orWhere('email', 'like', $term);
            });
        }

        $paginator = $query->paginate($this->perPage())->through(function (CampaignRecipient $recipient) use ($sendChannels): array {
            $contact = $recipient->contact;

            return [
                'id' => $recipient->id,
                'campaign_send_id' => $recipient->campaign_send_id,
                'channel' => $sendChannels[$recipient->campaign_send_id] ?? null,
                'contact_id' => $recipient->contact_id,
                'contact_name' => $contact instanceof Contact
                    ? trim($contact->first_name.' '.$contact->last_name)
                    : null,
                'status' => $recipient->status->value,
                'skip_reason' => $recipient->skip_reason?->value,
                'message_id' => $recipient->message_id,
            ];
        });

        return $this->paginated($paginator, 'Campaign recipients retrieved successfully.');
    }

    public function exportRecipients(Request $request, Campaign $campaign): Response
    {
        Gate::authorize(Permission::CampaignView->value);
        $this->assertVisible($this->employee($request), $campaign, Permission::CampaignView);

        $campaign->loadMissing('sends');
        $sendIds = $campaign->sends->modelKeys();

        $recipients = CampaignRecipient::query()
            ->with('contact:id,first_name,last_name,email')
            ->whereIn('campaign_send_id', $sendIds)
            ->orderBy('id')
            ->get();

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return response('', 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="campaign-'.$campaign->id.'-recipients.csv"',
            ]);
        }

        fputcsv($handle, ['name', 'email', 'status', 'skip_reason', 'message_id']);
        foreach ($recipients as $recipient) {
            $contact = $recipient->contact;
            fputcsv($handle, [
                $contact instanceof Contact ? trim($contact->first_name.' '.$contact->last_name) : '',
                $contact instanceof Contact ? (string) ($contact->email ?? '') : '',
                $recipient->status->value,
                $recipient->skip_reason?->value ?? '',
                $recipient->message_id === null ? '' : (string) $recipient->message_id,
            ]);
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return response($csv === false ? '' : $csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="campaign-'.$campaign->id.'-recipients.csv"',
        ]);
    }

    public function previewSend(Request $request, Campaign $campaign, string $channel): JsonResponse
    {
        Gate::authorize(Permission::CampaignView->value);
        $this->assertVisible($this->employee($request), $campaign, Permission::CampaignView);

        $validated = $request->validate([
            'contact_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        return $this->success(
            $this->contentPreview->render(
                $campaign,
                $this->channel($channel),
                isset($validated['contact_id']) ? (int) $validated['contact_id'] : null,
            ),
            'Campaign preview retrieved successfully.',
        );
    }

    public function store(Request $request): JsonResponse
    {
        if ($request->input('type') === CampaignType::Announcement->value) {
            Gate::authorize(Permission::AnnouncementSend->value);
        } else {
            Gate::authorize(Permission::CampaignManage->value);
        }

        $employee = $this->employee($request);
        $validated = $this->validateCampaign($request);
        $type = $validated['type'] instanceof CampaignType
            ? $validated['type']
            : CampaignType::from((string) $validated['type']);
        $this->assertShape($type, $validated);
        $this->assertSites($employee, $this->managePermission($type), $validated['site_ids'] ?? []);

        $campaign = $this->lifecycle->create($employee, [
            ...$validated,
            'type' => $type,
        ]);

        return $this->created(
            new CampaignResource($campaign->load('sends.templateVersion')),
            'Campaign created successfully.',
        );
    }

    public function update(Request $request, Campaign $campaign): JsonResponse
    {
        Gate::authorize($campaign->type === CampaignType::Announcement ? Permission::AnnouncementSend->value : Permission::CampaignManage->value);
        $this->authorizeManage($campaign);
        $employee = $this->employee($request);
        $this->assertVisible($employee, $campaign, $this->managePermission($campaign->type));
        $this->assertDraft($campaign);

        $validated = $this->validateCampaign($request, $campaign);
        $this->assertShape($campaign->type, $validated);
        $this->assertSites($employee, $this->managePermission($campaign->type), $validated['site_ids'] ?? []);

        $campaign->update([
            'name' => $validated['name'],
            'subscription_list_id' => $validated['subscription_list_id'] ?? null,
            'segment_id' => $validated['segment_id'] ?? null,
            'site_ids' => array_values(array_map(intval(...), $validated['site_ids'] ?? [])),
            'ignore_send_window' => (bool) ($validated['ignore_send_window'] ?? false),
        ]);

        return $this->success(
            new CampaignResource($campaign->fresh(['sends.templateVersion'])),
            'Campaign updated successfully.',
        );
    }

    public function upsertSend(Request $request, Campaign $campaign, string $channel): JsonResponse
    {
        Gate::authorize($campaign->type === CampaignType::Announcement ? Permission::AnnouncementSend->value : Permission::CampaignManage->value);
        $this->authorizeManage($campaign);
        $employee = $this->employee($request);
        $this->assertVisible($employee, $campaign, $this->managePermission($campaign->type));
        $this->assertDraft($campaign);

        $sendChannel = $this->channel($channel);
        $validated = $request->validate([
            'template_family_id' => ['required', 'integer', 'exists:template_families,id'],
            'subject_override' => ['sometimes', 'nullable', 'string', 'max:500'],
            'scheduled_at' => ['required'],
        ]);

        if ($sendChannel !== Channel::Email && ! empty($validated['subject_override'])) {
            throw ValidationException::withMessages([
                'subject_override' => [__('errors.campaigns.subject_email_only')],
            ]);
        }

        $family = TemplateFamily::query()->findOrFail($validated['template_family_id']);
        if ($family->channel->value !== $sendChannel->value) {
            throw ValidationException::withMessages([
                'channel' => [__('errors.campaigns.channel_mismatch')],
            ]);
        }

        $scheduledAt = $this->scheduledAt($validated['scheduled_at']);
        $send = CampaignSend::query()->firstOrNew([
            'campaign_id' => $campaign->id,
            'channel' => $sendChannel,
        ]);
        if (! $send->exists) {
            $send->status = CampaignSendStatus::Draft;
        }
        $send->template_family_id = (int) $validated['template_family_id'];
        $send->subject_override = $validated['subject_override'] ?? null;
        $send->scheduled_at = $scheduledAt;

        try {
            $send->save();
        } catch (CampaignImmutable) {
            throw ValidationException::withMessages([
                'status' => [__('errors.campaigns.illegal_transition', [
                    'status' => $campaign->status->value,
                ])],
            ]);
        }

        return $this->success(
            new CampaignResource($campaign->fresh(['sends.templateVersion'])),
            'Campaign send saved successfully.',
        );
    }

    public function destroySend(Campaign $campaign, string $channel): JsonResponse
    {
        Gate::authorize($campaign->type === CampaignType::Announcement ? Permission::AnnouncementSend->value : Permission::CampaignManage->value);
        $this->authorizeManage($campaign);
        $employee = $this->employee(request());
        $this->assertVisible($employee, $campaign, $this->managePermission($campaign->type));
        $this->assertDraft($campaign);

        $campaign->sends()
            ->where('channel', $this->channel($channel)->value)
            ->delete();

        return $this->success(
            new CampaignResource($campaign->fresh(['sends.templateVersion'])),
            'Campaign send removed successfully.',
        );
    }

    public function audiencePreview(Campaign $campaign): JsonResponse
    {
        Gate::authorize(Permission::CampaignView->value);
        $this->assertVisible($this->employee(request()), $campaign, Permission::CampaignView);

        $employee = $this->employee(request());
        $preview = $this->audience->preview($campaign);
        $channels = $preview['channels'] ?? [];
        $estimate = $channels === []
            ? (int) $preview['count']
            : max(array_map(static fn (array $channel): int => (int) $channel['count'], $channels));
        $approval = $this->schedule->approvalPreview($campaign, $employee, $estimate);
        $preview['approval_required'] = $approval['approval_required'];
        $preview['approval_reasons'] = $approval['approval_reasons'];
        $segments = $this->schedule->smsSegmentCount($campaign);
        if ($segments !== null) {
            $preview['sms_segments'] = $segments;
            if (isset($preview['channels'][Channel::Sms->value]) && is_array($preview['channels'][Channel::Sms->value])) {
                $preview['channels'][Channel::Sms->value]['sms_segments'] = $segments;
            }
        }

        return $this->success(
            $preview,
            'Campaign audience preview retrieved successfully.',
        );
    }

    public function testSend(Request $request, Campaign $campaign): JsonResponse
    {
        Gate::authorize($campaign->type === CampaignType::Announcement ? Permission::AnnouncementSend->value : Permission::CampaignManage->value);
        $this->authorizeManage($campaign);
        $employee = $this->employee($request);
        $this->assertVisible($employee, $campaign, $this->managePermission($campaign->type));

        $validated = $request->validate([
            'channel' => ['required', Rule::in([Channel::Email->value, Channel::Sms->value])],
            'to' => ['required', 'array', 'min:1', 'max:5'],
            'to.*' => ['required', 'string', 'max:255'],
            'as_contact_id' => ['sometimes', 'nullable', 'integer', 'exists:contacts,id'],
        ]);

        $sent = $this->testSend->send(
            $campaign,
            Channel::from((string) $validated['channel']),
            array_values($validated['to']),
            isset($validated['as_contact_id']) ? (int) $validated['as_contact_id'] : null,
        );

        return $this->success(['sent' => $sent], 'Test send accepted.');
    }

    public function submit(Campaign $campaign): JsonResponse
    {
        Gate::authorize($campaign->type === CampaignType::Announcement ? Permission::AnnouncementSend->value : Permission::CampaignManage->value);
        $this->authorizeManage($campaign);
        $employee = $this->employee(request());
        $this->assertVisible($employee, $campaign, $this->managePermission($campaign->type));

        $warnings = $this->lifecycle->submit($campaign, $employee);

        return $this->success(
            new CampaignResource($campaign->fresh(['sends.templateVersion'])),
            'Campaign submitted successfully.',
            warnings: $warnings === [] ? null : $warnings,
        );
    }

    public function approve(Campaign $campaign): JsonResponse
    {
        Gate::authorize(Permission::CampaignApprove->value);
        $employee = $this->employee(request());
        $this->assertVisible($employee, $campaign, Permission::CampaignApprove);

        $approved = $this->lifecycle->approve($campaign, $employee);

        return $this->success(
            new CampaignResource($approved->load(['sends.templateVersion', 'sends.templateFamily', 'creator'])),
            'Campaign approved successfully.',
        );
    }

    public function reject(Request $request, Campaign $campaign): JsonResponse
    {
        Gate::authorize(Permission::CampaignApprove->value);
        $employee = $this->employee($request);
        $this->assertVisible($employee, $campaign, Permission::CampaignApprove);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $rejected = $this->lifecycle->reject($campaign, $employee, $validated['reason']);

        return $this->success(
            new CampaignResource($rejected->load(['sends.templateVersion', 'sends.templateFamily', 'creator'])),
            'Campaign rejected successfully.',
        );
    }

    public function pause(Campaign $campaign): JsonResponse
    {
        Gate::authorize($campaign->type === CampaignType::Announcement ? Permission::AnnouncementSend->value : Permission::CampaignManage->value);

        return $this->act($campaign, 'pause', 'Campaign paused successfully.');
    }

    public function resume(Campaign $campaign): JsonResponse
    {
        Gate::authorize($campaign->type === CampaignType::Announcement ? Permission::AnnouncementSend->value : Permission::CampaignManage->value);

        return $this->act($campaign, 'resume', 'Campaign resumed successfully.');
    }

    public function cancel(Campaign $campaign): JsonResponse
    {
        Gate::authorize($campaign->type === CampaignType::Announcement ? Permission::AnnouncementSend->value : Permission::CampaignManage->value);

        return $this->act($campaign, 'cancel', 'Campaign cancelled successfully.');
    }

    public function duplicate(Campaign $campaign): JsonResponse
    {
        Gate::authorize($campaign->type === CampaignType::Announcement ? Permission::AnnouncementSend->value : Permission::CampaignManage->value);
        $this->authorizeManage($campaign);
        $employee = $this->employee(request());
        $this->assertVisible($employee, $campaign, $this->managePermission($campaign->type));

        $copy = $this->lifecycle->duplicate($campaign, $employee);

        return $this->created(
            new CampaignResource($copy->load('sends.templateVersion')),
            'Campaign duplicated successfully.',
        );
    }

    private function act(Campaign $campaign, string $method, string $message): JsonResponse
    {
        $this->authorizeManage($campaign);
        $employee = $this->employee(request());
        $this->assertVisible($employee, $campaign, $this->managePermission($campaign->type));

        $updated = $this->lifecycle->{$method}($campaign, $employee);

        return $this->success(
            new CampaignResource($updated->load(['sends.templateVersion', 'sends.templateFamily', 'creator'])),
            $message,
        );
    }

    private function authorizeManage(Campaign $campaign): void
    {
        if ($campaign->type === CampaignType::Announcement) {
            Gate::authorize(Permission::AnnouncementSend->value);
        } else {
            Gate::authorize(Permission::CampaignManage->value);
        }
    }

    private function managePermission(CampaignType $type): Permission
    {
        return $type === CampaignType::Announcement
            ? Permission::AnnouncementSend
            : Permission::CampaignManage;
    }

    /**
     * @return array<string, mixed>
     */
    private function validateCampaign(Request $request, ?Campaign $existing = null): array
    {
        $rules = [
            'type' => [$existing === null ? 'required' : 'prohibited', Rule::enum(CampaignType::class)],
            'name' => ['required', 'string', 'max:160'],
            'subscription_list_id' => ['nullable', 'integer', 'exists:subscription_lists,id'],
            'segment_id' => ['nullable', 'integer', 'exists:segments,id'],
            'site_ids' => ['sometimes', 'array'],
            'site_ids.*' => ['integer', 'distinct', 'exists:sites,id'],
            'ignore_send_window' => ['sometimes', 'boolean'],
        ];

        $type = $existing?->type;
        if (! $type instanceof CampaignType) {
            $raw = $request->input('type');
            $type = $raw instanceof CampaignType ? $raw : CampaignType::tryFrom((string) $raw);
        }
        if ($type instanceof CampaignType && ! $type->allowsPromotion()) {
            $rules['discount_id'] = ['prohibited'];
        }

        return $request->validate($rules, [
            'discount_id.prohibited' => __('errors.campaigns.discount_prohibited'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function assertShape(CampaignType $type, array $validated): void
    {
        $siteIds = $validated['site_ids'] ?? [];
        $listId = $validated['subscription_list_id'] ?? null;
        $ignoreWindow = (bool) ($validated['ignore_send_window'] ?? false);

        if ($type === CampaignType::Announcement) {
            if ($listId !== null || $siteIds === []) {
                throw ValidationException::withMessages([
                    'site_ids' => [__('errors.campaigns.announcement_shape')],
                ]);
            }

            return;
        }

        if ($listId === null) {
            throw ValidationException::withMessages([
                'subscription_list_id' => [__('errors.campaigns.list_required')],
            ]);
        }

        if ($type === CampaignType::Newsletter) {
            $list = SubscriptionList::query()->find((int) $listId);
            if (! $list instanceof SubscriptionList || $list->kind !== SubscriptionListKind::Newsletter) {
                throw ValidationException::withMessages([
                    'subscription_list_id' => [__('errors.campaigns.newsletter_list_kind')],
                ]);
            }
        }

        if ($ignoreWindow) {
            throw ValidationException::withMessages([
                'ignore_send_window' => [__('errors.campaigns.window_only_announcement')],
            ]);
        }
    }

    /**
     * @param  list<int>  $siteIds
     */
    private function assertSites(Employee $employee, Permission $permission, array $siteIds): void
    {
        $granted = $employee->siteIdsFor($permission);
        if ($granted === null) {
            return;
        }

        foreach (array_map(intval(...), $siteIds) as $siteId) {
            if (! in_array($siteId, $granted, true)) {
                throw ValidationException::withMessages([
                    'site_ids' => [__('errors.campaigns.site_outside_grants')],
                ]);
            }
        }
    }

    private function assertVisible(Employee $employee, Campaign $campaign, Permission $permission): void
    {
        if (! $campaign->sitesWithin($employee->siteIdsFor($permission))) {
            abort(404);
        }
    }

    private function assertDraft(Campaign $campaign): void
    {
        if ($campaign->status !== CampaignStatus::Draft) {
            throw ValidationException::withMessages([
                'status' => [__('errors.campaigns.illegal_transition', [
                    'status' => $campaign->status->value,
                ])],
            ]);
        }
    }

    private function channel(string $channel): Channel
    {
        $parsed = Channel::tryFrom($channel);
        if ($parsed !== Channel::Email && $parsed !== Channel::Sms) {
            throw ValidationException::withMessages([
                'channel' => [__('errors.campaigns.channel_mismatch')],
            ]);
        }

        return $parsed;
    }

    private function scheduledAt(mixed $value): ?Carbon
    {
        if ($value === 'now') {
            return null;
        }

        if (! is_string($value) || strtotime($value) === false) {
            throw ValidationException::withMessages([
                'scheduled_at' => [__('errors.campaigns.scheduled_at')],
            ]);
        }

        $at = Carbon::parse($value);
        if ($at->lt(now()->copy()->addMinutes(2))) {
            throw ValidationException::withMessages([
                'scheduled_at' => [__('errors.campaigns.scheduled_at')],
            ]);
        }

        return $at;
    }

    private function assertNewsletterList(SubscriptionList $list): void
    {
        if ($list->kind !== SubscriptionListKind::Newsletter) {
            throw ValidationException::withMessages([
                'subscription_list_id' => [__('errors.campaigns.newsletter_list_kind')],
            ]);
        }
    }

    /**
     * @param  Collection<int, Campaign>  $campaigns
     * @return array<int, array<string, int>>
     */
    private function recipientCountsByCampaign(Collection $campaigns): array
    {
        $sendToCampaign = [];
        foreach ($campaigns as $campaign) {
            foreach ($campaign->sends as $send) {
                $sendToCampaign[$send->id] = $campaign->id;
            }
        }

        if ($sendToCampaign === []) {
            return [];
        }

        $rows = CampaignRecipient::query()
            ->whereIn('campaign_send_id', array_keys($sendToCampaign))
            ->selectRaw('campaign_send_id, status, count(*) as aggregate')
            ->groupBy('campaign_send_id', 'status')
            ->get();

        $counts = [];
        foreach ($rows as $row) {
            $campaignId = $sendToCampaign[(int) $row->campaign_send_id];
            $status = $row->status instanceof CampaignRecipientStatus
                ? $row->status->value
                : (string) $row->status;
            $counts[$campaignId][$status] = ($counts[$campaignId][$status] ?? 0) + (int) $row->aggregate;
        }

        return $counts;
    }

    /**
     * @param  array<string, int>  $raw
     * @return array<string, int>
     */
    private function recipientCounts(array $raw): array
    {
        $counts = [];
        foreach (CampaignRecipientStatus::cases() as $status) {
            $counts[$status->value] = (int) ($raw[$status->value] ?? 0);
        }

        return $counts;
    }

    private function employee(Request $request): Employee
    {
        $user = $request->user();
        if (! $user instanceof Employee) {
            abort(403);
        }

        return $user;
    }
}
