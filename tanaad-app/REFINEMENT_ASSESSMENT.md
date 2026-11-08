# Frontend refinement assessment

**Purpose:** A reviewable direction for reducing complexity and increasing operational credibility in the Tanaad frontend mockup. This is an assessment and proposed sequence, not a claim that the current workflow has been validated with factory users.

**Evidence reviewed:** `docs/AGENT_BRIEF.md`, `docs/DESIGN_SPEC.md`, `docs/ARCHITECTURE.md`, `docs/KNOWN_GAPS.md`, the site configuration and demo seed, the route and navigation definitions, representative screen implementations, and committed screenshots under `docs/screenshots/`. Screenshot examples below are from the repository's October 2026 demo capture; the seed declares 10 October 2026 as its fixed demo date. They are evidence of the current mockup, not live plant operations.

## Assessment in brief

The mockup already has a coherent component library, role-aware navigation, touch density, domain arithmetic and explicit disclosure of simulations. Those are good foundations. The main risk is that the design spec pushes a feature-rich, dashboard-first workbench across a product whose confirmed operating rules and real user roles are still incomplete. The result can read as generated because screens share elaborate patterns even when the task does not need them, navigation names promise more than their destinations deliver, and demo data is displayed with the tone of verified operational reporting.

The refinement should therefore reduce decisions and visual noise, make every number traceable to a source/period/state, and let confirmed workflows determine which screens remain prominent. Keep domain capabilities that are supported by the proposal and business rules; demote or remove only after confirming that the role does not use them.

## Findings grounded in this repo

### 1. The design spec mandates richness even where the task does not call for it

`DESIGN_SPEC.md` describes the target as a “visually rich” control-room workbench, prescribes a hero, KPI strip, charts, side panels for monitor pages, and requires logs/trends to fill available space. It also specifies a hero on several major screens and keyboard shortcuts across screens. This is a strong global recipe. The resulting screenshots show similar hero bands, multiple metrics, mini charts, borders and status colours on the Group Cockpit and site dashboards (`01_cockpit.png`, `46_bagsdash.png`).

The existing task-specific guidance in `AGENT_BRIEF.md` is more appropriate for floor work: one task per screen, large touch targets, plain copy, and notes for unconfirmed rules. Preserve those operational constraints. Relax the visual recipe: use a dashboard only where users need to monitor and decide; use a short form or ledger when that is the task. Do not add charts, deltas, or empty-state panels just to fill space.

### 2. Navigation volume and naming do not consistently map to user work

The router currently registers 42 site child routes and 8 Group routes. `SITE_NAV` exposes production, people, cash, stock, dispatch, maintenance, sales, reports and settings groups, including multiple screens within several hubs; `AppShell` then renders a hub accordion and a second row of hub tabs. This creates two navigation layers before a user reaches a task. Some labels also overstate or mismatch the destination: `Loom Matrix MES` points to `reports/production`, while “Today & Workflow” and “Factory Dashboard” are separate entry points and the dashboard also links into production, people, cash and stock. This is visible in `app/nav.ts`, `app/router.tsx`, and the Bags dashboard screenshot.

Recommendation: make the default navigation role-specific and task-ordered. A person should see the few actions they perform on a normal shift, with less frequent records and administration under a clearly named secondary area. Align each label with the page's actual purpose. Test whether hub tabs add value once the sidebar already exposes the same pages. Do not delete a capability solely because it is secondary; first decide whether it should be a primary navigation item, a related action, or a settings-only function.

### 3. Group HQ is carrying too many incompatible signals at once

`01_cockpit.png` shows six headline measures, a shift timeline, three site cards with many more rows, a Cards/Matrix switch, exception counts, approvals, credit, cash, output, stock, waste and dispatches in one first view. In `Cockpit.tsx`, group totals include local cash in UGX while receivables are USD, and `kgToday` combines output weights across different products/sites. The per-site labels provide some context, but the top-line “5.5 t produced” is not a like-for-like performance measure. The site cards repeat some issues already represented in the headline/decision area.

