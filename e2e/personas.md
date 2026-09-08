# Personas

Employees created by `DemoRbacGrants` after the demo stage exists. Password for every account is `LOGIN_PASSWORD` from [`environment.md`](environment.md) (`password` after seed).

After `demo:seed --fresh --compact` the world has two sites. **`sm-mad-03`…`sm-mad-05` and `agent-sur` are full-world only** — they are not created. Smoke uses `readonly`, `agent-mad`, and `ops`.

Scenario frontmatter `persona` is the **key** in the first column. Emails are what you type on `/login`.

Page-level redirects (direct URL, not just a hidden nav item):

| Route | Required permission | Redirect if missing |
|---|---|---|
| `/settings/people`, `/settings/roles`, `/settings/roles/new`, `/settings/roles/:id` | `rbac.manage` | `/settings/general` |
| `/settings/ai-agents` | any of `settings.manage`, `ai_agent.use`, `ai_agent_binding.manage` | `/settings/general` |
| `/leasing/agent-approvals` | `agent_action.approve` | `/leasing/contacts` |
| `/leasing/voice-sessions`, `/leasing/voice-sessions/:id` | `ai_agent.use` | `/leasing/contacts` |
| `/demo/chat` | `ai_agent.use` | `/leasing/contacts` |

Billing-run **execution** (`Run billing now`) needs `billing.run.execute`. The list page still loads without it.

After a successful sign-in the panel lands on `/leasing/contacts` unless a `?redirect=` query is present.

## Accounts

| Key | Email | Display name | Role | Scope |
|---|---|---|---|---|
| `manager` | `manager@example.com` | Demo Manager | `owner` | company-wide |
| `ops` | `ops@example.com` | Ops Manager | `operations_manager` | company-wide |
| `sm-mad-01` | `sm-mad-01@example.com` | Site Manager MAD-01 | `site_manager` | MAD-01 |
| `sm-mad-02` | `sm-mad-02@example.com` | Site Manager MAD-02 | `site_manager` | MAD-02 |
| `sm-mad-03` | `sm-mad-03@example.com` | Site Manager MAD-03 | `site_manager` | MAD-03 |
| `sm-mad-04` | `sm-mad-04@example.com` | Site Manager MAD-04 | `site_manager` | MAD-04 |
| `sm-mad-05` | `sm-mad-05@example.com` | Site Manager MAD-05 | `site_manager` | MAD-05 |
| `agent-mad` | `agent-mad@example.com` | Ana López | `leasing_agent` | MAD-01 |
| `agent-norte` | `agent-norte@example.com` | Bea Martín | `leasing_agent` | MAD-02 |
| `agent-sur` | `agent-sur@example.com` | Luis Ortega | `leasing_agent` | MAD-03 |
| `accountant` | `accountant@example.com` | Carmen Contable | `accountant` | company-wide |
| `readonly` | `readonly@example.com` | Rita Lectura | `read_only` | company-wide |

## Should see / should be blocked

### `manager`

- **See:** every route, including People, Roles, credentials, legal entities, billing-run execution, agent approvals, voice sessions.
- **Blocked from:** nothing in the seeded RBAC set.

### `ops`

- **See:** company-wide operations — leasing, inbox, delinquency, billing-run execution, automations, playbooks, Insights. Settings that do not need RBAC / legal entities / credentials.
- **Blocked from:** `/settings/people` and `/settings/roles` (`rbac.manage`). Direct URL redirects to `/settings/general`.

### `sm-mad-01` … `sm-mad-05`

- **See:** leasing, inbox, delinquency, facility, and day-to-day billing **for the granted site only**. Agent approvals and voice sessions (`agent_action.approve`, `ai_agent.use`).
- **Blocked from:** `/settings/people`, `/settings/roles` (`rbac.manage`). Billing-run **execution** (`billing.run.execute` is not on this role). Records that belong to a different site.

### `agent-mad` / `agent-norte` / `agent-sur`

- **See:** contacts, deals, offers, reservations, contracts, units, and inbox **for the granted site only**. Contract signing and offer send.
- **Blocked from:** `/settings/people`, `/settings/roles`. `/leasing/agent-approvals` and `/leasing/voice-sessions` (redirect to `/leasing/contacts`). Billing-run execution. `/billing/delinquency` writes. Automations manage. Records on other sites — use this for S-02.

### `accountant`

- **See:** invoices, payments, billing runs (including **Run billing now**), tax rates, financial reports.
- **Blocked from:** leasing writes (contact / deal / offer / contract manage). `/settings/people`. `/leasing/agent-approvals`. Inbox send.

### `readonly`

- **See:** view-only surfaces the role's `isView()` permissions cover (contacts list, contracts, invoices, automations list, inbox list). Lands on `/leasing/contacts` after login.
- **Blocked from:** every manage / execute / approve permission. Direct URL to `/settings/people` redirects to `/settings/general`. `/leasing/agent-approvals` and `/leasing/voice-sessions` redirect to `/leasing/contacts`. **Run billing now** is hidden. Use this for S-01.
