import React, { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { Link, NavLink, Navigate, useNavigate, useLocation } from 'react-router-dom';
import { useAuth } from './context';

/* ============================================================
   PUBLIC SITE CHROME
   ============================================================ */
export function usePublicChrome() {
  const [loaderHidden, setLoaderHidden] = useState(false);
  const [scrolled, setScrolled] = useState(false);
  const [showBackToTop, setShowBackToTop] = useState(false);

  useEffect(() => {
    const t1 = setTimeout(() => setLoaderHidden(true), 400);
    const onScroll = () => {
      setScrolled(window.scrollY > 30);
      setShowBackToTop(window.scrollY > 400);
    };
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
    return () => {
      clearTimeout(t1);
      window.removeEventListener('scroll', onScroll);
    };
  }, []);

  return { loaderHidden, scrolled, showBackToTop, scrollToTop: () => window.scrollTo({ top: 0, behavior: 'smooth' }) };
}

/** Scroll-reveal animation for .reveal / .reveal-left / .reveal-right / .reveal-scale */
export function useReveal(deps = []) {
  useEffect(() => {
    const targets = document.querySelectorAll('.reveal, .reveal-left, .reveal-right, .reveal-scale');
    if (!targets.length) return;
    if ('IntersectionObserver' in window) {
      const io = new IntersectionObserver(
        (entries) => {
          entries.forEach((entry) => {
            if (entry.isIntersecting) {
              entry.target.classList.add('reveal-visible');
              io.unobserve(entry.target);
            }
          });
        },
        { threshold: 0.12, rootMargin: '0px 0px -60px 0px' }
      );
      targets.forEach((el) => io.observe(el));
      return () => io.disconnect();
    }
    targets.forEach((el) => el.classList.add('reveal-visible'));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, deps);
}

export function PageLoader({ hidden }) {
  return (
    <div id="pageLoader" className={hidden ? 'hide' : ''}>
      <i className="bi bi-wrench-adjustable-circle-fill loader-icon"></i>
    </div>
  );
}

export function PublicNavbar({ scrolled }) {
  const [menuOpen, setMenuOpen] = useState(false);
  const location = useLocation();

  // Close the mobile menu on every navigation, so it doesn't stay open
  // after tapping a link.
  useEffect(() => { setMenuOpen(false); }, [location.pathname]);

  return (
    <nav className={`navbar navbar-expand-lg navbar-custom fixed-top${scrolled ? ' scrolled' : ''}`} id="mainNav">
      <div className="container">
        <NavLink className="navbar-brand" to="/">
          <i className="bi bi-wrench-adjustable-circle-fill"></i> Garage<span>Manager</span>
        </NavLink>
        <button
          className="navbar-toggler"
          type="button"
          aria-label="Toggle navigation"
          aria-expanded={menuOpen}
          onClick={() => setMenuOpen((v) => !v)}
        >
          <span className="navbar-toggler-icon"></span>
        </button>
        <div className={`collapse navbar-collapse${menuOpen ? ' show' : ''}`} id="navMenu">
          <ul className="navbar-nav ms-auto align-items-lg-center gap-1 gap-lg-0">
            <li className="nav-item"><NavLink className={({ isActive }) => `nav-link${isActive ? ' active' : ''}`} to="/" end>Home</NavLink></li>
            <li className="nav-item"><NavLink className={({ isActive }) => `nav-link${isActive ? ' active' : ''}`} to="/about">About</NavLink></li>
            <li className="nav-item"><NavLink className={({ isActive }) => `nav-link${isActive ? ' active' : ''}`} to="/contact">Contact</NavLink></li>
            <li className="nav-item"><NavLink className="nav-link btn-login" to="/login"><i className="bi bi-box-arrow-in-right"></i> Login</NavLink></li>
          </ul>
        </div>
      </div>
    </nav>
  );
}

export function PublicFooter() {
  const year = new Date().getFullYear();
  return (
    <footer className="footer-custom">
      <div className="container">
        <div className="row g-4 align-items-center">
          <div className="col-md-4">
            <div className="footer-brand"><i className="bi bi-wrench-adjustable-circle-fill"></i> Garage<span>Manager</span></div>
            <p className="footer-text mt-2" style={{ maxWidth: 320 }}>
              Smart garage management system for modern auto workshops. Built with{' '}
              <i className="bi bi-heart-fill" style={{ color: 'var(--primary-blue)' }}></i> in Rwanda.
            </p>
          </div>
          <div className="col-md-4">
            <div className="footer-links">
              <Link to="/">Home</Link>
              <Link to="/about">About</Link>
              <button type="button" className="footer-link-btn" onClick={() => showBsModal('viewRepairStatusModal')}>View Repair Status</button>
              <Link to="/contact">Contact</Link>
              <Link to="/login">Login</Link>
            </div>
          </div>
          <div className="col-md-4 text-md-end">
            <a href="#top" className="social-link"><i className="bi bi-facebook"></i></a>
            <a href="#top" className="social-link"><i className="bi bi-twitter-x"></i></a>
            <a href="#top" className="social-link"><i className="bi bi-instagram"></i></a>
            <a href="#top" className="social-link"><i className="bi bi-linkedin"></i></a>
          </div>
        </div>
        <hr className="footer-divider" />
        <div className="row align-items-center">
          <div className="col-md-6"><p className="copyright mb-0">&copy; {year} <strong>GarageManager</strong> &mdash; All rights reserved.</p></div>
          <div className="col-md-6 text-md-end"><p className="copyright mb-0"><i className="bi bi-shield-lock"></i> Secure &bull; Reliable &bull; Efficient</p></div>
        </div>
      </div>
    </footer>
  );
}

export function BackToTop({ show, onClick }) {
  return (
    <button id="backToTop" className={`back-to-top${show ? ' show' : ''}`} aria-label="Back to top" onClick={onClick}>
      <i className="bi bi-arrow-up"></i>
    </button>
  );
}

export function PublicLayout({ children, modals }) {
  const { loaderHidden, scrolled, showBackToTop, scrollToTop } = usePublicChrome();
  return (
    <>
      <PageLoader hidden={loaderHidden} />
      <PublicNavbar scrolled={scrolled} />
      {children}
      <PublicFooter />
      <BackToTop show={showBackToTop} onClick={scrollToTop} />
      {modals}
    </>
  );
}

/* ============================================================
   ROUTE GUARD
   ============================================================ */
export function ProtectedRoute({ role, children }) {
  const { user, ready } = useAuth();
  if (!ready) return null;
  if (!user) return <Navigate to="/login" replace />;
  if (role && user.role !== role) return <Navigate to={{ Admin: '/dashboard/admin', Receptionist: '/dashboard/receptionist', Mechanic: '/dashboard/mechanic', 'Stock Manager': '/dashboard/stock' }[user.role] || '/login'} replace />;
  return children;
}

/* ============================================================
   BOOTSTRAP MODAL HELPERS (bootstrap.bundle.js is loaded globally
   in public/index.html, so modals/dropdowns/collapses work as-is)
   ============================================================ */
export function showBsModal(id) {
  const el = document.getElementById(id);
  if (el && window.bootstrap) window.bootstrap.Modal.getOrCreateInstance(el).show();
}
export function hideBsModal(id) {
  const el = document.getElementById(id);
  if (el && window.bootstrap) {
    const inst = window.bootstrap.Modal.getInstance(el);
    if (inst) inst.hide();
  }
}

export function Modal({ id, title, icon, children, size = '' }) {
  return (
    <div className="modal fade modal-custom" id={id} tabIndex="-1">
      <div className={`modal-dialog modal-dialog-centered${size ? ` ${size}` : ''}`}>
        <div className="modal-content">
          <div className="modal-header">
            <h5 className="modal-title">{icon && <i className={`bi ${icon}`} style={{ color: 'var(--primary-blue)' }}></i>} {title}</h5>
            <button type="button" className="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div className="modal-body">{children}</div>
        </div>
      </div>
    </div>
  );
}

/* ============================================================
   PREMIUM CONFIRM-DELETE MODAL
   ------------------------------------------------------------
   ConfirmDeleteHost is mounted once (inside DashboardShell) and
   registers itself as the active handler. ConfirmDelete() is then
   called the same way it always was - as a plain imported function,
   no hooks/context needed at the call site - but now returns a
   Promise<boolean> instead of using the blocking window.confirm().
   Callers just need `await`: `if (!(await ConfirmDelete('user', name))) return;`
   ============================================================ */
let _confirmDeleteImpl = null;

export function ConfirmDeleteHost() {
  const [state, setState] = useState(null); // { entityLabel, name, resolve }

  useEffect(() => {
    _confirmDeleteImpl = (entityLabel, name) => new Promise((resolve) => {
      setState({ entityLabel: entityLabel || 'item', name, resolve });
    });
    return () => { _confirmDeleteImpl = null; };
  }, []);

  const close = (result) => {
    if (state) state.resolve(result);
    setState(null);
  };

  useEffect(() => {
    if (!state) return undefined;
    const onKey = (e) => { if (e.key === 'Escape') close(false); };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [state]);

  if (!state) return null;

  const label = state.entityLabel;
  const titleLabel = label.charAt(0).toUpperCase() + label.slice(1);

  return (
    <div className="confirm-overlay" onClick={() => close(false)}>
      <div className="confirm-modal" onClick={(e) => e.stopPropagation()} role="dialog" aria-modal="true">
        <div className="confirm-modal-header">
          <h5><span className="confirm-icon"><i className="bi bi-trash3-fill"></i></span> Delete {titleLabel}</h5>
          <button type="button" className="btn-close-confirm" onClick={() => close(false)} aria-label="Close"><i className="bi bi-x-lg"></i></button>
        </div>
        <div className="confirm-modal-body">
          {state.name
            ? <>Are you sure you want to delete <strong>&ldquo;{state.name}&rdquo;</strong>? This action cannot be undone.</>
            : <>Are you sure you want to delete this {label}? This action cannot be undone.</>}
        </div>
        <div className="confirm-modal-footer">
          <button type="button" className="confirm-btn-cancel" onClick={() => close(false)}>Cancel</button>
          <button type="button" className="confirm-btn-danger" onClick={() => close(true)}>Continue</button>
        </div>
      </div>
    </div>
  );
}

export function ConfirmDelete(entityLabel, name) {
  if (_confirmDeleteImpl) return _confirmDeleteImpl(entityLabel, name);
  // Fallback (host not mounted yet) so the app never hard-fails.
  return Promise.resolve(window.confirm(`Are you sure you want to delete this ${entityLabel}? This action cannot be undone.`));
}

/* ============================================================
   STAT CARD
   ============================================================ */
/** Personalized greeting shown at the top of every dashboard's Overview tab.
 *  `name` must come from the authenticated user (never hard-code it). */
export function WelcomeBanner({ name, subtitle }) {
  const firstName = (name || '').trim().split(/\s+/)[0] || 'there';
  return (
    <div className="welcome-banner mb-4">
      <div className="welcome-banner-icon"><i className="bi bi-hand-thumbs-up-fill"></i></div>
      <div>
        <h5>Welcome back, {firstName}!</h5>
        {subtitle && <p>{subtitle}</p>}
      </div>
    </div>
  );
}

/** Long text in a table cell: shows a short preview, full text on hover
 *  (native tooltip) and toggles fully inline on click - for touch devices
 *  where hover doesn't apply. */
export function TruncatedText({ text, limit = 40 }) {
  const [expanded, setExpanded] = useState(false);
  const value = (text ?? '').toString();
  if (!value.trim()) return <span className="text-muted">-</span>;
  if (value.length <= limit) return <span>{value}</span>;
  return (
    <span
      className="truncated-text"
      title={!expanded ? value : undefined}
      onClick={(e) => { e.stopPropagation(); setExpanded((v) => !v); }}
    >
      {expanded ? value : `${value.slice(0, limit)}…`}
    </span>
  );
}

export function StatCard({ icon, color = 'blue', value, label, colClass = 'col-6 col-sm-6 col-lg-3' }) {
  return (
    <div className={colClass}>
      <div className="stat-card">
        <div className={`stat-icon ${color}`}><i className={`bi ${icon}`}></i></div>
        <div className="stat-info">
          <div className="number">{value}</div>
          <div className="label">{label}</div>
        </div>
      </div>
    </div>
  );
}

/* ============================================================
   GENERIC DATA TABLE
   columns: [{ key, label, render?(row) }]
   ============================================================ */
export function DataTable({ title, icon, columns, rows, searchPlaceholder, addLabel, onAdd, onRefresh, renderActions, emptyText = 'No records found.', filters = [] }) {
  const [query, setQuery] = useState('');
  const [filterValues, setFilterValues] = useState({});
  const [refreshing, setRefreshing] = useState(false);
  const safeRows = rows || [];

  const filtered = safeRows.filter((row) => {
    if (filters.some((filter) => filterValues[filter.key] && (filter.matches ? !filter.matches(row, filterValues[filter.key]) : String(row[filter.key] ?? '') !== filterValues[filter.key]))) return false;
    if (!query.trim()) return true;
    const q = query.toLowerCase();
    return columns.some((c) => String(c.render ? '' : row[c.key] ?? '').toLowerCase().includes(q)) || JSON.stringify(row).toLowerCase().includes(q);
  });

  const handleRefresh = async () => {
    if (!onRefresh || refreshing) return;
    setRefreshing(true);
    try { await onRefresh(); } finally { setRefreshing(false); }
  };

  return (
    <>
      <div className="filter-toolbar filter-toolbar-inline mb-4">
        <div className="d-flex align-items-center gap-2 flex-wrap w-100">
          {onAdd && (
            <button className="btn-blue btn-sm" onClick={onAdd}>
              <i className="bi bi-plus-lg"></i> {addLabel || 'Add'}
            </button>
          )}
          <div className="search-box table-search-box">
            <input type="search" aria-label={searchPlaceholder || 'Search records'} placeholder={searchPlaceholder || 'Search records...'} value={query} onChange={(e) => setQuery(e.target.value)} />
          </div>
          {filters.map((filter) => (
            <select
              key={filter.key}
              className="form-select form-control-custom table-filter-select"
              aria-label={filter.label}
              value={filterValues[filter.key] || ''}
              onChange={(e) => setFilterValues((current) => ({ ...current, [filter.key]: e.target.value }))}
            >
              <option value="">All {filter.label}</option>
              {filter.options.map((option) => <option key={option} value={option}>{option}</option>)}
            </select>
          ))}
        </div>
      </div>

      <div className="table-card">
        <div className="table-header">
          <h6>{icon && <i className={`bi ${icon}`} style={{ color: 'var(--primary-blue)' }}></i>} {title}</h6>
          <div className="table-header-actions">
            <span className="table-count">Showing {filtered.length} of {rows.length}</span>
            {onRefresh && (
              <button type="button" className="btn-blue btn-sm btn-refresh" onClick={handleRefresh} disabled={refreshing} title="Refresh data">
                <i className={`bi bi-arrow-clockwise${refreshing ? ' spin' : ''}`}></i> Refresh
              </button>
            )}
          </div>
        </div>
        <div className="table-responsive">
          <table className="table table-custom">
            <thead>
              <tr>
                {columns.map((c) => <th key={c.key}>{c.label}</th>)}
                {renderActions && <th>Actions</th>}
              </tr>
            </thead>
            <tbody>
              {filtered.length === 0 && (
                <tr><td colSpan={columns.length + (renderActions ? 1 : 0)} className="text-center text-muted py-4">{emptyText}</td></tr>
              )}
              {filtered.map((row, idx) => (
                <tr key={row.id ?? row.ID ?? idx}>
                  {columns.map((c) => <td key={c.key}>{c.render ? c.render(row) : row[c.key]}</td>)}
                  {renderActions && <td className="row-actions">{renderActions(row)}</td>}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </>
  );
}

/* ============================================================
   VIEW-DETAILS MODAL (with optional Print)
   ------------------------------------------------------------
   Reusable read-only "View" popup used across every table in the
   app (Customers, Vehicles, Jobs, Payments, Spare Parts, Suppliers,
   Purchases, ...), mirroring the old openDetailsModal()/
   printModalContent() pair from the original PHP admin.
   fields: [{ label, value }] - falsy entries are skipped.
   ============================================================ */
export function printElementById(bodyId, title) {
  const body = document.getElementById(bodyId);
  if (!body) return;
  const win = window.open('', 'PRINT', 'height=650,width=900,top=100,left=150');
  if (!win) return;
  win.document.write(`<!DOCTYPE html><html><head><title>${title || 'Print'}</title><style>
    body{font-family:'Segoe UI',Arial,sans-serif;color:#1f2937;padding:24px;}
    h5{margin:0 0 12px;}
    table{width:100%;border-collapse:collapse;}
    th,td{padding:8px 10px;text-align:left;border-bottom:1px solid #e5e7eb;font-size:0.92rem;}
    th{width:38%;color:#6b7280;font-weight:600;}
  </style></head><body>`);
  win.document.write(`<h5>${title || ''}</h5>`);
  win.document.write(body.innerHTML);
  win.document.write('</body></html>');
  win.document.close();
  win.focus();
  setTimeout(() => { win.print(); win.close(); }, 250);
}

export function DetailsModal({ id, title, icon = 'bi-eye', fields, printable = false, actions }) {
  const bodyId = `${id}-body`;
  const safeFields = fields || [];
  return (
    <div className="modal fade modal-custom" id={id} tabIndex="-1">
      <div className="modal-dialog modal-dialog-centered">
        <div className="modal-content">
          <div className="modal-header">
            <h5 className="modal-title"><i className={`bi ${icon}`} style={{ color: 'var(--primary-blue)' }}></i> {title}</h5>
            <button type="button" className="btn-close no-print" data-bs-dismiss="modal"></button>
          </div>
          <div className="modal-body" id={bodyId}>
            <table className="table table-borderless mb-0">
              <tbody>
                {safeFields.filter(Boolean).map((f, i) => (
                  <tr key={i}>
                    <th style={{ width: '40%', color: 'var(--text-muted)', fontWeight: 600 }}>{f.label}</th>
                    <td>{f.value === null || f.value === undefined || f.value === '' ? '-' : f.value}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {(printable || actions) && (
            <div className="modal-footer no-print">
              {actions}
              {printable && <button type="button" className="btn-blue btn-sm" onClick={() => printElementById(bodyId, title)}>
                <i className="bi bi-printer"></i> Print
              </button>}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

/** Small helper: `const view = useViewModal('viewCustomerModal'); view.open(row)` then render `<DetailsModal id="viewCustomerModal" fields={...(view.row)} />` */
export function useViewModal(modalId) {
  const [row, setRow] = useState(null);
  const open = (r) => { setRow(r); showBsModal(modalId); };
  return { row, open };
}

export function StatusBadge({ status, okValues = ['Active', 'Paid', 'Delivered', 'Approved'], lowValues = ['Inactive', 'Cancelled', 'Rejected'] }) {
  let cls = 'badge-status';
  if (okValues.includes(status)) cls += ' badge-ok';
  else if (lowValues.includes(status)) cls += ' badge-low';
  else cls += ' badge-pending';
  return <span className={cls}>{status}</span>;
}

/* ============================================================
   DASHBOARD SHELL (sidebar + topbar), shared by all 4 roles
   ============================================================ */
export function DashboardShell({ brandSub, navSections, activeTab, onTabChange, pageTitle, userName, userRole, unreadCount, notifications, onNotificationPreviewClick, onSearch, children }) {
  const { logout } = useAuth();
  const [userMenuOpen, setUserMenuOpen] = useState(false);
  const userToggleRef = useRef(null);
  const userMenuListRef = useRef(null);
  const [userMenuStyle, setUserMenuStyle] = useState({});
  const [notifPreviewOpen, setNotifPreviewOpen] = useState(false);
  const notifRef = useRef(null);

  useEffect(() => {
    if (!notifPreviewOpen) return;
    const onDocClick = (e) => {
      if (notifRef.current && !notifRef.current.contains(e.target)) setNotifPreviewOpen(false);
    };
    const onKeyDown = (e) => { if (e.key === 'Escape') setNotifPreviewOpen(false); };
    document.addEventListener('mousedown', onDocClick);
    document.addEventListener('keydown', onKeyDown);
    return () => {
      document.removeEventListener('mousedown', onDocClick);
      document.removeEventListener('keydown', onKeyDown);
    };
  }, [notifPreviewOpen]);

  const openNotificationsTab = () => {
    setNotifPreviewOpen(false);
    onTabChange('notifications');
  };

  useEffect(() => {
    if (!userMenuOpen) return;
    const onDocClick = (e) => {
      const clickedToggle = userToggleRef.current && userToggleRef.current.contains(e.target);
      const clickedMenu = userMenuListRef.current && userMenuListRef.current.contains(e.target);
      if (!clickedToggle && !clickedMenu) setUserMenuOpen(false);
    };
    const onKeyDown = (e) => { if (e.key === 'Escape') setUserMenuOpen(false); };
    document.addEventListener('mousedown', onDocClick);
    document.addEventListener('keydown', onKeyDown);
    return () => {
      document.removeEventListener('mousedown', onDocClick);
      document.removeEventListener('keydown', onKeyDown);
    };
  }, [userMenuOpen]);

  useLayoutEffect(() => {
    if (!userMenuOpen) return;

    const positionMenu = () => {
      const anchorEl = userToggleRef.current;
      const menuEl = userMenuListRef.current;
      if (!anchorEl || !menuEl) return;

      const anchorRect = anchorEl.getBoundingClientRect();
      const menuRect = menuEl.getBoundingClientRect();
      const gap = 14;
      const edge = 8;
      const left = Math.max(edge, Math.min(anchorRect.right + gap, window.innerWidth - menuRect.width - edge));
      const top = Math.max(edge, Math.min(anchorRect.top, window.innerHeight - menuRect.height - edge));

      setUserMenuStyle({ position: 'fixed', top: `${top}px`, left: `${Math.max(edge, left)}px` });
    };

    positionMenu();
    window.addEventListener('resize', positionMenu);
    window.addEventListener('scroll', positionMenu, true);
    return () => {
      window.removeEventListener('resize', positionMenu);
      window.removeEventListener('scroll', positionMenu, true);
    };
  }, [userMenuOpen]);

  const navigate = useNavigate();
  const [sidebarOpen, setSidebarOpen] = useState(false);

  const handleLogout = async (e) => {
    e.preventDefault();
    await logout();
    navigate('/login');
  };

  return (
    <>
      <div className={`sidebar-overlay${sidebarOpen ? ' show' : ''}`} onClick={() => setSidebarOpen(false)}></div>

      <aside className={`sidebar${sidebarOpen ? ' show' : ''}`}>
        <div className="sidebar-brand">
          <i className="bi bi-wrench-adjustable-circle-fill"></i>
          <div className="brand-text">Garage<span>Manager</span><small>{brandSub}</small></div>
        </div>
        <nav className="sidebar-nav">
          {navSections.map((section, sIdx) => (
            <React.Fragment key={`${section.title}-${sIdx}`}>
              <div className="nav-section">{section.title}</div>
              {section.items.map((item) => (
                <React.Fragment key={item.key}>
                  <a
                    href="#top"
                    onClick={(e) => { e.preventDefault(); onTabChange(item.key); setSidebarOpen(false); }}
                    className={activeTab === item.key ? 'active' : ''}
                  >
                    <i className={`bi ${item.icon}`}></i> {item.label}
                    {item.badge > 0 && <span className="badge bg-danger rounded-pill ms-auto" style={{ fontSize: '0.6rem' }}>{item.badge}</span>}
                  </a>
                </React.Fragment>
              ))}
            </React.Fragment>
          ))}
        </nav>
        <div className="sidebar-footer">
          <div className={`dropdown${userMenuOpen ? ' show' : ''}`}>
            <button
              type="button"
              className="user-info"
              ref={userToggleRef}
              onClick={() => setUserMenuOpen((v) => !v)}
              aria-expanded={userMenuOpen}
              aria-controls="profile-menu"
            >
              <div className="user-avatar">{(userName || 'US').substring(0, 2).toUpperCase()}</div>
              <div className="user-copy">
                <div className="user-name">{userName}</div>
                <div className="user-role">{userRole}</div>
              </div>
              <i className={`bi bi-chevron-${userMenuOpen ? 'up' : 'down'} user-menu-chevron`} aria-hidden="true"></i>
            </button>
          </div>
        </div>
      </aside>

      <ul
        id="profile-menu"
        className={`dropdown-menu sidebar-profile-menu${userMenuOpen ? ' show' : ''}`}
        ref={userMenuListRef}
        style={userMenuStyle}
        aria-hidden={!userMenuOpen}
      >
        <li><a className="dropdown-item" href="#top" onClick={(e) => { e.preventDefault(); setUserMenuOpen(false); showBsModal('profileModal'); }}><i className="bi bi-gear"></i> Settings</a></li>
        <li><hr className="dropdown-divider" /></li>
        <li><a className="dropdown-item" href="#top" onClick={(e) => { setUserMenuOpen(false); handleLogout(e); }}><i className="bi bi-box-arrow-right"></i> Logout</a></li>
      </ul>


      <div className="dashboard-main">
        <div className="dashboard-topbar">
          <div className="d-flex align-items-center gap-2">
            <button className="btn-action d-lg-none" onClick={() => setSidebarOpen((s) => !s)}>
              <i className="bi bi-list"></i>
            </button>
            <h5>{pageTitle}</h5>
          </div>
          <div className="topbar-actions">
            {onSearch && (
              <div className="search-box d-none d-lg-flex">
                <input type="text" placeholder="Search records..." onChange={(e) => onSearch(e.target.value)} />
              </div>
            )}
            <div className="notif-bell-wrap" ref={notifRef}>
              <button
                className="btn-action position-relative"
                onClick={() => (notifications ? setNotifPreviewOpen((v) => !v) : openNotificationsTab())}
                onDoubleClick={openNotificationsTab}
                title="Notifications — click to preview, double-click to open"
              >
                <i className="bi bi-bell-fill" style={{ fontSize: '1.4rem' }}></i>
                {unreadCount > 0 && <span className="badge bg-danger rounded-pill" style={{ position: 'absolute', top: -4, right: -6, fontSize: '0.6rem' }}>{unreadCount}</span>}
              </button>
              {notifPreviewOpen && notifications && (
                <div className="notif-preview-dropdown">
                  <div className="notif-preview-header">
                    <span>Notifications</span>
                    <button type="button" onClick={openNotificationsTab}>View all</button>
                  </div>
                  {notifications.length === 0 ? (
                    <div className="notif-preview-empty">
                      <i className="bi bi-bell-slash"></i>
                      <p>No notifications yet.</p>
                    </div>
                  ) : (
                    <ul className="notif-preview-list">
                      {notifications.slice(0, 5).map((n) => {
                        const isRead = n.IsRead || n.is_read;
                        return (
                          <li
                            key={n.NotificationID}
                            className={isRead ? 'read' : 'unread'}
                            onClick={() => { onNotificationPreviewClick && onNotificationPreviewClick(n); openNotificationsTab(); }}
                          >
                            <span className="notif-dot"></span>
                            <div>
                              <p>{n.Message || n.message}</p>
                              {(n.CreatedAt || n.created_at) && <span className="notif-time">{n.CreatedAt || n.created_at}</span>}
                            </div>
                          </li>
                        );
                      })}
                    </ul>
                  )}
                </div>
              )}
            </div>
          </div>
        </div>
        <div className="dashboard-content">{children}</div>
      </div>
      <ConfirmDeleteHost />
    </>
  );
}
