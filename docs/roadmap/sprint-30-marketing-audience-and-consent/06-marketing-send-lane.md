# S30-06 — Marketing send lane

## 1. Provider purpose

```sql
ALTER TABLE communication_accounts ADD COLUMN purpose VARCHAR(16) NOT NULL DEFAULT 'all';
-- all | transactional | marketing
```

(Edit the create migration in place if still pre-production; otherwise a new
migration.)

`ProviderResolver::resolve(Channel $ch, ?Site $site, SendClass $class = Transactional)`:
for marketing, prefer an active `purpose = marketing` account (site, then company),
then fall back to `purpose = all`. **Never** to `purpose = transactional`. Transactional
resolves `transactional`, then `all`. Pass `SendContext->class` from all senders.

If no account is eligible for marketing → `ChannelNotConfigured::forMarketing()`;
S31 shows it at scheduling time, not at send.

Facility → Communications settings: purpose selector per account, helper text
explaining Postmark broadcast streams.

## 2. Message source

`MessageSource::Campaign = 'campaign'`; `SendContext::campaign(CampaignRecipient $r,
SendClass $class)` with `sourceRef = {campaign_id, campaign_send_id,
campaign_recipient_id}`. (The factory lands now with a nullable model reference; S31
wires it.)

## 3. Marketing footer

`MarketingFooter::render(Site $site, Contact $contact, Channel $ch, ?int $listId)`:

- **Email**: legal name, address, tax id from the site's `legal_entity`; "Why am I
  receiving this?" (list label); links `{{preferences_url}}` and
  `{{unsubscribe_url}}`. Appended by `EmailTemplateRenderer` when the render context
  is marketing class — not a block, not removable.
- **SMS**: short line `"{trading_name}: baja {short_preferences_url}"` (locale-aware).
  Note it costs characters; the S31 segment counter includes it.

## 4. Marketing settings

Extend `GeneralSettings` (or a new `MarketingSettings` group):

| Key | Default | Use (S31) |
|---|---|---|
| `marketing_frequency_cap_count` | 2 | max marketing messages per contact… |
| `marketing_frequency_cap_days` | 7 | …per rolling window |
| `marketing_exclude_delinquent` | true | default exclusion |
| `marketing_exclude_active_playbook_kinds` | ["debt_process"] | skip contacts in these runs |
| `marketing_approval_threshold` | 500 | recipients above which approval is required |

Settings UI: Settings → Marketing.

## Acceptance criteria

- [ ] Marketing send with only a `transactional` account configured → refused.
- [ ] Marketing email contains the legal footer + working preference link; transactional doesn't.
- [ ] Existing transactional senders unchanged (full comms test suite green).
