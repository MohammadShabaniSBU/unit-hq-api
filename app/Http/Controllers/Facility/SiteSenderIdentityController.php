<?php

declare(strict_types=1);

namespace App\Http\Controllers\Facility;

use App\Enums\CommunicationAccountScope;
use App\Enums\CommunicationProviderType;
use App\Enums\LogChannel;
use App\Http\Controllers\Controller;
use App\Http\Resources\SiteSenderIdentityResource;
use App\Models\CommunicationAccount;
use App\Models\Site;
use App\Models\SiteSenderIdentity;
use App\Support\Auth\SiteAccess;
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
            ->keyBy(fn (SiteSenderIdentity $identity) => $identity->provider_type->value);

        $rows = collect(CommunicationProviderType::cases())
            ->map(fn (CommunicationProviderType $type) => $identities->get($type->value) ?? new SiteSenderIdentity([
                'site_id' => $site->id,
                'provider_type' => $type,
            ]));

        return $this->success(
            SiteSenderIdentityResource::collection($rows),
            'Sender identities retrieved successfully.'
        );
    }

    public function update(Request $request, Site $site, CommunicationProviderType $providerType): JsonResponse
    {
        abort_unless(SiteAccess::canManageSite($request->user(), $site), 403);

        $validated = $request->validate([
            'from_name' => ['nullable', 'string', 'max:255'],
            'from_email' => ['nullable', 'email', 'max:255'],
            'from_number' => ['nullable', 'string', 'max:50'],
            'reply_to_email' => ['nullable', 'email', 'max:255'],
        ]);

        $companyAccount = CommunicationAccount::query()
            ->where('scope', CommunicationAccountScope::Company)
            ->where('provider_type', $providerType->value)
            ->first();

        $identity = SiteSenderIdentity::query()->updateOrCreate(
            ['site_id' => $site->id, 'provider_type' => $providerType->value],
            [
                'account_id' => $companyAccount?->id,
                'from_name' => $validated['from_name'] ?? null,
                'from_email' => $validated['from_email'] ?? null,
                'from_number' => $validated['from_number'] ?? null,
                'reply_to_email' => $validated['reply_to_email'] ?? null,
            ]
        );

        RecordsActivity::log(LogChannel::Comms, 'sender_identity.updated', $site, [
            'provider_type' => $providerType->value,
        ]);

        return $this->success(
            SiteSenderIdentityResource::make($identity),
            'Sender identity updated successfully.'
        );
    }
}
