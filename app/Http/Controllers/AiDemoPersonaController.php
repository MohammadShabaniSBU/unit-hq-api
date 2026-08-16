<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ContractStatus;
use App\Http\Resources\AiDemoPersonaResource;
use App\Models\Contact;
use App\Models\Contract;
use App\Support\Auth\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class AiDemoPersonaController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize(Permission::AiAgentUse->value);

        if (! filter_var(config('agents.demo_enabled'), FILTER_VALIDATE_BOOLEAN)) {
            return $this->notFound('errors.agent.demo_disabled');
        }

        $activeStatuses = [
            ContractStatus::AwaitingSignature->value,
            ContractStatus::Pending->value,
            ContractStatus::Active->value,
            ContractStatus::NoticeGiven->value,
        ];

        $contacts = Contact::query()
            ->with([
                'sites',
                'contracts' => fn ($query) => $query
                    ->whereIn('status', $activeStatuses)
                    ->with(['delinquencies' => fn ($d) => $d->whereNull('cured_on')]),
            ])
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate($this->perPage(50));

        $contacts->getCollection()->transform(function (Contact $contact): Contact {
            $contracts = $contact->contracts;
            $contact->setAttribute('has_contract', $contracts->isNotEmpty());
            $contact->setAttribute(
                'has_delinquency',
                $contracts->contains(fn (Contract $contract): bool => $contract->delinquencies->isNotEmpty()),
            );
            $contact->setAttribute(
                'has_balance',
                $contracts->contains(fn (Contract $contract): bool => bccomp($contract->balanceOwed(), '0.00', 2) !== 0),
            );

            return $contact;
        });

        return $this->paginated(
            $contacts->through(fn (Contact $contact) => AiDemoPersonaResource::make($contact)),
            'Demo personas retrieved successfully.',
        );
    }
}
