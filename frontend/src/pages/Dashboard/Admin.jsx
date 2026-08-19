import React, { useCallback, useEffect, useMemo, useState } from 'react';
import '../../assets/staff.css';
import { DashboardShell, DataTable, Modal, DetailsModal, useViewModal, StatCard, WelcomeBanner, StatusBadge, showBsModal, hideBsModal, ConfirmDelete } from '../../components';
import { phoneError, digitsOnly, todayStr } from '../../utils/validators';
import { jobStatusLabel, normalizeJobStatus } from '../../utils/jobStatus';
import { useAuth, useToast } from '../../context';
import { usersApi, jobsApi, inventoryApi, notificationsApi, contactApi, authApi, customersApi, billingApi } from '../../api';

const NAV_SECTIONS = [
  {
    title: 'Main',
    items: [
      { key: 'dashboard', label: 'Dashboard', icon: 'bi-speedometer2' },
      { key: 'financial', label: 'Financial Overview', icon: 'bi-wallet2' },
      { key: 'notifications', label: 'Notifications', icon: 'bi-bell-fill' },
      { key: 'messages', label: 'Messages', icon: 'bi-envelope-fill' },
    ],
  },
  {
    title: 'Administration',
    items: [
      { key: 'users', label: 'Users', icon: 'bi-people-fill' },
      { key: 'mechanics', label: 'Mechanics', icon: 'bi-wrench' },
      { key: 'suppliers', label: 'Suppliers', icon: 'bi-truck' },
      { key: 'spareparts', label: 'Spare Parts', icon: 'bi-boxes' },
    ],
  },
  {
    title: 'Insights & System',
    items: [
      { key: 'reports', label: 'Reports', icon: 'bi-bar-chart-line-fill' },
    ],
  },
];

const ROLE_OPTIONS = ['Admin', 'Receptionist', 'Mechanic', 'Stock Manager'];
const SPECIALIZATION_OPTIONS = ['Engine Repair', 'Transmission', 'Electrical', 'Brake Systems', 'Suspension', 'AC & Cooling', 'Bodywork & Paint', 'Diagnostics', 'General Maintenance'];
const COMPLETED_JOB_STATUSES = ['Delivered', 'Ready'];
const NOTIFICATION_ICONS = { job: 'bi-plus-circle-fill', stock: 'bi-box-seam-fill', payment: 'bi-cash-coin', system: 'bi-info-circle-fill' };
const NOTIFICATION_COLORS = { job: '#2563eb', stock: '#d97706', payment: '#16a34a', system: '#64748b' };

const emptyUser = { UserID: null, Username: '', Password: '', ConfirmPassword: '', Role: '', FullName: '', Email: '', Phone: '', Status: 'Active', MechanicSpecialization: '', MechanicSalary: '' };
const emptyMechanic = { MechanicID: null, FullName: '', Phone: '', Specialization: '', Salary: '' };
const emptySupplier = { SupplierID: null, CompanyName: '', Phone: '', Email: '', Address: '' };
const emptyPart = { SparePartID: null, PartName: '', CategoryID: '', SupplierID: '', Quantity: '', ReorderLevel: '', UnitPrice: '' };
const emptyNotification = { NotificationID: null, UserID: '', Type: 'system', Message: '', Link: '' };

function fmtMoney(v) {
  const n = Number(v || 0);
  return `${n.toLocaleString('en-US')} RWF`;
}
function fmtDate(v) {
  if (!v) return '-';
  const d = new Date(v);
  if (Number.isNaN(d.getTime())) return v;
  return d.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' });
}
function fmtDateTime(v) {
  if (!v) return '-';
  const d = new Date(v);
  if (Number.isNaN(d.getTime())) return v;
  return d.toLocaleString('en-US', { month: 'short', day: '2-digit', hour: 'numeric', minute: '2-digit' });
}

/** Opens a print-friendly window so the browser's native "Save as PDF" can be used. */
function printReport(title, columns, rows) {
  const win = window.open('', '_blank', 'width=1000,height=700');
  if (!win) return;
  const head = columns.map((c) => `<th>${c.label}</th>`).join('');
  const body = rows
    .map((r) => `<tr>${columns.map((c) => `<td>${c.render ? c.render(r) : (r[c.key] ?? '-')}</td>`).join('')}</tr>`)
    .join('');
  win.document.write(`
    <html><head><title>${title}</title>
    <style>
      body{font-family:Arial,Helvetica,sans-serif;padding:24px;color:#0f172a;}
      h2{margin-bottom:4px;} p{color:#64748b;margin-top:0;font-size:0.85rem;}
      table{width:100%;border-collapse:collapse;margin-top:16px;}
      th,td{border:1px solid #e2e8f0;padding:8px 10px;font-size:0.85rem;text-align:left;}
      th{background:#f1f5f9;}
    </style></head><body>
    <h2>${title}</h2><p>GarageManager &mdash; generated ${new Date().toLocaleString()}</p>
    <table><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table>
    </body></html>`);
  win.document.close();
  win.focus();
  setTimeout(() => win.print(), 300);
}

/** Downloads the given rows as a CSV file (openable in Excel). */
function downloadCsv(filename, columns, rows) {
  const esc = (v) => `"${String(v ?? '').replace(/"/g, '""')}"`;
  const header = columns.map((c) => esc(c.label)).join(',');
  const lines = rows.map((r) => columns.map((c) => esc(c.render ? c.render(r) : r[c.key])).join(','));
  const csv = [header, ...lines].join('\r\n');
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `${filename}.csv`;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
}

