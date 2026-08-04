# S17-08 — People: create, invite, deactivate

## Context

Task 05 built Settings → People to *assign grants* to employees, and explicitly scoped
employee creation out. That was a spec error: a screen that manages access for people you
cannot create is half a feature, and the only way to add staff today is a database insert or
a seeder.

The interesting decision here is not the CRUD — it is how a new employee gets a password.

**Ruling: invitation links, not admin-set passwords.** An owner typing a colleague's initial
password means the owner knows it, and in a product whose value rests on a defensible audit
trail — fiscal invoices, delinquency notices, access grants, all attributed to a causer —
"my manager knew my password" is a repudiation argument against every row that employee ever
caused. The invite costs one small table and buys attribution that holds up. It also matches
the house pattern: crypto-random token, never the PK, expiry checked at read time.

Comms may not be configured on a fresh install, so the invite flow must also produce a
copyable link. That is not a fallback afterthought; it is the primary path on day one of a
deployment, before Brevo or Postmark credentials exist.

## Scope

**In:**
- Create an employee (name, email, initial grants) — no password set by the creator
- `employee_invitations` with crypto token, expiry, single use
- Send invite via existing `EmailSender`, or copy the link when comms is unconfigured
- Public invitation accept page — employee sets their own password
- Resend / revoke invitation
- Deactivate and reactivate; never delete
- Edit own profile and change own password
- Tier-3 activity for every lifecycle event

**Out:**
- Forgot-password (adjacent, deliberately separate — see task 07)
- Bulk import
- Employee avatars

## Schema changes

```sql
CREATE TABLE employee_invitations (
    id           BIGSERIAL PRIMARY KEY,
    employee_id  BIGINT NOT NULL REFERENCES employees(id) ON DELETE CASCADE,
    token_hash   VARCHAR(64) NOT NULL,
    expires_at   TIMESTAMP NOT NULL,
    accepted_at  TIMESTAMP NULL,
    revoked_at   TIMESTAMP NULL,
    invited_by   BIGINT NULL REFERENCES employees(id),
    created_at   TIMESTAMP,
    updated_at   TIMESTAMP
);
CREATE UNIQUE INDEX employee_invitations_token_idx ON employee_invitations (token_hash);
CREATE INDEX employee_invitations_open_idx ON employee_invitations (employee_id)
    WHERE accepted_at IS NULL AND revoked_at IS NULL;

ALTER TABLE employees ADD COLUMN deactivated_at TIMESTAMP NULL;
ALTER TABLE employees ADD COLUMN last_login_at  TIMESTAMP NULL;
ALTER TABLE employees ALTER COLUMN password DROP NOT NULL;  -- null until accepted
```

Store the **hash**, not the token — the raw value is shown once at creation and never
retrievable, same posture as `App\Support\Credentials`. Resend issues a new token and
revokes the old.

`last_login_at` exists so the People list can say "Never signed in", which is the single most
useful column on that screen: it tells an owner whether the invite landed.

Case-insensitive unique email. Postgres: a unique index on `lower(email)`. Check what the
existing employees migration does before adding a second constraint.

## Implementation notes

**Creation is one transaction:** employee row (no password) → grants → invitation → activity.
If email sending is configured, dispatch after commit; if not, the response carries the raw
link. Never send inside the transaction.

**Expiry is read-time** (invariant 13). No sweeper. An expired invitation renders an
explanatory page with a "request a new invite" instruction, not a 404.

**Acceptance** — public, throttled, single-use:

1. Look up by token hash; reject if accepted, revoked, or expired.
2. Employee sets a password (minimum length 12; do not impose character-class rules — they
   produce worse passwords and users write them down).
3. Set `password`, stamp `accepted_at`, issue a token, log them in.
4. Tier-3 `employee.invitation.accepted`.

**Deactivation, never deletion.** Employees are causers on `activity_log` and FK targets on
occupancies, holds, notices, runs and grants. `deactivated_at` plus:

- **Revoke all Sanctum tokens in the same transaction.** Without this a deactivated employee
  keeps working until their token expires, which is the entire point of the action failing
  silently. This is the single most important line in the task.
- Revoke any open invitation.
- Grants are **kept**, not deleted — reactivation restores the person, and the grant history
  stays readable.
- Login refuses a deactivated employee with the same generic credential error, so the form
  stays a non-oracle.

**Last-owner protection extends here.** Deactivating the last company-wide `owner` is
refused by the same guard task 01 built for grant revocation — re-check inside the
transaction with `SELECT … FOR UPDATE`. Owners lock themselves out otherwise, and there is
no recovery path short of tinker.

**Self-service.** An employee may edit their own name and change their own password
(requiring the current one) without `RbacManage` — `Exempt::self` in the task 03 manifest.
They may not edit their own grants; that check is `RbacManage` against the target, and an
owner editing their own grants is allowed but still hits the last-owner floor.

**Activity.** Tier-3 `RecordsActivity::core`: `employee.created`, `employee.invited`,
`employee.invitation.accepted`, `employee.invitation.revoked`, `employee.deactivated`,
`employee.reactivated`, `employee.password.changed`. Properties carry ids and email, **never
the token or password** — same rule as credential events.

## API surface

All under `Permission::RbacManage` unless noted.

