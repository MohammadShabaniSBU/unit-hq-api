<?php

declare(strict_types=1);

namespace App\Support\Automation;

use App\Enums\ContactChannelType;
use App\Models\AutomationRun;
use App\Models\Contact;
use App\Models\ContactChannel;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\Delinquency;
use App\Models\Site;

/**
 * Resolves contact / contract / site from an automation run subject chain.
 */
final class SubjectChain
{
    public static function contact(AutomationRun $run): ?Contact
    {
        return match ($run->subject_type) {
            'contact' => Contact::query()->find($run->subject_id),
            'deal' => Deal::query()->with('contact')->find($run->subject_id)?->contact,
            'contract' => Contract::query()->with('contact')->find($run->subject_id)?->contact,
            'delinquency' => Delinquency::query()
                ->with('contract.contact')
                ->find($run->subject_id)
                ?->contract
                ?->contact,
            default => null,
        };
    }

    public static function contract(AutomationRun $run): ?Contract
    {
        return match ($run->subject_type) {
            'contract' => Contract::query()->find($run->subject_id),
            'delinquency' => Delinquency::query()
                ->with('contract')
                ->find($run->subject_id)
                ?->contract,
            default => null,
        };
    }

    public static function delinquency(AutomationRun $run): ?Delinquency
    {
        if ($run->subject_type !== 'delinquency') {
            return null;
        }

        return Delinquency::query()->find($run->subject_id);
    }

    public static function site(AutomationRun $run): ?Site
    {
        $site = match ($run->subject_type) {
            'deal' => Deal::query()->with('site')->find($run->subject_id)?->site,
            'contract' => Contract::query()
                ->with('unitItem.item.site')
                ->find($run->subject_id)
                ?->unitItem
                ?->item
                ?->site,
            'delinquency' => Delinquency::query()
                ->with('contract.unitItem.item.site')
                ->find($run->subject_id)
                ?->contract
                ?->unitItem
                ?->item
                ?->site,
            default => null,
        };

        if ($site instanceof Site) {
            return $site;
        }

        // Contact-only (and similar) subjects: fall back to the first site for
        // company-scoped sending in the mono-tenant deployment.
        return Site::query()->orderBy('id')->first();
    }

    public static function primaryChannel(Contact $contact, ContactChannelType $type): ?ContactChannel
    {
        return ContactChannel::query()
            ->where('contact_id', $contact->id)
            ->where('type', $type)
            ->where('is_primary', true)
            ->first();
    }
}
