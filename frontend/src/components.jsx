import React, { useEffect, useState } from 'react';
import { Link, NavLink, Navigate, useNavigate } from 'react-router-dom';
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
  return (
    <nav className={`navbar navbar-expand-lg navbar-custom fixed-top${scrolled ? ' scrolled' : ''}`} id="mainNav">
      <div className="container">
        <NavLink className="navbar-brand" to="/">
          <i className="bi bi-wrench-adjustable-circle-fill"></i> Garage<span>Manager</span>
        </NavLink>
        <button className="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu">
          <span className="navbar-toggler-icon"></span>
        </button>
        <div className="collapse navbar-collapse" id="navMenu">
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

export function PublicLayout({ children }) {
  const { loaderHidden, scrolled, showBackToTop, scrollToTop } = usePublicChrome();
  return (
    <>
      <PageLoader hidden={loaderHidden} />
      <PublicNavbar scrolled={scrolled} />
      {children}
      <PublicFooter />
      <BackToTop show={showBackToTop} onClick={scrollToTop} />
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
export function DataTable({ title, icon, columns, rows, searchPlaceholder, addLabel, onAdd, renderActions, emptyText = 'No records found.' }) {
  const [query, setQuery] = useState('');

  const filtered = rows.filter((row) => {
    if (!query.trim()) return true;
    const q = query.toLowerCase();
    return columns.some((c) => String(c.render ? '' : row[c.key] ?? '').toLowerCase().includes(q)) || JSON.stringify(row).toLowerCase().includes(q);
  });

  return (
    <>
      <div className="filter-toolbar filter-toolbar-inline mb-4">
        <div className="d-flex align-items-center gap-2 flex-wrap w-100">
          {onAdd && (
            <button className="btn-blue btn-sm" onClick={onAdd}>
              <i className="bi bi-plus-lg"></i> {addLabel || 'Add'}
            </button>
          )}
          <div className="search-box">
            <i className="bi bi-search"></i>
            <input type="text" placeholder={searchPlaceholder || 'Search...'} value={query} onChange={(e) => setQuery(e.target.value)} />
          </div>
        </div>
      </div>

      <div className="table-card">
        <div className="table-header">
          <h6>{icon && <i className={`bi ${icon}`} style={{ color: 'var(--primary-blue)' }}></i>} {title}</h6>
          <span style={{ fontSize: '0.8rem', color: 'var(--text-muted)' }}>Showing {filtered.length} of {rows.length}</span>
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
export function DashboardShell({ brandSub, navSections, activeTab, onTabChange, pageTitle, userName, userRole, unreadCount, onSearch, children }) {
  const { logout } = useAuth();
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
          {navSections.map((section) => (
            <React.Fragment key={section.title}>
              <div className="nav-section">{section.title}</div>
              {section.items.map((item) => (
                <a
                  key={item.key}
                  href="#top"
                  onClick={(e) => { e.preventDefault(); onTabChange(item.key); setSidebarOpen(false); }}
                  className={activeTab === item.key ? 'active' : ''}
                >
                  <i className={`bi ${item.icon}`}></i> {item.label}
                  {item.badge > 0 && <span className="badge bg-danger rounded-pill ms-auto" style={{ fontSize: '0.6rem' }}>{item.badge}</span>}
                </a>
              ))}
            </React.Fragment>
          ))}
        </nav>
        <div className="sidebar-footer">
          <div className="dropdown">
            <div className="user-info" data-bs-toggle="dropdown" aria-expanded="false">
              <div className="user-avatar">{(userName || 'US').substring(0, 2).toUpperCase()}</div>
              <div>
                <div className="user-name">{userName}</div>
                <div className="user-role">{userRole}</div>
              </div>
            </div>
            <ul className="dropdown-menu">
              <li><a className="dropdown-item" href="#top" data-bs-toggle="modal" data-bs-target="#profileModal"><i className="bi bi-gear"></i> Settings</a></li>
              <li><hr className="dropdown-divider" /></li>
              <li><a className="dropdown-item" href="#top" onClick={handleLogout}><i className="bi bi-box-arrow-right"></i> Logout</a></li>
            </ul>
          </div>
        </div>
      </aside>

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
                <i className="bi bi-search"></i>
                <input type="text" placeholder="Search records..." onChange={(e) => onSearch(e.target.value)} />
              </div>
            )}
            <button className="btn-action position-relative" onClick={() => onTabChange('notifications')} title="Notifications">
              <i className="bi bi-bell-fill" style={{ fontSize: '1.4rem' }}></i>
              {unreadCount > 0 && <span className="badge bg-danger rounded-pill" style={{ position: 'absolute', top: -4, right: -6, fontSize: '0.6rem' }}>{unreadCount}</span>}
            </button>
          </div>
        </div>
        <div className="dashboard-content">{children}</div>
      </div>
      <ConfirmDeleteHost />
    </>
  );
}
