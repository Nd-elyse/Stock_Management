# Stock_Management — React Frontend

This is the original PHP `frontend/` (server-rendered pages: `index.php`,
`pages/{about,contact,login}.php`, `staff/{Admin,Receptionist,Mechanic,Stock_Manager}.php`)
converted to a React SPA, in the folder layout you're already using:

```
frontend/
  index.html            # Vite entry HTML, loads /src/main.jsx
  vite.config.js
  src/
    main.jsx            # app entry point (renders <App /> into #root)
    api.js              # axios client (CSRF header handling) + every backend/api/*.php endpoint
    context.jsx         # AuthProvider + ToastProvider
    components.jsx       # shared UI: navbar/footer, DashboardShell, DataTable, Modal, StatCard...
    App.jsx / App.css
    index.css
    assets/
      style.css        # ported verbatim from the original style.css (public site)
      staff.css        # ported verbatim from the original staff.css (dashboards)
    pages/
      Home/Home.jsx (+ Home.css)
      About/About.jsx
      Contact/Contact.jsx
      Login/Login.jsx (+ Login.css)
      Dashboard/
        Admin.jsx
        Receptionist.jsx
        Mechanic.jsx
        StockManager.jsx
```

Files that contain JSX use a `.jsx` extension (Vite/esbuild only parses
JSX syntax in `.jsx`/`.tsx` by default); plain logic files (`api.js`)
stay `.js`.

## Running it

```bash
cd frontend
npm install
npm run dev
```

The dev server proxies `/backend/*` calls to `http://localhost:8000`
(see `vite.config.js`) — point `VITE_BACKEND_ORIGIN` (see `.env.example`)
at wherever your existing PHP backend (`backend/api/*.php`) runs, or set
`VITE_API_BASE_URL` to an absolute URL instead of using the proxy.

`npm run build` produces a production build in `dist/`; `npm run preview`
serves that build locally.

## How the conversion maps

- **PHP pages → React Router routes**, all defined in `App.js`. Public
  pages (`/`, `/about`, `/contact`) share the original navbar/footer via
  `PublicLayout` in `components.js`. `/login` is standalone, matching the
  original `login.php`. The four staff dashboards live at
  `/dashboard/{admin,receptionist,mechanic,stock}` and are wrapped in
  `ProtectedRoute` so only a signed-in user with the matching role can
  reach them.
- **PHP forms + `fetch()` → Axios**, all centralized in `src/api.js`. The
  original app tagged every write request with an `X-CSRF-Token` header
  sourced from a server-rendered `<meta>` tag, retrying once via
  `csrf.php?action=refresh` on a 403 — `api.js` reproduces that exactly
  with an axios interceptor, fetching the token once on load instead.
- **Session/role state → `AuthContext`** (`context.js`), backed by
  `sessionStorage` so a refresh doesn't lose the signed-in user.
- **Bootstrap modals/dropdowns** are left to Bootstrap's own JS bundle
  (loaded via CDN in `public/index.html`, same as the original), since
  the CSS transitions in `staff.css`/`style.css` are built for it. React
  only supplies the DOM via JSX; `showBsModal()` / `hideBsModal()` in
  `components.js` open/close them imperatively after a save.
- **Validation regexes** (email, Rwanda phone format, name pattern, etc.)
  were ported 1:1 from the original `main.js` so error messages and
  accepted formats match exactly.

## What's fully wired up

- Home, About, Contact (with a working Axios-submitted contact form),
  and Login (role selector, OTP modal, 3-step forgot-password flow,
  contact-admin modal) — all pixel-matched to the original CSS.
- All four dashboards, each with a working sidebar/topbar shell, a
  Dashboard tab, notifications, and full CRUD (Add/Edit/Delete via the
  matching REST endpoint) for their core resources:
  - **Admin** — Users (full CRUD), read-only Mechanics/Suppliers/Spare
    Parts views, Reports, Contact Messages inbox, Notifications, Settings.
  - **Receptionist** — Customers, Vehicles, Repair Jobs (assign a
    mechanic, track status), Invoices, Payments.
  - **Mechanic** — assigned jobs (update diagnosis/status), spare-part
    requests.
  - **Stock Manager** — Spare Parts, Categories, Suppliers, Purchases
    (stock-in), incoming Part Requests (approve/reject), Stock Log.

## What to double-check against your real backend

Since only the `frontend/` PHP was provided (no `backend/`), the exact
field names, response shapes, and a couple of endpoints (e.g. an
admin-level aggregate stats endpoint) were inferred from the original
`main.js`'s fetch calls and the PHP table markup. Before treating this as
final, diff `src/api.js`'s endpoint list and the field names used in each
dashboard's forms (e.g. `PartName`, `QuantityInStock`, `ReorderLevel`)
against your actual `backend/api/*.php` responses, and adjust anything
that doesn't match.
