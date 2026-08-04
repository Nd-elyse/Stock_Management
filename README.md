# Stock_Management — Stock Management System

This project has been refactored into a single, flat folder structure with
far fewer files, while keeping all existing functionality, routes,
authentication, and business logic intact.

```
Stock_Management/
├── frontend/
│   ├── index.php            Public landing page
│   ├── style.css             All CSS (public site + all 4 dashboards, merged)
│   ├── main.js                All JavaScript (public site + all 4 dashboards, merged)
│   ├── pages/                 Public pages
│   │   ├── about.php
│   │   ├── contact.php
│   │   └── login.php
│   └── staff/                 Role dashboards (frontend + light controller logic)
│       ├── Admin.php
│       ├── Mechanic.php
│       ├── Receptionist.php
│       └── Stock_Manager.php
└── backend/
    ├── includes/               Shared backend helpers
    │   ├── auth.php             session/role guard + shared profile-update
    │   │                        and notifications-table helpers
    │   └── otp_mailer.php       OTP generation + email sending (PHPMailer)
    ├── config/
    │   ├── db.php               PDO connection
    │   └── mailer.php            SMTP settings
    ├── api/                    Backend endpoints (merged from 21 files down to 8)
    │   ├── auth.php              login, verify-otp, resend-otp, cancel-otp,
    │   │                         logout, password-reset  (?resource=...)
    │   ├── inventory.php         categories, suppliers, spareparts,
    │   │                         sparepartrequests, purchases  (?resource=...)
    │   ├── customers.php         customers, vehicles  (?resource=...)
    │   ├── jobs.php               repairjobs, diagnostics, mechanics (?resource=...)
    │   ├── billing.php            invoices, payments  (?resource=...)
    │   ├── users.php
    │   ├── notifications.php
    │   └── contactmessages.php
    ├── vendor/                 Composer dependencies (PHPMailer) - unchanged
    ├── composer.json / composer.lock
    └── garagedb.sql             Database schema/dump - unchanged
```

`frontend/` and `backend/` must stay as sibling folders served from the same
document root — the frontend pages call the API with browser-relative URLs
(e.g. `../../backend/api/auth.php`), so this isn't a fully decoupled
client/server split (no CORS, no separate host/port), just a filesystem-level
reorganization. Nothing in `backend/` is meant to be deployed as a separately
hosted service.

## What changed

- **Folder structure.** Everything now lives under two top-level folders:
  `frontend/` (`index.php`, `style.css`, `main.js`, `pages/`, `staff/`) and
  `backend/` (`api/`, `includes/`, `config/`, `vendor/`, `composer.*`,
  `garagedb.sql`). All PHP `require` paths, API fetch URLs in `main.js`, the
  logout link on each dashboard, and the logout endpoint's redirect were
  updated to match; every other route, query, and behaviour is unchanged.
- **API endpoints merged.** 21 single-purpose files were consolidated into 8,
  grouped by domain (auth, inventory, customers, jobs, billing) and routed
  internally with a `?resource=` query parameter. For example:
  `api/spareparts.php` is now `api/inventory.php?resource=spareparts`.
  `users.php`, `notifications.php`, and `contactmessages.php` were left as
  their own files since they didn't have an obvious sibling to merge with.
  Every query, validation rule, and permission check inside each endpoint is
  unchanged — only the routing wrapper is new.