Recommendation: define the small set of CEO decisions this page must support from the proposal and actual CEO review routine. Keep the decision queue and the few comparable, decision-relevant site signals. Show each currency and period in the same visual unit as its number. Do not aggregate unlike production units or currencies into a single comparison without a documented conversion/meaning. Move secondary operational detail to the site drill-down. Retain a matrix view only if CEO users prefer it in review.

### 4. Site dashboards show unconfirmed targets and seeded records in authoritative visual treatments

The site config contains taglines such as “oldest site · best records” and “new · 14 t/day”; the seed uses explicitly illustrative product prices, weights, rates, suppliers/customers/collector/worker records and machinery. `KNOWN_GAPS.md` says those examples await client confirmation. Yet the portal and dashboard screens show figures, trends, deltas, benchmarks and status badges as if they were live measured facts. `46_bagsdash.png` shows a production benchmark comparison despite the site brief saying many product weights and process assumptions are examples. The code has useful “to be confirmed” notes, but they do not explain the provenance of every benchmark/metric.

Recommendation: consistently identify demo data as demo in demo mode, and label unconfigured or illustrative benchmarks at the point of use. Do not use promotional claims (“best records”) or capacity claims unless a client source confirms them. For real operations, display when/where a measure was recorded, its unit, whether the shift is still open, and what denominator/benchmark it uses. Keep measured actuals visually distinct from estimates and examples.

### 5. At least one floor workflow visually exposes more than the confirmed intake policy

The intake screen (`28_intake.png`, `features/recycling/Intake.tsx`) includes collector selection, scale connection/manual photo route, tare, configurable deduction, price, arithmetic, voucher preview, pay-now, WhatsApp sharing, and today's intake/ledger summaries. The implementation correctly displays a warning that the deduction rule, process stages and sack size need plant confirmation; `KNOWN_GAPS.md` likewise says deductions/prices are illustrative and WhatsApp is only a preview. Until those policies and the scale/payment procedure are confirmed, the page is a rich prototype of several workflows, not an evidence-backed final production flow.

Recommendation: keep the confirmed weighing and auditable voucher arithmetic. Make the unresolved policy-dependent parts visibly provisional and avoid making “pay now” or sharing appear like a live external transaction. During plant walkthrough, establish the actual sequence, who may weigh/approve/pay, how tare and deductions are evidenced, what happens when the scale/photo/device is unavailable, and what paper voucher people use today. Then remove steps that do not correspond to that procedure.

### 6. Prototype status and real operational readiness are easy to confuse

The README says this is a deterministic browser demo and “demo today, product tomorrow.” `KNOWN_GAPS.md` lists simulated authentication, scale, label printing, camera capture, WhatsApp, sync, EFRIS and print/PDF. The shell screenshot includes “Live”, “Offline ready” and “Synced”; these labels are useful only if their meaning is explicit and true for the state shown. `ARCHITECTURE.md` describes a future API/outbox integration, not one that currently exists.

Recommendation: retain honest demo labeling throughout the app, especially around actions that imply payment, messaging, printing, authentication or server synchronization. For production, replace each simulated status with a real device/server result and failure state. Avoid “live” for seeded or local-only data. This is a credibility requirement, not a cosmetic preference.

## Page family review and proposed treatment

This is a first-pass inventory from the current route and nav definitions, not a usability test. “Retain” means keep the business capability in scope; it does not mean preserve its current layout or primary-nav position.

