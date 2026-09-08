# Fixtures

Stable anchors a scenario may name. Everything else must be found by filtering the UI.

`DEMO_SEED` (falls back to `SEED_RNG`) varies the **crowd**, never the **cast**. Naming a crowd contact is a defect: the next seed will not have that person.

## Determinism rule

- **May name:** cast personas in the table below (display name, email, handle), site codes that exist in the seeded world, unit-class **labels**, playbook / automation names `Default debt process` and `Default lead chase`. After `demo:seed --compact` only **MAD-01** and **MAD-02** exist.
- **Must not name:** any contact, deal, offer, contract, or unit that is not in this file.

Search the UI by **display name**, not by PHP class name.

## Naming traps

- Class names (`MarcusWebb`, `TheKellys`) never appear on screen.
- Accented letters are part of the stored name. Type `Lucía Ferrer`, `Sofía Marín`, `Tomás Blanco`, `Inés Valdés`, `Víctor Palencia`, `Javier Peña`, `Rafa Núñez`. An ASCII fallback is a false miss.
- Unit-class **codes** are `SS1`–`SS8` and `AL1`–`AL4`. The UI shows Spanish **labels** (`Trastero 8 m²` for `SS4`). Search by label.
- The Keller story is marketed as "Los Keller"; the contact row is **Patricia Keller** (`company` = `Los Keller`).

## Sites

| Code | Handle | Display name | City |
|---|---|---|---|
| MAD-01 | `madrid` | Madrid Centro | Madrid |
| MAD-02 | `norte` | Madrid Norte | Madrid |
| MAD-03 | `sur` | Madrid Sur | Madrid |
| MAD-04 | `este` | Madrid Este | Madrid |
| MAD-05 | `oeste` | Madrid Oeste | Madrid |

Most of the cast lives on MAD-01. Exceptions used by smoke: **Sofía Marín** is MAD-02. **Diego Hoyos** is authored on MAD-05; in `--compact` that site is missing and his journey falls back to MAD-02 — do not name him as a MAD-05 negative control.

### Compact vs full world

| | `demo:seed --fresh --compact` (E2E) | `demo:seed --fresh` (presenter) |
|---|---|---|
| Sites | MAD-01, MAD-02 | MAD-01 … MAD-05 |
| Clock | 10 days (`2025-06-01` → `2025-06-10`) | ~426 days |
| Crowd | none | ~793 |
| Lucía Ferrer | Open case, ladder started (fee / notice). Ageing **8–14**. Overlock and denied door are **not** required. | Open case, **15–30**, overlocked, denied door, still owing |
| `sm-mad-03`…`05`, `agent-sur` | absent | present |

Smoke scenarios assume the compact column.

## Unit-class labels

| Code | Label |
|---|---|
| SS1 | Trastero 5 m² |
| SS2 | Trastero 6 m² |
| SS3 | Trastero 7 m² |
| SS4 | Trastero 8 m² |
| SS5 | Trastero 9 m² |
| SS6 | Trastero 10 m² |
| SS7 | Trastero 11 m² |
| SS8 | Trastero 12 m² |
| AL1 | Trastero 10 m² XL |
| AL2 | Trastero 12 m² XL |
| AL3 | Trastero 14 m² XL |
| AL4 | Trastero 16 m² XL |

## Cast

Display names and emails come from the journey classes, not from the class identifiers. End states are the deliberate seed-end assertions.

| Handle | Class | Display name | Email | Site | End state |
|---|---|---|---|---|---|
| `marcus` | `MarcusWebb` | Marcos Vega | marcos.vega@demo.keevaris.test | MAD-01 | Active contract, transferred SS4 → SS6, SMS thread asking to enlarge (`ampliar`) |
| `lucia` | `LuciaFerrer` | Lucía Ferrer | lucia.ferrer@demo.keevaris.test | MAD-01 | Open delinquency, 15–30 day bucket, overlocked, denied door event, still owing |
| `tom` | `TomBradley` | Tomás Blanco | tomas.blanco@demo.keevaris.test | MAD-01 | Cured delinquency; promise-kept (`payment_promised` then paid) |
| `amara` | `AmaraOkafor` | Amara Okafor | amara.okafor@demo.keevaris.test | MAD-01 | Active; still inside the long-stay €0 window |
| `jean_luc` | `JeanLucPerrin` | Javier Peña | javier.pena@demo.keevaris.test | MAD-01 | Awaiting contract; envelope declined |
| `sofia` | `SofiaMarin` | Sofía Marín | sofia.marin@demo.keevaris.test | MAD-02 | Awaiting contract; envelope expiring within 3 days |
| `derek` | `DerekHoyle` | Diego Hoyos | diego.hoyos@demo.keevaris.test | MAD-05 | Ended (non-payment vacate) with write-off |
| `pilar` | `PilarSantos` | Pilar Santos | pilar.santos@demo.keevaris.test | MAD-01 | WhatsApp session window open; last inbound near seed-end |
| `hannah` | `HannahCole` | Ana Coloma | ana.coloma@demo.keevaris.test | MAD-01 | Autopay failing (`insufficient_funds` ×2), retry pending |
| `rafa` | `RafaNunez` | Rafa Núñez | rafa.nunez@demo.keevaris.test | MAD-01 | Payment link sent in-thread and paid |
| `ingrid` | `IngridWeiss` | Inés Valdés | ines.valdes@demo.keevaris.test | MAD-01 | Notice given; move-out scheduled next week |
| `omar` | `OmarHaddad` | Omar Haddad | omar.haddad@demo.keevaris.test | MAD-01 | Pending contract; move-in 10 days after seed-end |
| `grace` | `GraceLin` | Gracia Lin | gracia.lin@demo.keevaris.test | MAD-01 | Deal in negotiation; offer viewed, not accepted; lead-chase step 3 of 4 |
| `bea` | `BeaTorres` | Bea Torres | bea.torres@demo.keevaris.test | MAD-01 | Email suppressed (hard bounce); SMS fallback thread |
| `viktor` | `ViktorPalenik` | Víctor Palencia | victor.palencia@demo.keevaris.test | MAD-01 | Cancelled contract (never moved in) + lost deal |
| `nadia` | `NadiaRahal` | Nadia Rahal | nadia.rahal@demo.keevaris.test | MAD-01 | 20% tracking discount; one applied rate change + one scheduled |
| `kellys` | `TheKellys` | Patricia Keller | patricia.keller@demo.keevaris.test | MAD-01 | Two contracts; one vacated last month with deposit deduction. Company: Los Keller |
| `voicemail` | `FrontDeskMisc` | Vera Voicemail | vera.voicemail@demo.keevaris.test | — | Voicemail wrap-up on file |

### Front-desk triage (same journey, not named contacts)

Unmatched inbound the inbox **Triage** tab should list. Safe to recognise by address, not by inventing a contact:

- email `stranger.one@unknown.example` — "Hi, do you have units near the airport?"
- SMS `+34999888777` — "Need a locker for 2 weeks please"
- email `stranger.two@unknown.example` — "Is this the storage place?"
- unknown missed call `+34999000111`

These are cast-authored strangers. Discarding or attaching one is a mutation.

## Playbooks compiled into automations

Demo stage activates and compiles:

- **Default debt process**
- **Default lead chase**

On `/automations` each shows a **Playbook** badge. Opening the editor shows the banner "This graph is compiled from a playbook and is read-only here." The node palette is hidden.