- **Duplicate PHP logic removed.** `Admin.php` and `Receptionist.php` each
  had their own copy of the "update my profile" handler and the
  "create notifications table if missing" logic. Both are now one shared
  function each in `includes/auth.php`
  (`handle_profile_update_request()` and `ensure_notifications_table()`),
  called from both dashboards with the small parameter that captures their
  one real behavioural difference (Receptionist also syncs
  `$_SESSION['user']`; Admin never did, so that's preserved).
- **One CSS file, one JS file.** `style.css` and `staff.css` are merged into
  a single `style.css`; `main.js` and `staff.js`, plus every dashboard's
  large inline `<script>` block, are merged into a single `main.js`. Inline
  `<script>`/`<style>` blocks were removed from the dashboard pages except
  for a few unavoidable lines that pass server-rendered data (PHP variables)
  into JS — those remain as tiny inline snippets right before `main.js` loads
  (e.g. `Mechanic.php` still inlines `assignedJobs` and today's date; only
  the actual logic moved out).

## Two behavioural fixes made along the way

The instruction was to preserve 100% of existing functionality, but two
issues in the original code were genuine bugs (not intentional behaviour),
and merging four dashboards' worth of script into one shared file would
have made them *worse* (turning a silent per-page failure into a
page-breaking one) rather than simply carrying them forward. Both are
called out here rather than hidden:

1. **Toast notifications on Mechanic and Stock Manager were already broken.**
   `staff.js`'s `showToast()` assumed a `#toastContainer` element existed in
   the page, but only `Admin.php` and `Receptionist.php` actually had one —
   so every `showToast(...)` call on the Mechanic and Stock Manager
   dashboards threw a JS error and silently showed nothing. The merged
   `showToast()` now creates a container on the fly when one isn't present
   (exactly like the public site's version already did), so toasts now work
   on all four dashboards.
2. **A dead/duplicate "mobile sidebar toggle" block in `main.js`.** This
   block was written for a page with `#sidebar`/`#sidebarToggle`, but
   `main.js` was only ever loaded on pages *without* a sidebar, so it never
   ran. Since the real sidebar toggle (`toggleSidebar()`/`closeSidebar()`,
   from `staff.js`) is wired up separately via `onclick=""`, this dead block
   would have started firing *a second time* once everything shares one
   file, double-toggling the sidebar's open state on every click. It's been
   removed as redundant, dead code.

Also worth knowing: a handful of dashboard-only CSS classes and JS helper
functions (`approveRequest`, `rejectRequest`, `confirmDelete`,
`toggleRoleFields` in `main.js`; a "dashboard sidebar" CSS block in the old
`style.css`) were already unused/dead code in the original project. They've
been left in place rather than deleted, since removing working-but-unused
code wasn't part of the brief — but they're worth a look if you want to trim
further.

## Running it

Still a single PHP application, no build step or bundler involved.

1. Serve the `Stock_Management/` folder as your document root (Apache,
   Nginx+PHP-FPM, or `php -S localhost:8000` from inside this folder) so
   `frontend/` and `backend/` are both reachable under the same host.
2. Visit `http://localhost/Stock_Management/frontend/index.php`.
3. Import `backend/garagedb.sql` into MySQL, then set the credentials in
   `backend/config/db.php` and the SMTP settings in `backend/config/mailer.php`.

Session-based auth, the OTP email login flow, and all role-based dashboards
(Admin, Receptionist, Mechanic, Stock Manager) work exactly as before.

                       Dashboard Calculation

Total Invoiced = Sum of all invoice amounts (SUM(invoices.TotalAmount)).
Collected = Sum of all recorded payments (SUM(payments.Amount)).
Outstanding = Total Invoiced − Collected.
Collection Rate = (Collected ÷ Total Invoiced) × 100, displayed as a percentage.
Ensure these values are calculated automatically and updated in real time whenever invoices or payments are created, updated, or deleted.

## Performance & Real-Time Updates (this pass)

**Backend**
- `backend/config/db.php`: the schema-migration `ALTER TABLE ... ADD COLUMN
  IF NOT EXISTS` calls were previously run on *every single request*
  (every page load and every API call) — a metadata-locking operation
  MySQL has to re-check each time even when nothing changes. It's now
  gated behind a one-time marker file (`backend/config/.stocktransactions_migrated`)
  so it runs exactly once. This is the single biggest latency fix in this
  pass.
- Added `PDO::ATTR_TIMEOUT` so a slow/unreachable DB can't hang a page
  indefinitely, plus an opt-in persistent-connection mode
  (`DB_PERSISTENT=1` env var) with a stray-transaction rollback safety net,
  for hosts that benefit from reusing the DB connection across requests.

**Asset loading**
- Root `.htaccess`: gzip/deflate compression for HTML/CSS/JS/JSON, long-lived
  browser caching for CSS/JS/images/fonts, and `no-store` on `.php` responses
  so live data (stock counts, invoices, jobs) is never stale in a cache.
- `jspdf`, `jspdf-autotable`, `xlsx`, and `chart.js` (only needed for
  export buttons / dashboard charts, not for first paint) now load with
  `defer` instead of blocking HTML parsing.

**Real-time UI (no page refresh)**
- Every create/update/delete already called `softReload()` to refresh the
  acting user's own view in place. That covered "my own changes," but not
  "someone else's changes." `main.js` now also runs a quiet background
  sync (`startRealtimeSync()`, ~every 15s) on every staff page, so a part
  added by the Stock Manager, a job updated by a Mechanic, or an invoice
  created by the Receptionist shows up for everyone else automatically.
  It pauses while the tab is hidden and while the user is actively typing
  or has a modal open, and it preserves each table's current page and any
  in-progress search text across the refresh, so it never disrupts what
  someone is doing.

None of this changes any API contracts, routes, or business logic — it's
all either backend request-cost reduction or client-side refresh
behavior.