| Area | Current pages / evidence | Proposed treatment |
|---|---|---|
| Entry and portal selection | `Login`, `Portals`; `00a_login.png`, `00b_portals.png` | Retain sign-in and workspace selection. Simplify portal cards to access, site name, role and a few trustworthy open items. Remove unverified taglines and avoid presenting seed-based trend charts as company facts. Verify whether people switch sites often enough to justify the portal page on every session. |
| Group HQ | `Cockpit`, `Approvals`, `CreditDesk`, `Brief`, `AuditFeed`, `GroupPeople`, `Pnl`, Group settings; `01_cockpit.png`, `02_approvals.png`, `03_credit.png`, `04_brief.png`, `05_audit.png`, `06_gpeople.png`, `07_pnl.png` | Keep decision approval, credit control, audit trail and finance/reporting if the CEO process confirms them. Consolidate duplicate overview signals; make the morning brief a record or remove it from primary navigation if it is only a scheduled message that is “To be set.” Keep audit separate from informal narrative. Validate the purpose and owner of each page before removal. |
| Shared site entry and monitoring | `Today`, `SiteDashboard`; screenshots `46_bagsdash.png`, `16_wall.png`, `17_sheet.png` | Choose one default landing point per role. `Today` should answer “what do I do next?”; manager dashboard should answer “what needs attention?” Avoid making both general-purpose summaries. Machine wall and handover log are useful operational records when their data capture is confirmed. Correct `Loom Matrix MES` destination/label mismatch. |
| Mats production | Mixer, Extruders, Finishing, Waste, shift sheet, wall, labels, scan; screenshots `20_mixer.png`–`23_waste.png`, `16_wall.png`–`19_scan.png` | Retain site-specific process steps that match the confirmed production route. Do not call a report a MES. Confirm the meaning/source of benchmarks, waste, calibration and product weight tolerances before making variance colours/decisions authoritative. Keep scan/print features only with a real device workflow or clear demo treatment. |
| Bags production | Raw issue, Roll Sticker, Conversion, Film Scrap, wall, shift sheet, labels, scan; screenshots `24_rolls.png`–`27_scrap.png` | Preserve raw issue and roll traceability if they match paper records. `bags.ts` explicitly marks post-extrusion process as unconfirmed; label the affected screens as provisional and do not harden process labels/workflow until a floor walkthrough. Confirm conversion units and scrap accounting before using dashboard deltas. |
| Recycling | Intake, Collector Ledger, Sacks, wall, shift sheet, labels, scan; screenshots `28_intake.png`–`30_sacks.png` | Weigh-in and voucher/collector record are plausible core workflows, but confirm deductions, tare, price, payment authority, proof and scale failure handling. Keep Collector Ledger focused on reconciliation and unpaid vouchers; avoid decorative collector rankings/charts unless operators use them to act. Confirm stage and sack definitions before labeling output as production. |
| People | Casual signup, registry, attendance, settlement, attendance report; screenshots `10_attendance.png`–`13_settlement.png` | Keep distinct daily tasks (signup, attendance, settlement) if they map to the actual engagement/pay process. Verify whether the separate Attendance Sheet and Attendance & Wages report have clear owners and distinct decisions. Prioritize traceable edits, duplicate prevention, corrections and approval over KPIs. |
| Cash | Cash page, expense form, cash report; `14_cash.png`, `15_expense.png` | Retain cash close and expense capture. Use a ledger as the source record and a summary as a summary. Make expected vs counted vs signed/closed states explicit; validate who can reopen/approve and what evidence is required. Avoid presenting a forecast/expected close as actual balance. |
| Stock, gate and dispatch | Overview, count, tagging, receiving, movements, dispatch, deliveries, spares and maintenance; screenshots `32_stock.png`–`40_maintenance.png` | Preserve receiving, count, dispatch and delivery proof if they match current paper controls. Merge or demote overlapping entry points only after mapping their distinct actors/records. Confirm gate override authority, stock location/zone meanings, cargo rules and physical scale/vehicle steps. “Tagging campaign” appears specialized and should not lead navigation unless a real recurring campaign exists. |
| Sales and purchasing | Clients, orders, invoices, purchases; screenshots `41_orders.png`–`45_purchases.png` | Keep order approval, credit, invoice and receivables control if owners and currency rules are agreed. Connect Group credit desk to the same source of truth. Confirm import and landed-cost workflows; a seeded sample purchase is not enough evidence to elevate a complex purchase module. |
| Reports and settings | Reports, production/attendance/cash/dispatch reports, site/group settings | Keep reporting as a destination for historical review/export, not a second dashboard. Keep settings for confirmed client-owned master data. Remove example/configuration options only when the master data is approved and responsibility for maintaining it is clear. |

## Refinement rules

