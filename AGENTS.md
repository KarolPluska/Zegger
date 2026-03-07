# AGENTS.md - Zegger ERP

## Mission
Build a **production-ready** new plugin called **Zegger ERP** using the provided specification package and the legacy Offer Panel codebase.

This task is:
- not a cosmetic refactor
- not a PoC
- not a partial mockup
- not a documentation-only task

The required outcome is a **working ERP application** where the current **Offer Panel** is preserved as a mature module inside the new system.

## Mandatory reading order
Read and follow the files in `codex_zegger_erp_materials/` in this order:

1. `00_START_HERE_FOR_CODEX.txt`
2. `01_MASTER_SPEC_ZEGGER_ERP.txt`
3. `02_AUTH_COMPANY_USERS_PERMISSIONS_SPEC.txt`
4. `03_PRODUCTS_OFFERS_AND_SOURCES_SPEC.txt`
5. `04_COMMUNICATOR_NOTIFICATIONS_AND_RELATIONS_SPEC.txt`
6. `05_TECHNICAL_ARCHITECTURE_MIGRATION_AND_CALCULATOR_SPEC.txt`
7. `06_TASKLIST_ACCEPTANCE_AND_DELIVERABLES.txt`
8. `07_FILE_BY_FILE_PATCH_STRATEGY.txt`
9. `08_MESSAGE_TO_CODEX.txt`
10. `09_ACCEPTANCE_TEST_MATRIX.txt`

If legacy code conflicts with the specification package, the **specification package is the source of truth**.

## Available inputs
You have access to:

- `codex_zegger_erp_materials/` - full specification package
- `zq-offer-suite/` - extracted legacy plugin source
- `Plugin ZIP/` - contains `zq-offer-suite-v1.2.18.7.zip`
- `Źródło - Kalkulator ogrodzeń ZEGGER v1.9.9.1.html` - current calculator file

## Core product intent
The current Offer Panel is no longer the final standalone product.
The final product is **Zegger ERP**.

Zegger ERP must include:
- ERP shell / app frame
- login and registration
- company creation and joining flow
- users, roles, permissions, impersonation rules
- module dashboard
- Offer Panel as a preserved module
- company management module
- product sources / internal catalog module
- global communicator
- global notifications center
- company-to-company relations
- strict relation-aware offer/chat linking
- updated launcher flow from calculator

## Absolute priorities
### 1. Preserve the Offer Panel quality
The current Offer Panel must remain strong and recognizable.
Do not weaken:
- UX/UI
- offer creation flow
- positions flow
- clients flow
- offer history
- PDF generation
- current working business logic

You may refactor internals heavily if needed, but the result must not regress.

### 2. Ship a real ERP shell
The new system must not feel like legacy modals stitched together.
It must behave like a coherent ERP application with a stable shell and module navigation.

### 3. Replace calculator hosting model
The calculator must stop acting as the heavy legacy host for the old panel.
It should become a **launcher/entry point** into Zegger ERP.

## Execution posture
Operate like a senior production engineer.

That means:
- read first
- inspect legacy carefully
- preserve working behavior where required
- redesign architecture where needed
- prefer maintainable modular code over shortcuts
- do not leave fake active modules or placeholder business flows
- do not improvise around already-specified product rules

## High-level architecture target
### Build a new plugin
Preferred target:
- create a new plugin folder, e.g. `zegger-erp/`

Do **not** continue the old plugin architecture as the main runtime without restructuring.
Use legacy code as:
- behavior reference
- migration source
- UI/UX reference
- code donor for mature modules

### Recommended modular domains
Use a modular structure such as:
- db / migrations
- app shell
- auth
- companies
- company members
- roles / permissions
- relations
- google source sync
- internal catalog
- offers
- offer history / audit
- pdf
- communicator
- notifications
- wp admin
- integration / launcher
- maintenance / retention / cron

## Required application behavior
### Post-login behavior
All users land on the **module dashboard** after successful login.
The dashboard shows:
- only modules visible under that user’s permissions
- summary strip / key updates
- coherent ERP navigation

### Module navigation
Use a persistent ERP shell with topbar and module switching.
Do not force users to leave the app shell just to move between modules.

### Unsaved changes protection
If the user attempts to leave a module with unsaved changes, show a protective confirmation flow such as:
- Save
- Discard
- Cancel

This is especially important for the Offer Panel module.

## Mandatory product rules
### Relation context
Relation context is strict.
If an offer is created in relation **A↔B**, it cannot be linked, sent, or reused inside **A↔C**.
Enforce this **server-side**.
Never rely on UI-only hiding.

### Offer-chat linking
- one offer can be linked to max one chat
- one chat can hold max one linked offer
- a general thread can later receive one matching unbound offer
- once linked, no second offer may be attached to that chat
- linked-offer edits must produce concise but meaningful changelog entries in the thread