/** A single Reports sub-tab: 3 stat cards + a table with PDF / Excel export buttons. */
function ReportPanel({ icon, title, stats, columns, rows, emptyText = 'No records found.' }) {
  return (
    <>
      <div className="row g-3 mb-1">
        {stats.map((s) => (
          <StatCard key={s.label} icon={s.icon} color={s.color} value={s.value} label={s.label} colClass="col-6 col-sm-4 col-lg-4" />
        ))}
      </div>
      <div className="table-card mt-3">
        <div className="table-header">
          <h6><i className={`bi ${icon}`} style={{ color: 'var(--primary-blue)' }}></i> {title}</h6>
          <div className="d-flex gap-2">
            <button className="btn-outline-blue btn-sm" onClick={() => printReport(title, columns, rows)}>
              <i className="bi bi-file-earmark-pdf"></i> PDF
            </button>
            <button className="btn-outline-blue btn-sm" onClick={() => downloadCsv(title.replace(/\s+/g, '_').toLowerCase(), columns, rows)}>
              <i className="bi bi-file-earmark-excel"></i> Excel
            </button>
          </div>
        </div>
        <div className="table-responsive">
          <table className="table table-custom">
            <thead><tr>{columns.map((c) => <th key={c.key}>{c.label}</th>)}</tr></thead>
            <tbody>
              {rows.length === 0 && <tr><td colSpan={columns.length} className="text-center text-muted py-4">{emptyText}</td></tr>}
              {rows.map((r, i) => (
                <tr key={r.id ?? i}>{columns.map((c) => <td key={c.key}>{c.render ? c.render(r) : (r[c.key] ?? '-')}</td>)}</tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </>
  );
}

export default function Admin() {
  const { user } = useAuth();
  const { showToast } = useToast();
  const [activeTab, setActiveTab] = useState('dashboard');
  const [reportTab, setReportTab] = useState('repairs');
  const viewPart = useViewModal('viewPartModal');
  const viewUser = useViewModal('viewUserModal');
  const viewMechanic = useViewModal('viewMechanicModal');
  const viewSupplier = useViewModal('viewSupplierModal');
  const viewNotification = useViewModal('viewNotificationModal');
  const viewMessage = useViewModal('viewMessageModal');

  const [users, setUsers] = useState([]);
  const [mechanics, setMechanics] = useState([]);
  const [suppliers, setSuppliers] = useState([]);
  const [categories, setCategories] = useState([]);
  const [spareParts, setSpareParts] = useState([]);
  const [notifications, setNotifications] = useState([]);
  const [messages, setMessages] = useState([]);
  const [customers, setCustomers] = useState([]);
  const [vehicles, setVehicles] = useState([]);
  const [jobs, setJobs] = useState([]);
  const [invoices, setInvoices] = useState([]);
  const [payments, setPayments] = useState([]);
  const [purchases, setPurchases] = useState([]);
  const [loading, setLoading] = useState(true);

  const [userForm, setUserForm] = useState(emptyUser);
  const [mechanicForm, setMechanicForm] = useState(emptyMechanic);
  const [supplierForm, setSupplierForm] = useState(emptySupplier);
  const [partForm, setPartForm] = useState(emptyPart);
  const [notificationForm, setNotificationForm] = useState(emptyNotification);
  const [profileForm, setProfileForm] = useState({ full_name: user?.name || '', username: user?.username || '', email: user?.email || '', phone: user?.phone || '', current_password: '', new_password: '', confirm_password: '' });

  const loadAll = useCallback(async () => {
    setLoading(true);
    const [u, m, s, cat, sp, n, msg, c, v, j, inv, pay, pur] = await Promise.all([
      usersApi.list(),
      jobsApi.listMechanics(),
      inventoryApi.listSuppliers(),
      inventoryApi.listCategories(),
      inventoryApi.listSpareParts(),
      notificationsApi.listAll(),
      contactApi.list(),
      customersApi.listCustomers(),
      customersApi.listVehicles(),
      jobsApi.listJobs(),
      billingApi.listInvoices(),
      billingApi.listPayments(),
      inventoryApi.listPurchases(),
    ]);
    if (u.success) setUsers(u.data || []);
    if (m.success) setMechanics(m.data || []);
    if (s.success) setSuppliers(s.data || []);
    if (cat.success) setCategories(cat.data || []);
    if (sp.success) setSpareParts(sp.data || []);
    if (n.success) setNotifications(n.data || []);
    if (msg.success) setMessages(msg.data || []);
    if (c.success) setCustomers(c.data || []);
    if (v.success) setVehicles(v.data || []);
    if (j.success) setJobs(j.data || []);
    if (inv.success) setInvoices(inv.data || []);
    if (pay.success) setPayments(pay.data || []);
    if (pur.success) setPurchases(pur.data || []);
    setLoading(false);
  }, []);

  useEffect(() => { loadAll(); }, [loadAll]);

  // Live Active/Inactive status (set on login/logout) is only accurate as
  // of the last fetch. Poll it quietly in the background - no loading
  // spinner, no disruption to whatever the admin is doing - so status
  // reflects reality without requiring a manual page refresh.
  useEffect(() => {
    const poll = setInterval(async () => {
      const [u, m] = await Promise.all([usersApi.list(), jobsApi.listMechanics()]);
      if (u.success) setUsers(u.data || []);
      if (m.success) setMechanics(m.data || []);
    }, 20000);
    return () => clearInterval(poll);
  }, []);

  const unreadCount = useMemo(() => notifications.filter((n) => !n.IsRead && !n.is_read).length, [notifications]);
  const unreadMessages = useMemo(() => messages.filter((m) => !m.IsRead && !m.is_read).length, [messages]);
  const totalInvoiceValue = useMemo(() => invoices.reduce((sum, invoice) => sum + Number(invoice.TotalAmount || 0), 0), [invoices]);

  const financialSummary = useMemo(() => {
    const totalInvoices = invoices.length;
    const totalDue = invoices.reduce((sum, invoice) => sum + Number(invoice.TotalAmount || 0), 0);
    const totalPaid = invoices.reduce((sum, invoice) => sum + Number(invoice.TotalPaid || 0), 0);
    const totalUnpaid = Math.max(totalDue - totalPaid, 0);
    const outstandingDebts = invoices
      .filter((invoice) => Number(invoice.TotalAmount || 0) > Number(invoice.TotalPaid || 0))
      .reduce((sum, invoice) => sum + (Number(invoice.TotalAmount || 0) - Number(invoice.TotalPaid || 0)), 0);
    const paymentRate = totalDue > 0 ? (totalPaid / totalDue) * 100 : 0;
    const debtStatus = paymentRate >= 80 ? 'Healthy' : paymentRate >= 50 ? 'Watchlist' : 'Critical';
    const debtStatusClass = paymentRate >= 80 ? 'success' : paymentRate >= 50 ? 'warning' : 'danger';

    return {
      totalInvoices,
      totalDue,
      totalPaid,
      totalUnpaid,
      outstandingDebts,
      paymentRate,
      debtStatus,
      debtStatusClass,
    };
  }, [invoices]);

  // ---- Users CRUD ----
  const openAddUser = () => { setUserForm(emptyUser); showBsModal('userModal'); };
  const openEditUser = (u) => {
    const linkedMechanic = u.MechanicID ? mechanics.find((m) => m.MechanicID === u.MechanicID) : null;
    setUserForm({
      ...emptyUser,
      ...u,
      Password: '',
      ConfirmPassword: '',
      MechanicSpecialization: linkedMechanic?.Specialization || '',
      MechanicSalary: linkedMechanic?.Salary || '',
    });
    showBsModal('userModal');
  };
  const saveUser = async (e) => {
    e.preventDefault();
    if (userForm.Password && userForm.Password !== userForm.ConfirmPassword) {
      showToast('Passwords do not match.', 'danger');
      return;
    }
    if (phoneError(userForm.Phone)) { showToast(phoneError(userForm.Phone), 'danger'); return; }
    const res = await usersApi.save(userForm);
    if (res.success) {
      showToast(userForm.UserID ? 'User updated.' : 'User created.', 'success');
      hideBsModal('userModal');
      loadAll();
    } else {
      showToast(res.message || 'Could not save user.', 'danger');
    }
  };
  const deleteUser = async (u) => {
    if (!(await ConfirmDelete('user', u.FullName))) return;
    const res = await usersApi.remove(u.UserID);
    showToast(res.success ? 'User deleted.' : res.message || 'Could not delete user.', res.success ? 'success' : 'danger');
    if (res.success) loadAll();
  };

  // ---- Mechanics CRUD (edit / delete only - creation happens via Users) ----
  const openEditMechanic = (m) => { setMechanicForm({ ...emptyMechanic, ...m }); showBsModal('mechanicModal'); };
  const saveMechanic = async (e) => {
    e.preventDefault();
    if (phoneError(mechanicForm.Phone)) { showToast(phoneError(mechanicForm.Phone), 'danger'); return; }
    const res = await jobsApi.saveMechanic(mechanicForm);
    if (res.success) {
      showToast('Mechanic updated.', 'success');
      hideBsModal('mechanicModal');
      loadAll();
    } else {
      showToast(res.message || 'Could not save mechanic.', 'danger');
    }
  };
  const deleteMechanic = async (m) => {
    if (!(await ConfirmDelete('mechanic', m.FullName))) return;
    const res = await jobsApi.removeMechanic(m.MechanicID);
    showToast(res.success ? 'Mechanic deleted.' : res.message || 'Could not delete mechanic.', res.success ? 'success' : 'danger');
    if (res.success) loadAll();
  };

  // ---- Suppliers CRUD ----
  const openAddSupplier = () => { setSupplierForm(emptySupplier); showBsModal('supplierModal'); };
  const openEditSupplier = (s) => { setSupplierForm({ ...emptySupplier, ...s }); showBsModal('supplierModal'); };
  const saveSupplier = async (e) => {
    e.preventDefault();
    if (phoneError(supplierForm.Phone)) { showToast(phoneError(supplierForm.Phone), 'danger'); return; }
    const res = await inventoryApi.saveSupplier(supplierForm);
    if (res.success) {
      showToast(supplierForm.SupplierID ? 'Supplier updated.' : 'Supplier added.', 'success');
      hideBsModal('supplierModal');
      loadAll();
    } else {
      showToast(res.message || 'Could not save supplier.', 'danger');
    }
  };
  const deleteSupplier = async (s) => {
    if (!(await ConfirmDelete('supplier', s.CompanyName))) return;
    const res = await inventoryApi.removeSupplier(s.SupplierID);
    showToast(res.success ? 'Supplier deleted.' : res.message || 'Could not delete supplier.', res.success ? 'success' : 'danger');
    if (res.success) loadAll();
  };

  // ---- Spare Parts CRUD ----
  const openAddPart = () => { setPartForm(emptyPart); showBsModal('partModal'); };
  const openEditPart = (p) => { setPartForm({ ...emptyPart, ...p }); showBsModal('partModal'); };
  const savePart = async (e) => {
    e.preventDefault();
    const res = await inventoryApi.saveSparePart(partForm);
    if (res.success) {
      showToast(partForm.SparePartID ? 'Spare part updated.' : 'Spare part added.', 'success');
      hideBsModal('partModal');
      loadAll();
    } else {
      showToast(res.message || 'Could not save spare part.', 'danger');
    }
  };
  const deletePart = async (p) => {
    if (!(await ConfirmDelete('spare part', p.PartName))) return;
    const res = await inventoryApi.removeSparePart(p.SparePartID);
    showToast(res.success ? 'Spare part deleted.' : res.message || 'Could not delete spare part.', res.success ? 'success' : 'danger');
    if (res.success) loadAll();
  };

  // ---- Notifications ----
  const markAllRead = async () => {
    const res = await notificationsApi.markAllRead();
    if (res.success) { showToast('All notifications marked as read.', 'success'); loadAll(); }
  };
  const markOneRead = async (n) => {
    if (n.IsRead || n.is_read) return;
    setNotifications((prev) => prev.map((x) => (x.NotificationID === n.NotificationID ? { ...x, IsRead: true } : x)));
    await notificationsApi.markRead(n.NotificationID);
  };
  // Opening the Notifications tab is "viewing" them - automatically mark
  // whatever is currently unread as read/seen, same as the preview dropdown.
  useEffect(() => {
    if (activeTab !== 'notifications') return;
    setNotifications((prev) => {
      if (!prev.some((n) => !n.IsRead && !n.is_read)) return prev;
      notificationsApi.markAllRead();
      return prev.map((n) => ({ ...n, IsRead: true }));
    });
  }, [activeTab]);
  const openAddNotification = () => { setNotificationForm(emptyNotification); showBsModal('notificationModal'); };
  const openEditNotification = (n) => { setNotificationForm({ ...emptyNotification, ...n, UserID: n.UserID ?? '' }); showBsModal('notificationModal'); };
  const saveNotification = async (e) => {
    e.preventDefault();
    const res = await notificationsApi.save({ ...notificationForm, UserID: notificationForm.UserID || null });
    if (res.success) {
      showToast(notificationForm.NotificationID ? 'Notification updated.' : 'Notification created.', 'success');
      hideBsModal('notificationModal');
      loadAll();
    } else {
      showToast(res.message || 'Could not save notification.', 'danger');
    }
  };
  const deleteNotification = async (n) => {
    if (!(await ConfirmDelete('notification', n.Message ? `${n.Message.slice(0, 40)}${n.Message.length > 40 ? '…' : ''}` : undefined))) return;
    const res = await notificationsApi.remove(n.NotificationID);
    showToast(res.success ? 'Notification deleted.' : res.message || 'Could not delete.', res.success ? 'success' : 'danger');
    if (res.success) loadAll();
  };

  // ---- Messages ----
  const markAllMessagesRead = async () => {
    const res = await contactApi.markAllRead();
    if (res.success) { showToast('All messages marked as read.', 'success'); loadAll(); }
  };
  const markOneMessageRead = async (m) => {
    if (m.IsRead || m.is_read) return;
    setMessages((prev) => prev.map((x) => (x.MessageID === m.MessageID ? { ...x, IsRead: true } : x)));
    await contactApi.markRead(m.MessageID);
  };
  const deleteMessage = async (m) => {
    if (!(await ConfirmDelete('message', m.Subject || m.FullName))) return;
    const res = await contactApi.remove(m.MessageID);
    showToast(res.success ? 'Message deleted.' : res.message || 'Could not delete.', res.success ? 'success' : 'danger');
    if (res.success) loadAll();
  };

  // ---- Profile / Settings ----
  const saveProfile = async (e) => {
    e.preventDefault();
    if (phoneError(profileForm.phone)) { showToast(phoneError(profileForm.phone), 'danger'); return; }
    const res = await authApi.updateProfile(profileForm);
    showToast(res.success ? 'Profile updated.' : res.message || 'Could not update profile.', res.success ? 'success' : 'danger');
  };

  const pageTitles = {
    dashboard: 'Dashboard', financial: 'Financial Overview', users: 'Manage Users', mechanics: 'Manage Mechanics', suppliers: 'Manage Suppliers',
    spareparts: 'Manage Spare Parts', reports: 'Manage Reports', messages: 'Messages', notifications: 'Notifications',
  };

  // ---- Reports data (built from the same lists already loaded for the other tabs) ----
  const reportTabs = [
    { key: 'repairs', label: 'Repairs', icon: 'bi-clipboard2-pulse-fill' },
    { key: 'customers', label: 'Customers', icon: 'bi-people-fill' },
    { key: 'mechanics', label: 'Mechanics', icon: 'bi-person-badge-fill' },
    { key: 'inventory', label: 'Inventory', icon: 'bi-boxes' },
    { key: 'suppliers', label: 'Suppliers', icon: 'bi-truck' },
    { key: 'purchases', label: 'Purchases', icon: 'bi-cart-fill' },
    { key: 'payments', label: 'Payments', icon: 'bi-credit-card-fill' },
    { key: 'vehicles', label: 'Vehicles', icon: 'bi-truck-front-fill' },
  ];

  const now = new Date();
  const isThisMonth = (v) => { if (!v) return false; const d = new Date(v); return d.getMonth() === now.getMonth() && d.getFullYear() === now.getFullYear(); };

  const reportPanel = () => {
    if (reportTab === 'repairs') {
      const completed = jobs.filter((j) => COMPLETED_JOB_STATUSES.includes(normalizeJobStatus(j.Status))).length;
      return (
        <ReportPanel
          icon="bi-clipboard2-pulse-fill" title="Repairs Report"
          stats={[
            { label: 'Total Repairs', value: jobs.length, icon: 'bi-clipboard2-pulse-fill', color: 'blue' },
            { label: 'Completed', value: completed, icon: 'bi-check-circle-fill', color: 'green' },
            { label: 'In Progress', value: jobs.filter((j) => !['Delivered', 'Ready', 'Cancelled'].includes(normalizeJobStatus(j.Status))).length, icon: 'bi-clock-fill', color: 'amber' },
          ]}
          columns={[
            { key: 'JobID', label: 'JobID' },
            { key: 'CustomerName', label: 'Customer', render: (r) => r.CustomerName || '-' },
            { key: 'PlateNumber', label: 'Vehicle', render: (r) => r.PlateNumber || '-' },
            { key: 'MechanicName', label: 'Mechanic', render: (r) => r.MechanicName || 'Unassigned' },
            { key: 'StartDate', label: 'Start Date', render: (r) => fmtDate(r.StartDate) },
            { key: 'Status', label: 'Status', render: (r) => <StatusBadge status={jobStatusLabel(r.Status)} okValues={['Delivered', 'Ready']} lowValues={['Cancelled']} /> },
          ]}
          rows={jobs}
        />
      );
    }
    if (reportTab === 'customers') {
      const newThisMonth = customers.filter((c) => isThisMonth(c.RegistrationDate)).length;
      return (
        <ReportPanel
          icon="bi-people-fill" title="Customers Report"
          stats={[
            { label: 'Total Customers', value: customers.length, icon: 'bi-people-fill', color: 'blue' },
            { label: 'Active', value: customers.length, icon: 'bi-person-check-fill', color: 'green' },
            { label: 'New (this month)', value: newThisMonth, icon: 'bi-person-plus-fill', color: 'amber' },
          ]}
          columns={[
            { key: 'CustomerID', label: 'CustomerID' },
            { key: 'FullName', label: 'FullName' },
            { key: 'Phone', label: 'Phone' },
            { key: 'Email', label: 'Email', render: (r) => r.Email || '-' },
            { key: 'Address', label: 'Address', render: (r) => r.Address || '-' },
            { key: 'RegistrationDate', label: 'RegistrationDate', render: (r) => fmtDate(r.RegistrationDate) },
          ]}
          rows={customers}
        />
      );
    }
    if (reportTab === 'mechanics') {
      const totalJobs = mechanics.reduce((a, m) => a + (Number(m.AssignedJobs) || 0), 0);
      return (
        <ReportPanel
          icon="bi-person-badge-fill" title="Mechanics Report"
          stats={[
            { label: 'Total Mechanics', value: mechanics.length, icon: 'bi-person-badge-fill', color: 'blue' },
            { label: 'Active', value: mechanics.filter((m) => m.Status === 'Active').length, icon: 'bi-check-circle-fill', color: 'green' },
            { label: 'Jobs Assigned', value: totalJobs, icon: 'bi-clock-fill', color: 'amber' },
          ]}
          columns={[
            { key: 'MechanicID', label: 'MechanicID' },
            { key: 'FullName', label: 'FullName' },
            { key: 'Phone', label: 'Phone', render: (r) => r.Phone || '-' },
            { key: 'Specialization', label: 'Specialization', render: (r) => r.Specialization || '-' },
            { key: 'Salary', label: 'Salary', render: (r) => fmtMoney(r.Salary) },
            { key: 'AssignedJobs', label: 'Jobs' },
          ]}
          rows={mechanics}
        />
      );
    }
    if (reportTab === 'inventory') {
      const low = spareParts.filter((p) => Number(p.Quantity) <= Number(p.ReorderLevel)).length;
      const health = spareParts.length ? Math.round(((spareParts.length - low) / spareParts.length) * 100) : 100;
      return (
        <ReportPanel
          icon="bi-boxes" title="Inventory Report"
          stats={[
            { label: 'Total Parts', value: spareParts.length, icon: 'bi-boxes', color: 'blue' },
            { label: 'Low Stock', value: low, icon: 'bi-exclamation-triangle-fill', color: 'red' },
            { label: 'Stock Health', value: `${health}%`, icon: 'bi-check-circle-fill', color: 'green' },
          ]}
          columns={[
            { key: 'PartName', label: 'PartName' },
            { key: 'CategoryName', label: 'Category', render: (r) => r.CategoryName || '-' },
            { key: 'Quantity', label: 'Quantity' },
            { key: 'ReorderLevel', label: 'Min Level' },
            { key: 'UnitPrice', label: 'Unit Price', render: (r) => fmtMoney(r.UnitPrice) },
            { key: 'SupplierName', label: 'Supplier', render: (r) => r.SupplierName || '-' },
          ]}
          rows={spareParts}
        />
      );
    }
    if (reportTab === 'suppliers') {
      const totalPurchases = suppliers.reduce((a, s) => a + (Number(s.PurchaseCount) || 0), 0);
      return (
        <ReportPanel
          icon="bi-truck" title="Suppliers Report"
          stats={[
            { label: 'Total Suppliers', value: suppliers.length, icon: 'bi-truck', color: 'blue' },
            { label: 'Active', value: suppliers.length, icon: 'bi-check-circle-fill', color: 'green' },
            { label: 'Purchases', value: totalPurchases, icon: 'bi-cart-fill', color: 'amber' },
          ]}
          columns={[
            { key: 'CompanyName', label: 'CompanyName' },
            { key: 'Phone', label: 'Phone', render: (r) => r.Phone || '-' },
            { key: 'Email', label: 'Email', render: (r) => r.Email || '-' },
            { key: 'Address', label: 'Address', render: (r) => r.Address || '-' },
            { key: 'PurchaseCount', label: 'Purchases', render: (r) => r.PurchaseCount ?? 0 },
          ]}
          rows={suppliers}
        />
      );
    }
    if (reportTab === 'purchases') {
      const total = purchases.reduce((a, p) => a + Number(p.TotalAmount || 0), 0);
      const thisMonth = purchases.filter((p) => isThisMonth(p.PurchaseDate)).length;
      return (
        <ReportPanel
          icon="bi-cart-fill" title="Purchases Report"
          stats={[
            { label: 'Total Purchases', value: purchases.length, icon: 'bi-cart-fill', color: 'blue' },
            { label: 'Total Amount (RWF)', value: total.toLocaleString('en-US'), icon: 'bi-cash-stack', color: 'green' },
            { label: 'This Month', value: thisMonth, icon: 'bi-clock-fill', color: 'amber' },
          ]}
          columns={[
            { key: 'PurchaseID', label: 'PurchaseID' },
            { key: 'PurchaseDate', label: 'Date', render: (r) => fmtDate(r.PurchaseDate) },
            { key: 'TotalAmount', label: 'Total Amount', render: (r) => fmtMoney(r.TotalAmount) },
            { key: 'SupplierName', label: 'Supplier', render: (r) => r.SupplierName || '-' },
            { key: 'UserName', label: 'User', render: (r) => r.UserName || '-' },
          ]}
          rows={purchases}
        />
      );
    }
    if (reportTab === 'payments') {
      const completed = payments.filter((p) => p.PaymentStatus === 'Paid').length;
      const pending = payments.filter((p) => p.PaymentStatus !== 'Paid').length;
      return (
        <ReportPanel
          icon="bi-credit-card-fill" title="Payments Report"
          stats={[
            { label: 'Total Payments', value: payments.length, icon: 'bi-credit-card-fill', color: 'blue' },
            { label: 'Completed', value: completed, icon: 'bi-check-circle-fill', color: 'green' },
            { label: 'Pending', value: pending, icon: 'bi-clock-fill', color: 'amber' },
          ]}
          columns={[
            { key: 'PaymentID', label: 'PaymentID' },
            { key: 'CustomerName', label: 'Customer', render: (r) => r.CustomerName || '-' },
            { key: 'Amount', label: 'Amount', render: (r) => fmtMoney(r.Amount) },
            { key: 'PaymentMethod', label: 'Method', render: (r) => r.PaymentMethod || '-' },
            { key: 'PaymentStatus', label: 'Status', render: (r) => <StatusBadge status={r.PaymentStatus} okValues={['Paid']} lowValues={['Pending']} /> },
            { key: 'PaymentDate', label: 'Date', render: (r) => fmtDate(r.PaymentDate) },
          ]}
          rows={payments}
        />
      );
    }
    // vehicles
    return (
      <ReportPanel
        icon="bi-truck-front-fill" title="Vehicles Report"
        stats={[
          { label: 'Total Vehicles', value: vehicles.length, icon: 'bi-truck-front-fill', color: 'blue' },
          { label: 'Active', value: vehicles.length, icon: 'bi-check-circle-fill', color: 'green' },
          { label: 'Inactive', value: 0, icon: 'bi-clock-fill', color: 'amber' },
        ]}
        columns={[
          { key: 'VehicleID', label: 'VehicleID' },
          { key: 'PlateNumber', label: 'Plate' },
          { key: 'Manufacturer', label: 'Manufacturer' },
          { key: 'Model', label: 'Model' },
          { key: 'Year', label: 'Year' },
          { key: 'Transmission', label: 'Transmission', render: (r) => r.Transmission || '-' },
          { key: 'OwnerName', label: 'Customer', render: (r) => r.OwnerName || '-' },
        ]}
        rows={vehicles}
      />
    );
  };

  return (
    <DashboardShell
      brandSub="Admin Panel"
      navSections={NAV_SECTIONS.map((s) => ({
        ...s,
        items: s.items.map((it) => {
          if (it.key === 'notifications') return { ...it, badge: unreadCount };
          if (it.key === 'messages') return { ...it, badge: unreadMessages };
          return it;
        }),
      }))}
      activeTab={activeTab}
      onTabChange={setActiveTab}
      pageTitle={pageTitles[activeTab]}
      userName={user?.name}
      userRole="Administrator"
      unreadCount={unreadCount}
      notifications={notifications}
      onNotificationPreviewClick={markOneRead}
    >
      {loading ? (
        <div className="text-center py-5"><span className="spinner-border" /></div>
      ) : (
        <>
          {activeTab === 'dashboard' && (
            <>
              <WelcomeBanner name={user?.name} subtitle="Overview of your garage management system. All stats are live from the database." />
              <div className="row g-3">
                <StatCard icon="bi-people-fill" color="blue" value={users.length} label="Total Users" colClass="col-6 col-sm-6 col-lg-3" />
                <StatCard icon="bi-wrench" color="green" value={mechanics.length} label="Mechanics" colClass="col-6 col-sm-6 col-lg-3" />
                <StatCard icon="bi-truck" color="amber" value={suppliers.length} label="Suppliers" colClass="col-6 col-sm-6 col-lg-3" />
                <StatCard icon="bi-boxes" color="red" value={spareParts.length} label="Spare Parts" colClass="col-6 col-sm-6 col-lg-3" />
                <StatCard icon="bi-person-vcard-fill" color="purple" value={customers.length} label="Customers" colClass="col-6 col-sm-6 col-lg-3" />
                <StatCard icon="bi-truck-front-fill" color="blue" value={vehicles.length} label="Vehicles" colClass="col-6 col-sm-6 col-lg-3" />
                <StatCard icon="bi-scissors" color="green" value={jobs.length} label="Repair Jobs" colClass="col-6 col-sm-6 col-lg-3" />
                <StatCard icon="bi-receipt" color="amber" value={Number(totalInvoiceValue || 0).toLocaleString('en-US')} label={`${invoices.length} Invoices Total`} colClass="col-6 col-sm-6 col-lg-3" />
              </div>
            </>
          )}

          {activeTab === 'financial' && (
            <>
              <div className="row g-3 mb-3">
                <StatCard icon="bi-receipt-cutoff" color="blue" value={financialSummary.totalInvoices} label="Total Invoices" colClass="col-12 col-sm-6 col-lg-3" />
                <StatCard icon="bi-cash-coin" color="green" value={fmtMoney(financialSummary.totalPaid)} label="Total Amount Paid" colClass="col-12 col-sm-6 col-lg-3" />
                <StatCard icon="bi-wallet2" color="amber" value={fmtMoney(financialSummary.totalUnpaid)} label="Total Unpaid Amount" colClass="col-12 col-sm-6 col-lg-3" />
                <StatCard icon="bi-arrow-down-circle-fill" color="red" value={fmtMoney(financialSummary.outstandingDebts)} label="Outstanding Customer Debts" colClass="col-12 col-sm-6 col-lg-3" />
              </div>

              <div className="card-custom p-4">
                <div className="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                  <div>
                    <h6 className="mb-1" style={{ fontWeight: 700 }}><i className="bi bi-wallet2" style={{ color: 'var(--primary-blue)' }}></i> Financial Overview</h6>
                    <div className="text-muted small">Live revenue and debt status from the latest invoice totals.</div>
                  </div>
                  <span className={`badge bg-${financialSummary.debtStatusClass} rounded-pill`}>{financialSummary.debtStatus}</span>
                </div>

                <div className="row g-3">
                  <div className="col-md-6">
                    <div className="border rounded p-3 h-100">
                      <div className="text-muted small mb-1">Amounts Due</div>
                      <div className="fs-4 fw-bold">{fmtMoney(financialSummary.totalDue)}</div>
                    </div>
                  </div>
                  <div className="col-md-6">
                    <div className="border rounded p-3 h-100">
                      <div className="text-muted small mb-1">Amounts Already Paid</div>
                      <div className="fs-4 fw-bold text-success">{fmtMoney(financialSummary.totalPaid)}</div>
                    </div>
                  </div>
                </div>

                <div className="mt-4">
                  <div className="d-flex justify-content-between small text-muted mb-2">
                    <span>Payment progress</span>
                    <span>{financialSummary.paymentRate.toFixed(1)}%</span>
                  </div>
                  <div className="progress" style={{ height: 12 }}>
                    <div
                      className={`progress-bar bg-${financialSummary.debtStatusClass}`}
                      role="progressbar"
                      aria-valuenow={financialSummary.paymentRate}
                      aria-valuemin="0"
                      aria-valuemax="100"
                      style={{ width: `${Math.min(financialSummary.paymentRate, 100)}%` }}
                    />
                  </div>
                </div>

                <div className="mt-3 text-muted small">
                  {financialSummary.debtStatus === 'Healthy' && 'Collection is on track and most invoices are being settled on time.'}
                  {financialSummary.debtStatus === 'Watchlist' && 'Revenue is coming in, but a notable share of invoices still needs follow-up.'}
                  {financialSummary.debtStatus === 'Critical' && 'Immediate follow-up is needed because outstanding balances are rising.'}
                </div>
              </div>

              <div className="table-card mt-4">
                <div className="table-header">
                  <h6><i className="bi bi-receipt-cutoff" style={{ color: 'var(--primary-blue)' }}></i> Invoice Debt Summary</h6>
                </div>
                <div className="table-responsive">
                  <table className="table table-custom">
                    <thead>
                      <tr>
                        <th>Invoice</th>
                        <th>Customer</th>
                        <th>Amount Due</th>
                        <th>Paid</th>
                        <th>Balance</th>
                        <th>Status</th>
                      </tr>
                    </thead>
                    <tbody>
                      {invoices.length === 0 && (
                        <tr><td colSpan="6" className="text-center text-muted py-4">No invoices found.</td></tr>
                      )}
                      {invoices.map((invoice) => {
                        const balance = Number(invoice.TotalAmount || 0) - Number(invoice.TotalPaid || 0);
                        return (
                          <tr key={invoice.InvoiceID}>
                            <td>#{invoice.InvoiceID}</td>
                            <td>{invoice.CustomerName || invoice.CustomerID || '-'}</td>
                            <td>{fmtMoney(invoice.TotalAmount || 0)}</td>
                            <td>{fmtMoney(invoice.TotalPaid || 0)}</td>
                            <td>{fmtMoney(Math.max(balance, 0))}</td>
                            <td><StatusBadge status={invoice.PaymentStatus || 'Pending'} okValues={['Paid']} lowValues={['Pending', 'Partial']} /></td>
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                </div>
              </div>
            </>
          )}

          {activeTab === 'notifications' && (
            <div className="card-custom p-4">
              <div className="row g-3 mb-4">
                <StatCard icon="bi-bell-fill" color="blue" value={notifications.length} label="Total" colClass="col-4" />
                <StatCard icon="bi-envelope-exclamation-fill" color="red" value={notifications.filter((n) => !(n.IsRead || n.is_read)).length} label="Unread" colClass="col-4" />
                <StatCard icon="bi-envelope-open-fill" color="green" value={notifications.filter((n) => n.IsRead || n.is_read).length} label="Read" colClass="col-4" />
              </div>
              <div className="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
                <h6 style={{ fontWeight: 700 }}><i className="bi bi-bell-fill" style={{ color: 'var(--primary-blue)' }}></i> All Notifications</h6>
                <div className="d-flex gap-2">
                  <button className="btn-outline-blue btn-sm" onClick={markAllRead}><i className="bi bi-check-all"></i> Mark All Read</button>
                  <button className="btn-blue btn-sm" onClick={openAddNotification}><i className="bi bi-plus-lg"></i> Add</button>
                </div>
              </div>
              {notifications.length === 0 ? (
                <div className="text-center py-4 text-muted">No notifications yet.</div>
              ) : notifications.map((n) => (
                <div key={n.NotificationID} className={`list-group-item d-flex gap-3 align-items-center py-3 border-bottom ${n.IsRead ? 'opacity-75' : ''}`}>
                  <i className={`bi ${NOTIFICATION_ICONS[n.Type] || 'bi-info-circle-fill'}`} style={{ color: NOTIFICATION_COLORS[n.Type] || '#64748b', fontSize: '1.3rem' }}></i>
                  <div className="flex-grow-1">
                    <div style={{ fontWeight: 600, fontSize: '0.95rem' }}>{n.Message}</div>
                    <div style={{ fontSize: '0.8rem', color: 'var(--text-muted)' }}>{fmtDateTime(n.CreatedAt)}</div>
                  </div>
                  {!(n.IsRead || n.is_read) && <span className="badge bg-primary rounded-pill">New</span>}
                  <button className="btn-action view" title="View" onClick={() => { viewNotification.open(n); markOneRead(n); }}><i className="bi bi-eye"></i></button>
                  <button className="btn-icon" title="Edit" onClick={() => openEditNotification(n)}><i className="bi bi-pencil"></i></button>
                  <button className="btn-icon danger" title="Delete" onClick={() => deleteNotification(n)}><i className="bi bi-trash"></i></button>
                </div>
              ))}
            </div>
          )}
          <DetailsModal
            id="viewNotificationModal" title="Notification Details" icon="bi-bell-fill"
            fields={viewNotification.row && [
              { label: 'Type', value: viewNotification.row.Type },
              { label: 'Message', value: viewNotification.row.Message },
              { label: 'Sent', value: fmtDateTime(viewNotification.row.CreatedAt) },
              { label: 'Status', value: (viewNotification.row.IsRead || viewNotification.row.is_read) ? 'Read' : 'Unread' },
            ]}
          />

          {activeTab === 'messages' && (
            <div className="card-custom p-4">
              <div className="row g-3 mb-4">
                <StatCard icon="bi-envelope-fill" color="blue" value={messages.length} label="Total" colClass="col-4" />
                <StatCard icon="bi-envelope-exclamation-fill" color="red" value={messages.filter((m) => !(m.IsRead || m.is_read)).length} label="Unread" colClass="col-4" />
                <StatCard icon="bi-envelope-open-fill" color="green" value={messages.filter((m) => m.IsRead || m.is_read).length} label="Read" colClass="col-4" />
              </div>
              <div className="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
                <h6 style={{ fontWeight: 700 }}><i className="bi bi-envelope-fill" style={{ color: 'var(--primary-blue)' }}></i> Contact Messages</h6>
                <button className="btn-outline-blue btn-sm" onClick={markAllMessagesRead}><i className="bi bi-check-all"></i> Mark All Read</button>
              </div>
              {messages.length === 0 ? (
                <div className="text-center py-4 text-muted">No messages yet.</div>
              ) : messages.map((m) => (
                <div key={m.MessageID} className="list-group-item d-flex gap-3 align-items-start py-3 border-bottom">
                  <i className="bi bi-envelope-open-fill" style={{ color: 'var(--primary-blue)', fontSize: '1.2rem', marginTop: 2 }}></i>
                  <div className="flex-grow-1">
                    <div style={{ fontWeight: 700, fontSize: '0.95rem' }}>{m.Subject || 'Technical Support'}</div>
                    <div style={{ fontSize: '0.82rem', color: 'var(--text-muted)' }}>From: {m.FullName} ({m.Email})</div>
                    <div style={{ fontSize: '0.78rem', color: 'var(--text-light)' }}>{fmtDateTime(m.CreatedAt)}</div>
                    <div className="mt-1" style={{ fontSize: '0.9rem' }}>{m.Message}</div>
                  </div>
                  <div className="d-flex align-items-start gap-2">
                    {!(m.IsRead || m.is_read) && <span className="badge bg-primary rounded-pill">New</span>}
                    <button className="btn-action view" title="View" onClick={() => { viewMessage.open(m); markOneMessageRead(m); }}><i className="bi bi-eye"></i></button>
                    <button className="btn-icon danger" title="Delete" onClick={() => deleteMessage(m)}><i className="bi bi-trash"></i></button>
                  </div>
                </div>
              ))}
            </div>
          )}
          <DetailsModal
            id="viewMessageModal" title="Contact Message" icon="bi-envelope-fill"
            fields={viewMessage.row && [
              { label: 'From', value: viewMessage.row.FullName },
              { label: 'Email', value: viewMessage.row.Email },
              { label: 'Phone', value: viewMessage.row.Phone },
              { label: 'Subject', value: viewMessage.row.Subject || 'Technical Support' },
              { label: 'Message', value: viewMessage.row.Message },
              { label: 'Received', value: fmtDateTime(viewMessage.row.CreatedAt) },
              { label: 'Status', value: (viewMessage.row.IsRead || viewMessage.row.is_read) ? 'Read' : 'Unread' },
            ]}
          />

          {activeTab === 'users' && (
            <>
              <div className="row g-3 mb-1">
                <StatCard icon="bi-people-fill" color="blue" value={users.length} label="Total Users" colClass="col-6 col-md-4" />
                <StatCard icon="bi-shield-fill-check" color="green" value={new Set(users.map((u) => u.Role)).size} label="Roles" colClass="col-6 col-md-4" />
                <StatCard icon="bi-person-x-fill" color="amber" value={users.filter((u) => u.Status === 'Inactive').length} label="Inactive Accounts" colClass="col-6 col-md-4" />
              </div>
              <DataTable
                onRefresh={loadAll}
                title="User Management"
                icon="bi-people-fill"
                addLabel="Add User"
                onAdd={openAddUser}
                searchPlaceholder="Search users..."
                filters={[{ key: 'Status', label: 'Status', options: ['Active', 'Inactive'] }]}
                columns={[
                  { key: 'FullName', label: 'Full Name' },
                  { key: 'Username', label: 'Username' },
                  { key: 'Role', label: 'Role' },
                  { key: 'Email', label: 'Email' },
                  { key: 'Phone', label: 'Phone' },
                  { key: 'Status', label: 'Status', render: (r) => <StatusBadge status={r.Status} /> },
                ]}
                rows={users}
                renderActions={(r) => (
                  <>
                    <button className="btn-action view" title="View" onClick={() => viewUser.open(r)}><i className="bi bi-eye"></i></button>
                    <button className="btn-icon" title="Edit" onClick={() => openEditUser(r)}><i className="bi bi-pencil"></i></button>
                    <button className="btn-icon danger" title="Delete" onClick={() => deleteUser(r)}><i className="bi bi-trash"></i></button>
                  </>
                )}
              />
              <DetailsModal
                id="viewUserModal" title="User Details" icon="bi-people-fill"
                fields={viewUser.row && [
                  { label: 'Full Name', value: viewUser.row.FullName },
                  { label: 'Username', value: viewUser.row.Username },
                  { label: 'Role', value: viewUser.row.Role },
                  { label: 'Email', value: viewUser.row.Email },
                  { label: 'Phone', value: viewUser.row.Phone },
                  { label: 'Status', value: viewUser.row.Status },
                ]}
              />
            </>
          )}

          {activeTab === 'mechanics' && (
            <>
              <div className="row g-3 mb-1">
                <StatCard icon="bi-person-vcard-fill" color="blue" value={mechanics.length} label="Total Mechanics" colClass="col-6 col-md-4" />
                <StatCard icon="bi-check-circle-fill" color="green" value={mechanics.filter((m) => m.Status === 'Active').length} label="Active" colClass="col-6 col-md-4" />
                <StatCard icon="bi-clock-fill" color="amber" value={mechanics.reduce((a, m) => a + (Number(m.AssignedJobs) || 0), 0)} label="Assigned Jobs" colClass="col-6 col-md-4" />
              </div>
              <div className="alert-note mb-3" style={{ background: 'var(--primary-light)', color: 'var(--primary-dark)', borderRadius: 'var(--radius-sm)', padding: '0.75rem 1rem', fontSize: '0.85rem' }}>
                <i className="bi bi-info-circle-fill me-1"></i> To add a new mechanic, create a user with the <strong>Mechanic</strong> role in Manage Users.
              </div>
              <DataTable
                onRefresh={loadAll}
                title="Mechanics Directory"
                icon="bi-person-vcard-fill"
                searchPlaceholder="Search mechanics..."
                filters={[{ key: 'Status', label: 'Status', options: ['Active', 'Inactive'] }]}
                columns={[
                  { key: 'FullName', label: 'Name' },
                  { key: 'Specialization', label: 'Specialty', render: (r) => r.Specialization || '-' },
                  { key: 'Phone', label: 'Phone', render: (r) => r.Phone || '-' },
                  { key: 'Salary', label: 'Salary (RWF)', render: (r) => Number(r.Salary || 0).toLocaleString('en-US') },
                  { key: 'AssignedJobs', label: 'Assigned Jobs' },
                  { key: 'Status', label: 'Status', render: (r) => <StatusBadge status={r.Status} /> },
                ]}
                rows={mechanics}
                emptyText="No mechanics found. Mechanics are added by the Admin from Manage Users."
                renderActions={(r) => (
                  <>
                    <button className="btn-action view" title="View" onClick={() => viewMechanic.open(r)}><i className="bi bi-eye"></i></button>
                    <button className="btn-icon" title="Edit" onClick={() => openEditMechanic(r)}><i className="bi bi-pencil"></i></button>
                    <button className="btn-icon danger" title="Delete" onClick={() => deleteMechanic(r)}><i className="bi bi-trash"></i></button>
                  </>
                )}
              />
              <DetailsModal
                id="viewMechanicModal" title="Mechanic Details" icon="bi-person-vcard-fill"
                fields={viewMechanic.row && [
                  { label: 'Name', value: viewMechanic.row.FullName },
                  { label: 'Specialty', value: viewMechanic.row.Specialization },
                  { label: 'Phone', value: viewMechanic.row.Phone },
                  { label: 'Salary', value: `${Number(viewMechanic.row.Salary || 0).toLocaleString('en-US')} RWF` },
                  { label: 'Assigned Jobs', value: viewMechanic.row.AssignedJobs },
                  { label: 'Status', value: viewMechanic.row.Status },
                ]}
              />
            </>
          )}

          {activeTab === 'suppliers' && (
            <>
              <div className="row g-3 mb-1">
                <StatCard icon="bi-truck" color="blue" value={suppliers.length} label="Total Suppliers" colClass="col-6 col-md-4" />
                <StatCard icon="bi-cart-check-fill" color="green" value={suppliers.filter((s) => Number(s.PurchaseCount) > 0).length} label="With Purchase History" colClass="col-6 col-md-4" />
                <StatCard icon="bi-cart-fill" color="amber" value={suppliers.reduce((a, s) => a + (Number(s.PurchaseCount) || 0), 0)} label="Total Purchases" colClass="col-6 col-md-4" />
              </div>
              <DataTable
                onRefresh={loadAll}
                title="Supplier Directory"
                icon="bi-truck"
                addLabel="Add Supplier"
                onAdd={openAddSupplier}
                searchPlaceholder="Search suppliers..."
                columns={[
                  { key: 'CompanyName', label: 'Name' },
                  { key: 'Phone', label: 'Contact', render: (r) => r.Phone || '-' },
                  { key: 'Email', label: 'Email', render: (r) => r.Email || '-' },
                  { key: 'Address', label: 'Address', render: (r) => r.Address || '-' },
                ]}
                rows={suppliers}
                emptyText="No suppliers found."
                renderActions={(r) => (
                  <>
                    <button className="btn-action view" title="View" onClick={() => viewSupplier.open(r)}><i className="bi bi-eye"></i></button>
                    <button className="btn-icon" title="Edit" onClick={() => openEditSupplier(r)}><i className="bi bi-pencil"></i></button>
                    <button className="btn-icon danger" title="Delete" onClick={() => deleteSupplier(r)}><i className="bi bi-trash"></i></button>
                  </>
                )}
              />
              <DetailsModal
                id="viewSupplierModal" title="Supplier Details" icon="bi-truck"
                fields={viewSupplier.row && [
                  { label: 'Company Name', value: viewSupplier.row.CompanyName },
                  { label: 'Phone', value: viewSupplier.row.Phone },
                  { label: 'Email', value: viewSupplier.row.Email },
                  { label: 'Address', value: viewSupplier.row.Address },
                  { label: 'Total Purchases', value: viewSupplier.row.PurchaseCount },
                ]}
              />
            </>
          )}

          {activeTab === 'spareparts' && (
            <>
              <div className="row g-3 mb-1">
                <StatCard icon="bi-boxes" color="blue" value={spareParts.length} label="Total Parts" colClass="col-6 col-md-6" />
                <StatCard icon="bi-exclamation-triangle-fill" color="red" value={spareParts.filter((p) => Number(p.Quantity) <= Number(p.ReorderLevel)).length} label="Low Stock" colClass="col-6 col-md-6" />
              </div>
              <DataTable
                onRefresh={loadAll}
                title="Spare Parts Inventory"
                icon="bi-boxes"
                addLabel="Add Spare Part"
                onAdd={openAddPart}
                searchPlaceholder="Search spare parts..."
                columns={[
                  { key: 'PartName', label: 'Part Name' },
                  { key: 'CategoryName', label: 'Category', render: (r) => r.CategoryName || '-' },
                  { key: 'Quantity', label: 'Stock' },
                  { key: 'ReorderLevel', label: 'Min Level' },
                  { key: 'UnitPrice', label: 'Unit Price (RWF)', render: (r) => Number(r.UnitPrice || 0).toLocaleString('en-US') },
                  { key: 'SupplierName', label: 'Supplier', render: (r) => r.SupplierName || '-' },
                  {
                    key: 'Status', label: 'Status',
                    render: (r) => <StatusBadge status={Number(r.Quantity) <= Number(r.ReorderLevel) ? 'Low' : 'In Stock'} okValues={['In Stock']} lowValues={['Low']} />,
                  },
                ]}
                rows={spareParts}
                emptyText="No spare parts found."
                renderActions={(r) => (
                  <>
                    <button className="btn-action view" title="View" onClick={() => viewPart.open(r)}><i className="bi bi-eye"></i></button>
                    <button className="btn-icon" title="Edit" onClick={() => openEditPart(r)}><i className="bi bi-pencil"></i></button>
                    <button className="btn-icon danger" title="Delete" onClick={() => deletePart(r)}><i className="bi bi-trash"></i></button>
                  </>
                )}
              />
              <DetailsModal
                id="viewPartModal" title="Spare Part Details" icon="bi-boxes"
                fields={viewPart.row && [
                  { label: 'Part Name', value: viewPart.row.PartName },
                  { label: 'Category', value: viewPart.row.CategoryName },
                  { label: 'Supplier', value: viewPart.row.SupplierName },
                  { label: 'Unit Price', value: `${Number(viewPart.row.UnitPrice || 0).toLocaleString('en-US')} RWF` },
                  { label: 'Quantity In Stock', value: viewPart.row.Quantity },
                  { label: 'Reorder Level', value: viewPart.row.ReorderLevel },
                  { label: 'Stock Status', value: Number(viewPart.row.Quantity) <= Number(viewPart.row.ReorderLevel) ? 'Low Stock' : 'In Stock' },
                ]}
              />
              <Modal id="partModal" title={partForm.SparePartID ? 'Edit Spare Part' : 'Add Spare Part'} icon="bi-boxes">
                <form onSubmit={savePart}>
                  <div className="row g-3">
                    <div className="col-md-6">
                      <label className="form-label-custom">Part Name</label>
                      <input className="form-control form-control-custom" required value={partForm.PartName ?? ''} onChange={(e) => setPartForm((f) => ({ ...f, PartName: e.target.value }))} />
                    </div>
                    <div className="col-md-6">
                      <label className="form-label-custom">Category</label>
                      <select className="form-select form-control-custom" required value={partForm.CategoryID ?? ''} onChange={(e) => setPartForm((f) => ({ ...f, CategoryID: e.target.value }))}>
                        <option value="">Select category...</option>
                        {categories.map((c) => <option key={c.CategoryID} value={c.CategoryID}>{c.CategoryName}</option>)}
                      </select>
                    </div>
                    <div className="col-md-6">
                      <label className="form-label-custom">Supplier</label>
                      <select className="form-select form-control-custom" required value={partForm.SupplierID ?? ''} onChange={(e) => setPartForm((f) => ({ ...f, SupplierID: e.target.value }))}>
                        <option value="">Select supplier...</option>
                        {suppliers.map((s) => <option key={s.SupplierID} value={s.SupplierID}>{s.CompanyName}</option>)}
                      </select>
                    </div>
                    <div className="col-md-6">
                      <label className="form-label-custom">Unit Price (RWF)</label>
                      <input type="number" min="0" step="0.01" className="form-control form-control-custom" required value={partForm.UnitPrice ?? ''} onChange={(e) => setPartForm((f) => ({ ...f, UnitPrice: e.target.value }))} />
                    </div>
                    <div className="col-md-6">
                      <label className="form-label-custom">Quantity In Stock</label>
                      <input type="number" min="0" className="form-control form-control-custom" required value={partForm.Quantity ?? ''} onChange={(e) => setPartForm((f) => ({ ...f, Quantity: e.target.value }))} />
                    </div>
                    <div className="col-md-6">
                      <label className="form-label-custom">Reorder Level</label>
                      <input type="number" min="0" className="form-control form-control-custom" required value={partForm.ReorderLevel ?? ''} onChange={(e) => setPartForm((f) => ({ ...f, ReorderLevel: e.target.value }))} />
                    </div>
                  </div>
                  <button type="submit" className="btn-primary-full btn-save mt-3"><i className="bi bi-check-circle"></i> Save Spare Part</button>
                </form>
              </Modal>
            </>
          )}

          {activeTab === 'reports' && (
            <>
              <div className="d-flex gap-2 flex-wrap mb-3">
                {reportTabs.map((t) => (
                  <button
                    key={t.key}
                    className={reportTab === t.key ? 'btn-blue btn-sm' : 'btn-outline-blue btn-sm'}
                    onClick={() => setReportTab(t.key)}
                  >
                    <i className={`bi ${t.icon}`}></i> {t.label}
                  </button>
                ))}
              </div>
              {reportPanel()}
            </>
          )}

          <Modal id="profileModal" title="Profile Settings" icon="bi-gear">
            <form onSubmit={saveProfile}>
              <div className="row g-3">
                <div className="col-md-6">
                  <label className="form-label-custom">Full Name</label>
                  <input className="form-control form-control-custom" required value={profileForm.full_name ?? ''} onChange={(e) => setProfileForm((f) => ({ ...f, full_name: e.target.value }))} />
                </div>
                <div className="col-md-6">
                  <label className="form-label-custom">Username</label>
                  <input className="form-control form-control-custom" required value={profileForm.username ?? ''} onChange={(e) => setProfileForm((f) => ({ ...f, username: e.target.value }))} />
                </div>
                <div className="col-md-6">
                  <label className="form-label-custom">Email</label>
                  <input type="email" className="form-control form-control-custom" required value={profileForm.email ?? ''} onChange={(e) => setProfileForm((f) => ({ ...f, email: e.target.value }))} />
                </div>
                <div className="col-md-6">
                  <label className="form-label-custom">Phone</label>
                  <input
                    type="tel" className={`form-control form-control-custom${profileForm.phone && phoneError(profileForm.phone) ? ' is-invalid' : ''}`}
                    inputMode="numeric" maxLength={10} placeholder="07XXXXXXXX"
                    value={profileForm.phone ?? ''} onChange={(e) => setProfileForm((f) => ({ ...f, phone: digitsOnly(e.target.value) }))}
                  />
                  {profileForm.phone && phoneError(profileForm.phone) && <div className="invalid-feedback d-block">{phoneError(profileForm.phone)}</div>}
                </div>
                <div className="col-12"><hr /><p className="text-muted small mb-0">Change Password (optional)</p></div>
                <div className="col-md-6">
                  <label className="form-label-custom">Current Password</label>
                  <input type="password" className="form-control form-control-custom" placeholder="Required to change password or username" value={profileForm.current_password ?? ''} onChange={(e) => setProfileForm((f) => ({ ...f, current_password: e.target.value }))} />
                </div>
                <div className="col-md-6">
                  <label className="form-label-custom">New Password</label>
                  <input type="password" className="form-control form-control-custom" placeholder="Min 6 characters" value={profileForm.new_password ?? ''} onChange={(e) => setProfileForm((f) => ({ ...f, new_password: e.target.value }))} />
                </div>
                <div className="col-md-6">
                  <label className="form-label-custom">Confirm New Password</label>
                  <input type="password" className="form-control form-control-custom" value={profileForm.confirm_password ?? ''} onChange={(e) => setProfileForm((f) => ({ ...f, confirm_password: e.target.value }))} />
                </div>
              </div>
              <button type="submit" className="btn-primary-full btn-save mt-3"><i className="bi bi-check-circle"></i> Update Profile</button>
            </form>
          </Modal>
        </>
      )}

      {/* Add / Edit User Modal */}
      <Modal id="userModal" title={userForm.UserID ? 'Edit User' : 'Add User'} icon="bi-person-plus">
        <form onSubmit={saveUser}>
          <div className="row g-3">
            <div className="col-md-6">
              <label className="form-label-custom">Role</label>
              <select className="form-select form-control-custom" required value={userForm.Role ?? ''} onChange={(e) => setUserForm((f) => ({ ...f, Role: e.target.value }))}>
                <option value="" disabled>Select role...</option>
                {ROLE_OPTIONS.map((r) => <option key={r} value={r}>{r}</option>)}
              </select>
            </div>
            <div className="col-md-6">
              <label className="form-label-custom">Full Name</label>
              <input className="form-control form-control-custom" required value={userForm.FullName ?? ''} onChange={(e) => setUserForm((f) => ({ ...f, FullName: e.target.value }))} />
            </div>
            <div className="col-md-6">
              <label className="form-label-custom">Phone</label>
              <input
                className={`form-control form-control-custom${userForm.Phone && phoneError(userForm.Phone) ? ' is-invalid' : ''}`}
                inputMode="numeric" maxLength={10} placeholder="07XXXXXXXX"
                value={userForm.Phone ?? ''} onChange={(e) => setUserForm((f) => ({ ...f, Phone: digitsOnly(e.target.value) }))}
              />
              {userForm.Phone && phoneError(userForm.Phone) && <div className="invalid-feedback d-block">{phoneError(userForm.Phone)}</div>}
            </div>
            <div className="col-md-6">
              <label className="form-label-custom">Email</label>
              <input type="email" className="form-control form-control-custom" required value={userForm.Email ?? ''} onChange={(e) => setUserForm((f) => ({ ...f, Email: e.target.value }))} />
            </div>
            <div className="col-md-6">
              <label className="form-label-custom">Username</label>
              <input className="form-control form-control-custom" required value={userForm.Username ?? ''} onChange={(e) => setUserForm((f) => ({ ...f, Username: e.target.value }))} />
            </div>
            <div className="col-md-6">
              <label className="form-label-custom">{userForm.UserID ? 'New Password (optional)' : 'Password'}</label>
              <input type="password" className="form-control form-control-custom" required={!userForm.UserID} value={userForm.Password ?? ''} onChange={(e) => setUserForm((f) => ({ ...f, Password: e.target.value }))} />
            </div>
            <div className="col-12">
              <label className="form-label-custom">Confirm Password</label>
              <input type="password" className="form-control form-control-custom" required={!userForm.UserID || !!userForm.Password} value={userForm.ConfirmPassword ?? ''} onChange={(e) => setUserForm((f) => ({ ...f, ConfirmPassword: e.target.value }))} />
            </div>

            {userForm.Role === 'Mechanic' && (
              <>
                <div className="col-12 field-reveal"><div className="form-section-divider">Mechanic Details</div></div>
                <div className="col-md-6 field-reveal">
                  <label className="form-label-custom">Specialization</label>
                  <select className="form-select form-control-custom" value={userForm.MechanicSpecialization ?? ''} onChange={(e) => setUserForm((f) => ({ ...f, MechanicSpecialization: e.target.value }))}>
                    <option value="">Select specialization...</option>
                    {SPECIALIZATION_OPTIONS.map((s) => <option key={s} value={s}>{s}</option>)}
                  </select>
                </div>
                <div className="col-md-6 field-reveal">
                  <label className="form-label-custom">Salary</label>
                  <input type="number" min="0" className="form-control form-control-custom" placeholder="e.g., 450000" value={userForm.MechanicSalary ?? ''} onChange={(e) => setUserForm((f) => ({ ...f, MechanicSalary: e.target.value }))} />
                </div>
              </>
            )}

          </div>
          <button type="submit" className="btn-primary-full btn-save mt-3"><i className="bi bi-check-circle"></i> Save User</button>
        </form>
      </Modal>

      {/* Edit Mechanic Modal */}
      <Modal id="mechanicModal" title="Edit Mechanic" icon="bi-wrench">
        <form onSubmit={saveMechanic}>
          <div className="row g-3">
            <div className="col-md-6">
              <label className="form-label-custom">Full Name</label>
              <input className="form-control form-control-custom" required value={mechanicForm.FullName ?? ''} onChange={(e) => setMechanicForm((f) => ({ ...f, FullName: e.target.value }))} />
            </div>
            <div className="col-md-6">
              <label className="form-label-custom">Phone</label>
              <input
                className={`form-control form-control-custom${mechanicForm.Phone && phoneError(mechanicForm.Phone) ? ' is-invalid' : ''}`}
                inputMode="numeric" maxLength={10} placeholder="07XXXXXXXX"
                value={mechanicForm.Phone ?? ''} onChange={(e) => setMechanicForm((f) => ({ ...f, Phone: digitsOnly(e.target.value) }))}
              />
              {mechanicForm.Phone && phoneError(mechanicForm.Phone) && <div className="invalid-feedback d-block">{phoneError(mechanicForm.Phone)}</div>}
            </div>
            <div className="col-md-6">
              <label className="form-label-custom">Specialty</label>
              <input className="form-control form-control-custom" value={mechanicForm.Specialization ?? ''} onChange={(e) => setMechanicForm((f) => ({ ...f, Specialization: e.target.value }))} />
            </div>
            <div className="col-md-6">
              <label className="form-label-custom">Salary (RWF)</label>
              <input type="number" min="0" className="form-control form-control-custom" required value={mechanicForm.Salary ?? ''} onChange={(e) => setMechanicForm((f) => ({ ...f, Salary: e.target.value }))} />
            </div>
          </div>
          <button type="submit" className="btn-primary-full btn-save mt-3"><i className="bi bi-check-circle"></i> Save Mechanic</button>
        </form>
      </Modal>

      {/* Add / Edit Supplier Modal */}
      <Modal id="supplierModal" title={supplierForm.SupplierID ? 'Edit Supplier' : 'Add Supplier'} icon="bi-truck">
        <form onSubmit={saveSupplier}>
          <div className="row g-3">
            <div className="col-md-6">
              <label className="form-label-custom">Company Name</label>
              <input className="form-control form-control-custom" required value={supplierForm.CompanyName ?? ''} onChange={(e) => setSupplierForm((f) => ({ ...f, CompanyName: e.target.value }))} />
            </div>
            <div className="col-md-6">
              <label className="form-label-custom">Phone</label>
              <input
                className={`form-control form-control-custom${supplierForm.Phone && phoneError(supplierForm.Phone) ? ' is-invalid' : ''}`}
                inputMode="numeric" maxLength={10} placeholder="07XXXXXXXX"
                value={supplierForm.Phone ?? ''} onChange={(e) => setSupplierForm((f) => ({ ...f, Phone: digitsOnly(e.target.value) }))}
              />
              {supplierForm.Phone && phoneError(supplierForm.Phone) && <div className="invalid-feedback d-block">{phoneError(supplierForm.Phone)}</div>}
            </div>
            <div className="col-md-6">
              <label className="form-label-custom">Email</label>
              <input type="email" className="form-control form-control-custom" value={supplierForm.Email ?? ''} onChange={(e) => setSupplierForm((f) => ({ ...f, Email: e.target.value }))} />
            </div>
            <div className="col-md-6">
              <label className="form-label-custom">Address</label>
              <input className="form-control form-control-custom" value={supplierForm.Address ?? ''} onChange={(e) => setSupplierForm((f) => ({ ...f, Address: e.target.value }))} />
            </div>
          </div>
          <button type="submit" className="btn-primary-full btn-save mt-3"><i className="bi bi-check-circle"></i> Save Supplier</button>
        </form>
      </Modal>

      {/* Add / Edit Notification Modal */}
      <Modal id="notificationModal" title={notificationForm.NotificationID ? 'Edit Notification' : 'Add Notification'}>
        <form onSubmit={saveNotification}>
          <div className="row g-3">
            <div className="col-md-6">
              <label className="form-label-custom">User</label>
              <select className="form-select form-control-custom" value={notificationForm.UserID ?? ''} onChange={(e) => setNotificationForm((f) => ({ ...f, UserID: e.target.value }))}>
                <option value="">All Users</option>
                {users.map((u) => <option key={u.UserID} value={u.UserID}>{u.FullName}</option>)}
              </select>
            </div>
            <div className="col-md-6">
              <label className="form-label-custom">Type</label>
              <select className="form-select form-control-custom" value={notificationForm.Type ?? ''} onChange={(e) => setNotificationForm((f) => ({ ...f, Type: e.target.value }))}>
                <option value="system">System</option>
                <option value="job">Job</option>
                <option value="stock">Stock</option>
                <option value="payment">Payment</option>
              </select>
            </div>
            <div className="col-12">
              <label className="form-label-custom">Message</label>
              <textarea className="form-control form-control-custom" rows={4} required value={notificationForm.Message ?? ''} onChange={(e) => setNotificationForm((f) => ({ ...f, Message: e.target.value }))} />
            </div>
            <div className="col-12">
              <label className="form-label-custom">Link (optional)</label>
              <input className="form-control form-control-custom" placeholder="# or ?tab=..." value={notificationForm.Link || ''} onChange={(e) => setNotificationForm((f) => ({ ...f, Link: e.target.value }))} />
            </div>
          </div>
          <button type="submit" className="btn-primary-full btn-save mt-3"><i className="bi bi-check-circle"></i> Save Notification</button>
        </form>
      </Modal>
    </DashboardShell>
  );
}
