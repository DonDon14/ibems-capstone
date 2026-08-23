# IBEMS User Portal Mobile Web Interface Investigation and Plan

Date: 2026-08-23
Branch: `codex/mobile-web-interface`
Baseline: local `production` commit `8ef733f`

## Decision

Build one adaptive web application with a dedicated mobile experience only for
the active `USER` (Employee/User Portal) role. Do not create a separate native
mobile application or duplicated mobile backend.

The User Portal desktop and mobile interfaces should use the same CodeIgniter
controllers, routes, authorization filters, session, CSRF protection, business
services, and database records. At phone widths, only `user/*` pages present the
mobile-specific navigation and record layouts. The User Portal desktop interface
remains available from the same URLs at larger widths.

Administrator, Store Administrator, Store Officer, and Accounting portals are out
of scope for the dedicated mobile interface. They retain their current web UI and
existing responsive safety behavior. Shared changes must be opt-in through the
active User Portal layout so they cannot silently redesign another role portal.

Use viewport and capability rules, not browser user-agent detection. The primary
mobile breakpoint should be CSS-driven. JavaScript `matchMedia` should be used only
when a User Portal interaction genuinely changes, such as opening account actions
in a mobile sheet instead of the desktop topbar presentation.

## What “same as web” means

The local and hosted applications can run the same committed source, but they are
not the same runtime:

- Local development uses the local configured database, local session/filesystem
  behavior, and a localhost base URL.
- Render uses HTTPS, PostgreSQL-backed sessions, Supabase PostgreSQL, Supabase
  Storage, environment-held secrets, and the deployed commit from `production`.
- A local change is not on the web until the exact commit is pushed, merged into
  `production`, and manually deployed.
- Production parity must be verified by comparing exact commit IDs and then
  smoke-testing the hosted service. Visual similarity alone is insufficient.

At the time of this investigation, a direct fetch of the remote `production`
branch resolved to the same `8ef733f` baseline from which this feature branch was
created. This does not deploy future work on this branch.

## Architecture findings

### Application and request model

- IBEMS is a server-rendered CodeIgniter 4 application. Authenticated pages use the
  canonical `app/Views/components/portal_shell.php` shell through role layouts.
- The frontend contains 103 `fetch()` call sites. The application-data calls use
  relative or same-origin URLs such as `/store/...`, `/admin/...`, `/accounting/...`,
  and `/user/...`.
- Login state is a CodeIgniter cookie session. Hosted sessions are stored in the
  database so a Render filesystem replacement does not erase login state.
- Mutating browser requests use the existing CSRF cookie/header support.
- The CORS configuration has no allowed cross-origin clients and the application
  has no Bearer-token authentication layer. A separately hosted frontend or native
  app would therefore require a new and security-sensitive API/authentication
  project. A mobile browser on the same IBEMS origin does not require that change.
- External browser dependencies currently include CDN-hosted icons, Chart.js, QR
  scanning code, and QR image fallbacks. These are availability risks on weak
  mobile connections and should be inventoried before offline/PWA claims are made.

### Render constraints

Render’s free web service is publicly reachable through HTTPS and accepts incoming
HTTP requests from desktop and mobile browsers. The relevant free-tier constraints
are:

- spin-down after an idle period and a cold start on the next request;
- limited monthly instance hours, bandwidth, and build minutes;
- ephemeral local filesystem and possible restarts;
- no free persistent disk, scaling, or high-availability guarantee.

The existing IBEMS design already mitigates the filesystem concern by using
Supabase PostgreSQL, Supabase Storage, and database-backed sessions. Mobile web does
not remove cold starts; the UI should show a patient connection/loading state and
must never encourage a user to repeat a financial submission while the first
request may still be completing.

### Existing responsive behavior

The application already has a responsive foundation:

- `portal_shell.php` declares the mobile viewport.
- At 980px and below, the shared sidebar becomes a sticky full-width header and
  its navigation becomes a horizontally scrollable row.
- At 640px and below, dashboards stack, page headers reflow, and modal actions
  become full-width.
- Page styles contain additional responsive breakpoints, especially POS,
  Inventory, Settings, Staff Records, Accounting, and Admin screens.
- Shared conventions require core actions at 360 CSS pixels, no page-level
  horizontal scrolling, and 40px minimum / 44px preferred touch targets.

This is responsive CSS, but the User Portal is not yet a cohesive mobile product
interface. Findings for other roles are retained below only as baseline evidence;
they are not implementation scope for this branch.

## Mobile viewport audit

The local application was tested at a 390 x 844 viewport with the included demo
roles. Authentication and role switching worked using the same routes and session
as desktop.

### User Portal and current shared shell

- User Dashboard generally stays within the viewport and its metric cards stack.
- Role navigation becomes a horizontal strip. It works, but important destinations
  can be off-screen with little indication of what remains.
- The second header row is tall and can wrap portal names while showing several
  unlabeled icon controls.
- Navigation targets in the strip are about 38px high, below the preferred 44px
  mobile target.
- User Dashboard's recent-record table remains wide inside a local scroller.
- User History contains table regions approximately 437–584px wide.
- My Deductions contains an approximately 527px table region.
- Stores & Products reflows without page-level horizontal overflow.

### Non-User roles (baseline only, out of scope)

Admin, Store Admin, Store Officer, and Accounting were inspected only to establish
the current baseline and verify that role switching works. Their observed table,
touch-target, and overflow limitations are not remediation work for this branch.
Negative regression checks should ensure the User-only mobile shell does not alter
those portals.

### PWA status

IBEMS is not currently an installable Progressive Web App. No web app manifest,
service-worker registration, install prompt handling, or standalone-display rules
were found. HTTPS on Render is suitable for a later installable PWA, but offline
financial writes must not be introduced in the first PWA phase.

