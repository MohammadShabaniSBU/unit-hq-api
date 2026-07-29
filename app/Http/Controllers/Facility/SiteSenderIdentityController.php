<?php

declare(strict_types=1);

namespace App\Http\Controllers\Facility;

use App\Enums\LogChannel;
use App\Http\Controllers\Controller;
use App\Http\Resources\SiteSenderIdentityResource;
use App\Models\CommunicationAccount;
use App\Models\Site;
use App\Models\SiteSenderIdentity;
use App\Support\Auth\SiteAccess;
use App\Support\Communications\AccountScope;
use App\Support\Communications\Channel;
use App\Support\RecordsActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Site Integrations tab — sender identity fields only (no secrets).
 * The provider credential itself lives on the company CommunicationAccount.
 */
class SiteSenderIdentityController extends Controller
{
    public function index(Site $site): JsonResponse
    {
        $identities = $site->senderIdentities()->get()
            ->keyBy(fn (SiteSenderIdentity $identity) => $identity->channel->value);

        $rows = collect(Channel::implemented())
            ->map(fn (Channel $channel) => $identities->get($channel->value) ?? new SiteSenderIdentity([
                'site_id' => $site->id,
                'channel' => $channel,
            ]));

        return $this->success(
            SiteSenderIdentityResource::collection($rows),
            'Sender identities retrieved successfully.'
        );
    }

    public function update(Request $request, Site $site, Channel $channel): JsonResponse
    {
        abort_unless(SiteAccess::canManageSite($request->user(), $site), 403);

        if (! $channel->isImplemented()) {
            abort(404);
        }

        $validated = $request->validate([
            'from_name' => ['nullable', 'string', 'max:255'],
            'from_email' => ['nullable', 'email', 'max:255'],
            'from_number' => ['nullable', 'string', 'max:50'],
            'reply_to_email' => ['nullable', 'email', 'max:255'],
        ]);

        $companyAccount = CommunicationAccount::query()
            ->where('scope', AccountScope::Company)
            ->whereNull('site_id')
            ->where('channel', $channel)
            ->where('is_active', true)
            ->first();

        $identity = SiteSenderIdentity::query()->updateOrCreate(
            ['site_id' => $site->id, 'channel' => $channel->value],
            [
                'account_id' => $companyAccount?->id,
                'from_name' => $validated['from_name'] ?? null,
                'from_email' => $validated['from_email'] ?? null,
                'from_number' => $validated['from_number'] ?? null,
                'reply_to_email' => $validated['reply_to_email'] ?? null,
            ]
        );

        RecordsActivity::log(LogChannel::Comms, 'sender_identity.updated', $site, [
            'channel' => $channel->value,
        ]);

        return $this->success(
            SiteSenderIdentityResource::make($identity),
            'Sender identity updated successfully.'
        );
    }
}