```
POST   /api/employees                          { first_name, last_name, email, grants[] }
PATCH  /api/employees/{employee}               { first_name?, last_name? }
POST   /api/employees/{employee}/deactivate
POST   /api/employees/{employee}/reactivate
POST   /api/employees/{employee}/invitations   → resend; returns link when comms unconfigured
DELETE /api/employees/{employee}/invitations/{invitation}   → revoke

PATCH  /api/user                               Exempt::self — own name
POST   /api/user/password                      Exempt::self — { current_password, password }
```

Public, allowlisted in `routes/api.php`, throttled:

```
GET    /api/invitations/{token}     → { email, first_name, expires_at } or 410 when spent
POST   /api/invitations/{token}/accept  { password }
```

`GET /api/employees` gains `status` (`invited` / `active` / `deactivated`), `last_login_at`,
and the grants summary task 05 added. List filter `?status=` defaults to active-plus-invited;
`all` includes deactivated.

The invitation GET returns the invitee's own email only — enough to render "Set up your
account, jamie@…" — and nothing about the organisation.

## Panel surface

**Settings → People** gains a primary "Add person" action opening a drawer:

- First name, last name, work email
- Access: at least one role + site grant, using the same grant editor as task 05. Allow zero
  grants but show an inline warning — "This person will be able to sign in but see nothing" —
  because that state is legitimate for a pending hire and confusing if unexplained.
- Submit → success state showing the invite link with a copy button, *always*, even when the
  email sent. The owner often wants to paste it into a message themselves.

**List columns:** name, email, access chips, status, last sign-in. `Never signed in` renders
in muted text with a "Resend invite" inline action. Deactivated rows are dimmed and sorted
last.

**Row actions:** edit name, manage access (task 05 drawer), resend invite, revoke invite,
deactivate. Deactivate confirms with explicit consequence copy — "They'll be signed out
immediately and won't be able to sign back in." Last-owner refusal renders inline on the row,
not as a toast.

**`pages/invite/[token].vue`** — public, styled as the login page's sibling: same brand panel
and signature, form column reading "Set your password". Shows the invitee's email as static
text. Confirm-password field, minimum-length hint shown up front rather than as an error
after submit. Expired or spent token renders an explanatory state with next steps.

**Self-service** lives in the existing account menu: change name, change password.

i18n `people.*` and `invite.*` across `en` / `es` / `fr`.

## Invariants

- Invariant 6 — invitation links use a crypto-random token, never the PK. Hash at rest.
- Invariant 13 — expiry is read-time. No sweeper job.
- Invariant 15 — activity is append-only; deactivation writes rows, never removes them.
- Archive-only family — employees are deactivated, never hard-deleted; they are causers on
  append-only history.
- Credential-handling posture — the raw invite token is shown once and never retrievable;
  it never appears in activity properties or logs.
- New invariant (next free number):

> **Deactivating an employee revokes their access tokens in the same transaction.** An
> account that cannot sign in but whose issued tokens still authenticate is not deactivated.

## Acceptance criteria

- [ ] An owner can create an employee with grants and no password.
- [ ] The response always includes a copyable invite link, whether or not email was sent.
- [ ] Creating an employee with comms unconfigured succeeds and does not error on send.
- [ ] The invitation link sets a password and signs the employee in, once. A second use
      returns 410 with an explanatory panel state.
- [ ] An expired invitation renders an explanation, not a 404, with no sweeper involved.
- [ ] Resend revokes the previous token; the old link stops working.
- [ ] Deactivation immediately invalidates existing tokens — an in-flight session's next
      request returns 401.
- [ ] A deactivated employee's login attempt returns the same generic error as a wrong
      password.
- [ ] Deactivating the last company-wide owner is refused inline; the account stays active.
- [ ] Reactivation restores the employee with their prior grants intact.
- [ ] An employee can change their own name and password without `RbacManage`, and cannot
      change their own grants without it.
- [ ] Email uniqueness is case-insensitive.
- [ ] Every lifecycle event writes a Tier-3 row; no row contains a token or password.
- [ ] `/invite/:token` is on the panel public allowlist from task 07 and on the API public
      allowlist from task 00, both with reasons.
- [ ] The invitation accept endpoint is throttled.
- [ ] `en.json`, `es.json`, `fr.json` complete; `bun run lint` + `bun run typecheck` pass.

## Tests required

| Test | Asserts |
|---|---|
| `EmployeeCreationTest::creates_employee_with_grants_and_invitation` | One transaction, no password set |
| `EmployeeCreationTest::returns_link_when_comms_unconfigured` | Day-one deployment path |
| `EmployeeCreationTest::rejects_duplicate_email_case_insensitively` | Uniqueness |
| `InvitationTest::accept_sets_password_and_signs_in` | Happy path |
| `InvitationTest::token_is_single_use` | 410 on reuse |
| `InvitationTest::expired_token_rejected_without_sweeper` | Read-time expiry |
| `InvitationTest::resend_revokes_previous_token` | Old link dead |
| `InvitationTest::token_stored_hashed` | Raw value absent from the table |
| `InvitationTest::accept_is_throttled` | 429 |
| `DeactivationTest::revokes_tokens_in_same_transaction` | The one that matters |
| `DeactivationTest::deactivated_login_returns_generic_error` | Non-oracle |
| `DeactivationTest::cannot_deactivate_last_owner` | Lockout floor |
| `DeactivationTest::reactivation_restores_grants` | Grants kept, not deleted |
| `SelfServiceTest::employee_changes_own_password_without_rbac_manage` | `Exempt::self` |
| `SelfServiceTest::employee_cannot_change_own_grants` | 403 |
| `EmployeeActivityTest::lifecycle_events_contain_no_secrets` | Token and password absent from properties |
