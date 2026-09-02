<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | JSON keys nullified by contacts:redact
    |--------------------------------------------------------------------------
    |
    | Applied to activity_log.properties, system_events.payload, and
    | automation_run_steps.input/output + automation_runs.trigger_payload
    | for rows whose subject is the contact being redacted.
    |
    | Fiscal identity keys (tax_id, billing_*) are cleared from live contact
    | activity. Issued-invoice buyer snapshots are retained — legal obligation
    | basis; those rows are not subjects of contacts:redact.
    |
    | channel_suppressions are intentionally NOT touched: they key on the
    | normalized channel address (not contact_id). A redacted contact's
    | bounced/complained address must stay suppressed if re-added later.
    |
    | Call wrap-ups / recordings (S12-02): contacts:redact also nulls
    | call_wrapups.note and marks call message recordings unavailable
    | (source_ref.recording_redacted + cleared media URLs). See
    | RedactContactCommand — not a JSON property_keys path.
    |
    | E-sign signed PDFs + certificates (S14-03): retained on the private disk
    | (esign_envelopes.signed_pdf_path / certificate_path). Legal-retention
    | basis — same posture as issued-invoice snapshots. contacts:redact must
    | NOT delete these artifacts; include them in any future GDPR export.
    |
    | AI summaries (S22 / invariant 53): contacts:redact nulls body, highlights,
    | source_counts, and error_code on all ai_summaries rows for the contact and
    | every deal belonging to it (current and superseded). Rows are kept;
    | content is destroyed. See RedactContactCommand — not a property_keys path.
    |
    | contact_verifications (S27-04 / AR-03): the table holds a contact id, a
    | channel id and a code hash — no plaintext PII in JSON properties, so it
    | is not a property_keys entry. It is still a record that a named person
    | was asked to prove identity and belongs in the redaction scope with the
    | rest of the agent tables. AR-03 stays blocking before any provider
    | binding goes to auto.
    |
    | voice_sessions / voice_session_turns (S28-05 / AR-03): caller_number,
    | contact_id, bridge_session_id, and answer_text are voice-channel PII /
    | transcript. Processor audio joins the list if Vocal Bridge retains call
    | audio. contacts:redact does not touch these tables. Customer voice does
    | not launch while AR-03 is open.
    |
    */

    'property_keys' => [
        'email',
        'phone',
        'value',
        'tax_id',
        'tax_id_type',
        'billing_name',
        'billing_address_line1',
        'billing_address_line2',
        'billing_city',
        'billing_postal_code',
        'billing_country_code',
        'old.email',
        'old.phone',
        'old.value',
        'old.tax_id',
        'old.tax_id_type',
        'old.billing_name',
        'old.billing_address_line1',
        'old.billing_address_line2',
        'old.billing_city',
        'old.billing_postal_code',
        'old.billing_country_code',
        'attributes.email',
        'attributes.phone',
        'attributes.value',
        'attributes.tax_id',
        'attributes.tax_id_type',
        'attributes.billing_name',
        'attributes.billing_address_line1',
        'attributes.billing_address_line2',
        'attributes.billing_city',
        'attributes.billing_postal_code',
        'attributes.billing_country_code',
        'old.attributes.email',
        'old.attributes.phone',
        'old.attributes.value',
        'old.attributes.tax_id',
        'old.attributes.tax_id_type',
        'old.attributes.billing_name',
        'old.attributes.billing_address_line1',
        'old.attributes.billing_address_line2',
        'old.attributes.billing_city',
        'old.attributes.billing_postal_code',
        'old.attributes.billing_country_code',
        'to',
        'subject',
        'trigger_payload.attributes.email',
        'trigger_payload.attributes.phone',
        'input.to',
        'input.subject',
        'output.to',
        'output.subject',
    ],

];
