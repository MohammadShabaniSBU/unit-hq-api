<?php

declare(strict_types=1);

namespace App\Support\Communications;

use App\Models\Contact;
use App\Models\Contract;
use App\Support\Automation\RunContext;
use App\Support\Automation\SubjectTokenBag;

/**
 * Builds a RunContext for template preview / test-send from a sample contact (+ optional contract).
 */
final class TemplateBuilderContext
{
    public static function for(Contact $contact, ?Contract $contract = null): RunContext
    {
        $bag = SubjectTokenBag::forContact($contact);

        if ($contract instanceof Contract && $contract->contact_id === $contact->id) {
            $bag = array_replace_recursive($bag, SubjectTokenBag::contractBag($contract));
        }

        return new RunContext(subjectBag: $bag, subjectId: $contact->id);
    }
}