## Target User Portal mobile experience

### User-only adaptive shell

When the active role is `USER` and the viewport is at phone width:

1. Use a compact single-row app bar for logo, current context, notifications, and
   profile.
2. Use a bottom navigation bar for the four existing User Portal destinations:
   Dashboard, Stores & Products, History, and My Deductions.
3. Put portal switching, theme, notifications, profile, and logout in an accessible
   account/action sheet where necessary.
4. Preserve the User Portal desktop sidebar at tablet/desktop widths and preserve
   every non-User role shell at all widths.
5. Respect safe-area insets and virtual-keyboard resizing.
6. Keep every navigation and action target at least 40px, preferably 44px.

The same server-rendered User Portal navigation array remains authoritative. It
should be rendered into desktop and mobile presentations without duplicating
route/permission rules. The mobile shell must be enabled by the User layout (for
example, a `user-mobile-enabled` body class or explicit shell option), not inferred
globally by the shared shell.

### Page presentation rules

- Metric dashboards: one column on narrow phones, two columns on larger phones
  only when values remain readable.
- Operational record lists: use summary cards/rows with the primary status and
  amount visible, then open a full-screen detail sheet for secondary fields.
- User History and My Deductions tables: retain desktop tables, but provide a mobile
  record renderer from the same response data. Do not hide material amounts,
  balances, dates, references, or statuses.
- Filters: use the existing compact filter sheet pattern and make Apply/Reset
  actions sticky and full-width on phones.
- Forms: one column, correct mobile input modes, sticky safe action area, explicit
  validation, and no save button hidden behind the virtual keyboard.
- Modals: use full-screen sheets on narrow phones; preserve focus trapping, Escape,
  accessible labels, and save-in-progress dismissal protection.
- Loading: distinguish “waking the service / connecting” from “submitting” where
  practical. User mutations such as debt-PIN changes must be visibly locked against
  double taps.

### Explicitly out of scope

This branch does not create dedicated mobile interfaces for POS, Inventory, Store
Reports, Store History, Store Settings, Store Administration, Accounting, Admin,
or Audit workflows. Their existing responsive behavior remains untouched unless a
small global regression must be fixed to preserve the current desktop application;
such a change requires separate review because it is outside the User mobile goal.

## Implementation phases

### Phase 0 — User-only mobile contract and regression harness

- Add viewport checks for 360, 390, 430, 768, and 1366 CSS pixels.
- Add assertions for no document-level horizontal overflow on all four User pages.
- Add shell tests proving that mobile navigation appears only when the active role
  is `USER`.
- Add negative tests proving Admin, Store Admin, Store Officer, and Accounting do
  not receive the User mobile shell.
- Add a User Portal mobile acceptance matrix for Android Chrome and iOS Safari.

### Phase 1 — User Portal shell foundation

- Add an explicit User-layout mobile opt-in, then implement the compact app bar,
  four-item bottom navigation, and account/action sheet.
- Add safe-area tokens, mobile spacing/type tokens, and shared full-screen sheet
  behavior under the User-only scope.
- Preserve the User desktop shell and all non-User portal behavior and URLs.
- Use User Dashboard as the first proof slice.

### Phase 2 — User Portal pages

- Adapt User Dashboard, Stores & Products, History, and My Deductions.
- Introduce a User-scoped mobile record-list component rather than page-specific
  card copies.
- Verify purchase receipts, cashbook entries, deduction details, filters,
  pagination, empty states, and error states at phone widths.

### Phase 3 — Installable User Portal PWA

- Link the manifest only from the User Portal layout/shell state.
- Set the install start URL to the User Dashboard and identify the experience as
  the IBEMS Employee/User Portal.
- Scope service-worker registration and cached assets to the User mobile experience
  as narrowly as the deployed URL structure permits.
- Add reviewed 192px/512px icons, theme colors, standalone display, and an explicit
  offline page.
- Do not cache authenticated JSON, financial responses, CSRF tokens, POST requests,
  receipts containing personal data, or uploaded personal/business data.
- Do not queue offline mutations. Require a confirmed online connection for writes.

### Phase 4 — User Portal device acceptance

- Test the User Portal on Android Chrome and iOS Safari using real devices.
- Verify install/launch, receipt viewing/printing, dark mode, orientation changes,
  keyboard coverage, session expiry, portal switching, Render cold start, and
  reconnect behavior.
- Confirm that switching from `USER` to another assigned role exits the mobile User
  experience and renders that role's unchanged portal.
- Run the existing PHP, route-authorization, JavaScript, Tailwind, and UI convention
  gates before any release request.

## Acceptance criteria

- Only the active `USER` role receives the dedicated mobile interface.
- Admin, Store Admin, Store Officer, and Accounting retain their current interfaces.
- User pages keep the same URL, controller, authorization, and record identity on
  desktop and mobile.
- No user-agent-based security or routing decision.
- No page-level horizontal scrolling at 360px; intentional table/carousel regions
  may scroll locally and must be visibly discoverable.
- Core controls are at least 40 x 40px and preferably 44 x 44px.
- No financial field or status is removed merely to fit the phone layout.
- User write operations keep CSRF, authorization, server validation, loading lock,
  success confirmation, and error recovery.
- User desktop behavior and every non-User role remain unchanged outside the
  explicitly enabled User mobile presentation.
- No service worker caches authenticated or financial responses.
- Production is untouched until separately authorized to commit/push/merge/deploy.

## Branch boundary

`codex/mobile-web-interface` is the isolated branch for the User Portal mobile
investigation and future implementation. The investigation itself changes
documentation only. No push, merge, Render deployment, database change, migration,
or production-data write is part of this branch setup.