1. **No unsupported operational claim.** Every benchmark, target, rate, threshold, capacity, “live” status and process rule must cite its configured source or be labeled example/unconfirmed.
2. **One page, one job.** Every primary page needs a named role, trigger, completion state and record it creates/updates. If these cannot be stated from the proposal or user observation, keep it out of the primary nav until confirmed.
3. **Show the next action and the evidence.** A status count should link to the records behind it. A number needs a unit, period and actual/expected/example state. A comparison needs a meaningful denominator.
4. **Keep only useful visual weight.** Remove a hero, chart, KPI, animation, icon or card if it does not help a user decide or complete the page's job. Reuse the existing component system; do not create bespoke surface treatments per portal.
5. **Model actual exceptions.** For floor tasks, document offline/device unavailable, correction, duplicate, partial shift, supervisor override and reprint/reversal outcomes from the real procedure. Avoid designing hypothetical workflows without an owner.
6. **Separate demonstration from operation.** Simulated scale, printer, messaging, camera, authentication, sync and fiscal behavior must be unmistakably demo behavior. Production status must reflect a real result, including failure.

## Recommended work sequence

### Phase 1 — establish truth and ownership

- Review each page with the responsible person at Mats, Bags, Recycling and Group HQ. Capture actual role, trigger, paper/source record, sequence, decision, exception and completion proof.
- Confirm which demo facts are placeholders and who can approve each master-data value. `KNOWN_GAPS.md` is the starting checklist, not a substitute for client sign-off.
- Confirm priority roles and routine frequency. Do not infer that every seeded role or routed page is needed in the first production release.

### Phase 2 — reduce the product surface

- Create a role-to-task map from the walkthrough; choose one landing page per role.
- Rework primary navigation around frequent shift tasks; put rare records/reports under a secondary section.
- Remove redundant summaries and incorrect labels. Keep domain functions in scope until users confirm they are redundant or unused.
- Resolve unresolved Bags and Recycling process steps before polishing those workflows as final.

### Phase 3 — make records and states trustworthy

- Add consistent demo/source/period cues and distinguish measured, expected, estimated and illustrative values.
- Review currency/units and comparisons on cockpit, dashboards and reports. Do not sum unlike quantities.
- Audit buttons and status language against the actual simulator/API result. Mark simulated payment, WhatsApp, printing, scale and sync actions accordingly.

### Phase 4 — visual simplification and field check

- Apply one page archetype only when it fits the task: focused form, searchable ledger, or small actionable overview.
- Remove repeated cards, chart noise, gratuitous deltas, excessive keyboard hints and decorative animation. Retain visible labels, accessible states and touch targets.
- Validate with a floor user on the device they actually use. Measure task completion, correction rate, missing records and time; do not use “zero-training” as an untested acceptance claim.

## Evidence and references

The following primary product guidance supports the principles above. These sources provide general design principles; they do not validate Tanaad's business process or dictate a particular layout.

- SAP describes Fiori as role-based, adaptive, simple and coherent, with users shown what they need for their work: [SAP Fiori concept and design](https://help.sap.com/docs/SAP_S4HANA_ON-PREMISE/22bbe89ef68b4d0e98d05f0d56a7f6c8/85f167b1da3d46d98e26cf4cac4430f8.html).
- Microsoft defines operational workspaces around a target persona's activity, current state and direct access to relevant deeper tasks, with simple work completed in the workspace: [Build operational workspaces](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/dev-itpro/user-interface/build-workspaces).
- Microsoft says Role Centers should provide the information most relevant to users' daily work and navigation to related records/tasks: [Designing Role Centers](https://learn.microsoft.com/en-au/dynamics365/business-central/dev-itpro/developer/devenv-designing-role-centers).
- SAP launchpad guidance recommends lean, role-specific pages, commonly used apps, task-sequenced organization and insight-to-action navigation: [SAP Fiori launchpad recommendations (PDF)](https://help.sap.com/doc/289ec1eb1a9b4efab8cb1bf60f6f8e03/202210.002/en-US/bde12a271f0647e799b338574cda0808.pdf).

## Limits of this assessment

No interviews, site observation, production backend, live device integration, or client-approved process documentation were available in the repo evidence reviewed. The report can identify unsupported claims, navigation duplication, visual density and explicitly documented simulations; it cannot determine which operation is truly unnecessary, validate labor/payment rules, or prescribe the exact final task sequence. Those decisions require a short walkthrough with the people who perform and approve the work.