### Communicator
The communicator is global.
It must support:
- general company-to-company threads
- offer-linked threads
- thread categories
- pinging one or multiple users by account name
- advisory ping behavior only
- first actual responder becomes handler according to rules
- visible participants/handlers
- unread counters
- mute
- close thread
- reopen thread with reason
- attachments
- attachment retention rules
- full event history

### Notification center
Notifications must be centralized and distinguish event types such as:
- new message
- ping to user
- offer linked to thread
- offer changed
- offer status changed
- join request
- relation invitation
- system events

### Product sources
Each company may have:
- one Google Sheets source
- one internal local catalog

Both may be active together.
For own-company context:
- Google + local catalog products appear together as one list

For relation context:
- the user switches into the related company data context
- only allowed relation context is usable

### SKU / color parsing
Support the agreed `Nr towaru ...` model:
- `Nr towaru` => color variant `Brak`
- `Nr towaru RAL 6000` => color variant `RAL 6000`
- each variant owns its own SKU
- internal catalog must support the same logic model as agreed

### Company ownership rules
- company owner is unique
- owner must not be able to accidentally break their own account by removing critical self-access
- impersonation rules must be explicit and visible in UI
- switching into another account must show a highly visible banner/state

## WordPress engineering rules
- sanitize every input
- escape every output
- use backend capability validation for privileged actions
- use nonces where relevant
- no data leaks in error messages
- no trust in frontend-only permission hiding
- no global CSS that can break host pages
- no unscoped JS globals if avoidable
- preserve mobile usability

## UI/UX rules
- preserve the current visual tone of the Offer Panel
- clean modern grayscale system UI
- coherent shell around modules
- scoped CSS only
- avoid host-page collisions
- avoid horizontal overflow
- keep interaction intuitive and production-grade

## Legacy handling rules
### Legacy Offer Panel
Do not turn the mature Offer Panel into a weaker replacement.
Do not strip features just to simplify the migration.
If the legacy renderer is monolithic, extract/restructure internally, but preserve the result.

### Legacy REST/data behavior
New architecture may use new namespaces and new structures, but preserve compatibility where required for a safe migration and for preserving Offer Panel behavior.

### Existing accounts/data
Follow the specification package regarding migration assumptions.
Do not invent extra migration shortcuts not described in the spec.

## Calculator rules
The file `Źródło - Kalkulator ogrodzeń ZEGGER v1.9.9.1.html` must be updated.

Target behavior:
- calculator no longer hosts the heavy legacy panel/login flow
- calculator becomes a clean launcher entry into Zegger ERP
- final calculator file must be ready to replace the existing one directly

## Required deliverables
Final output must include:
- new plugin source code
- installable plugin ZIP
- updated calculator file
- migration / installation instructions
- rollback notes
- any required SQL / DB migration logic inside the plugin
- explicit mapping to the acceptance matrix

## Recommended execution order
1. Read all spec files.
2. Audit the legacy plugin and calculator.
3. Map legacy features to new ERP architecture.
4. Design DB and module structure.
5. Implement ERP shell and auth/company foundation.
6. Implement permissions, company management, sources/catalog.
7. Integrate/preserve Offer Panel as module.
8. Implement communicator and notifications.
9. Implement relation-aware linking and offer changelogs.
10. Update calculator launcher.
11. Validate against `09_ACCEPTANCE_TEST_MATRIX.txt`.
12. Produce final ZIP + deployment docs.

## Working style
- read before editing
- plan before restructuring
- keep backend/frontend changes synchronized
- validate continuously against acceptance matrix
- choose exactness over speed

## Do not do these things
- do not ask questions already answered in the spec files
- do not ignore the patch strategy
- do not ignore the acceptance matrix
- do not ship fake active modules
- do not weaken Offer Panel to make ERP easier
- do not bypass relation or permission rules
- do not collapse everything into one new monolithic runtime file
- do not keep the old calculator-hosted modal architecture as the main final product

## If unsure
If a detail is not explicitly specified, choose the option that is:
- future-proof
- secure
- modular
- visually consistent with current Offer Panel quality
- least likely to cause regression
- most aligned with Zegger ERP as a real production system

## Definition of done
The work is complete only when:
- Zegger ERP plugin is installable
- ERP shell works
- users land on the module dashboard
- company/user/role/module visibility rules work
- Google source + internal catalog work together correctly
- communicator + notifications work according to spec
- A↔B relation validation is enforced server-side
- Offer Panel works as preserved module without regression
- calculator launcher is updated
- deliverables are packaged
- acceptance matrix has been checked item-by-item or any remaining gap is explicitly documented
