import React, { useCallback, useEffect, useMemo, useState } from 'react';
import '../../assets/staff.css';
import { DashboardShell, DataTable, Modal, DetailsModal, useViewModal, StatCard, WelcomeBanner, TruncatedText, StatusBadge, showBsModal, hideBsModal, ConfirmDelete } from '../../components';
import { phoneError, digitsOnly, todayStr } from '../../utils/validators';
import { useAuth, useToast } from '../../context';
import { customersApi, jobsApi, billingApi, notificationsApi, authApi } from '../../api';
import { JOB_ALL_STATUSES, JOB_WORKFLOW_STATUSES, jobStatusLabel, normalizeJobStatus } from '../../utils/jobStatus';

const NOTIFICATION_ICONS = { job: 'bi-plus-circle-fill', stock: 'bi-box-seam-fill', payment: 'bi-cash-coin', system: 'bi-info-circle-fill' };
const NOTIFICATION_COLORS = { job: '#2563eb', stock: '#d97706', payment: '#16a34a', system: '#64748b' };

function fmtDateTime(v) {
  if (!v) return '-';
  const d = new Date(v);
  if (Number.isNaN(d.getTime())) return v;
  return d.toLocaleString('en-US', { month: 'short', day: '2-digit', hour: 'numeric', minute: '2-digit' });
}

const NAV_SECTIONS = [
  {
    title: 'Overview',
    items: [
      { key: 'dashboard', label: 'Dashboard', icon: 'bi-speedometer2' },
      { key: 'financial', label: 'Financial Overview', icon: 'bi-wallet2' },
    ],
  },
  {
    title: 'Front Desk',
    items: [
      { key: 'customers', label: 'Customers', icon: 'bi-people-fill' },
      { key: 'vehicles', label: 'Vehicles', icon: 'bi-car-front-fill' },
      { key: 'jobs', label: 'Repair Jobs', icon: 'bi-wrench-adjustable' },
    ],
  },
  {
    title: 'Billing',
    items: [
      { key: 'invoices', label: 'Invoices', icon: 'bi-receipt-cutoff' },
      { key: 'payments', label: 'Payments', icon: 'bi-cash-coin' },
    ],
  },
  { title: 'Account', items: [{ key: 'notifications', label: 'Notifications', icon: 'bi-bell-fill' }] },
];

const emptyCustomer = {
  CustomerID: null,
  FullName: '',
  Phone: '',
  Email: '',
  Address: '',
  vehicle: { PlateNumber: '', Manufacturer: '', Model: '', Year: '', ChassisNumber: '', EngineNumber: '', FuelType: 'Petrol', Transmission: 'Manual' },
};
const emptyVehicle = { VehicleID: null, CustomerID: '', PlateNumber: '', Manufacturer: '', Model: '', Year: '', ChassisNumber: '', EngineNumber: '', FuelType: 'Petrol', Transmission: 'Manual' };
const emptyJob = { JobID: null, VehicleID: '', MechanicID: '', Description: '', Status: 'Pending' };
const emptyInvoice = {
  InvoiceID: null, CustomerID: '', VehicleID: '', JobID: '', InvoiceDate: '',
  LabourCharges: 0, SparePartsCost: 0, TaxRate: 18, TaxAmount: 0, DiscountRate: 0, DiscountAmount: 0, TotalAmount: 0,
};
const emptyPayment = { PaymentID: null, InvoiceID: '', Amount: '', PaymentMethod: 'Cash', PaymentDate: '' };

export default function Receptionist() {
  const { user } = useAuth();
  const { showToast } = useToast();
  const [activeTab, setActiveTab] = useState('dashboard');

  const [customers, setCustomers] = useState([]);
  const [vehicles, setVehicles] = useState([]);
  const [jobs, setJobs] = useState([]);
  const [invoices, setInvoices] = useState([]);
  const [payments, setPayments] = useState([]);
  const [mechanics, setMechanics] = useState([]);
  const [notifications, setNotifications] = useState([]);
  const [loading, setLoading] = useState(true);
  const [updatingJobId, setUpdatingJobId] = useState(null);

  const [customerForm, setCustomerForm] = useState(emptyCustomer);
  const [vehicleForm, setVehicleForm] = useState(emptyVehicle);
  const [jobForm, setJobForm] = useState(emptyJob);
  const [invoiceForm, setInvoiceForm] = useState(emptyInvoice);
  const [paymentForm, setPaymentForm] = useState(emptyPayment);
  const [profileForm, setProfileForm] = useState({ full_name: user?.name || '', username: user?.username || '', email: user?.email || '', phone: user?.phone || '', current_password: '', new_password: '', confirm_password: '' });

  const loadAll = useCallback(async () => {
    setLoading(true);
    const [c, v, j, inv, pay, mech, notif] = await Promise.all([
      customersApi.listCustomers(),
      customersApi.listVehicles(),
      jobsApi.listJobs(),
      billingApi.listInvoices(),
      billingApi.listPayments(),
      jobsApi.listMechanics(),
      notificationsApi.listAll(),
    ]);
    if (c.success) setCustomers(c.data || []);
    if (v.success) setVehicles(v.data || []);
    if (j.success) setJobs(j.data || []);
    if (inv.success) setInvoices(inv.data || []);
    if (pay.success) setPayments(pay.data || []);
    if (mech.success) setMechanics(mech.data || []);
    if (notif.success) setNotifications(notif.data || []);
    setLoading(false);
  }, []);

  useEffect(() => { loadAll(); }, [loadAll]);

  const unreadCount = useMemo(() => notifications.filter((n) => !n.IsRead && !n.is_read).length, [notifications]);
  const customerName = (id) => customers.find((c) => c.CustomerID === id)?.FullName || id;
  const vehiclePlate = (id) => vehicles.find((v) => v.VehicleID === id)?.PlateNumber || id;
  const mechanicName = (id) => mechanics.find((m) => m.MechanicID === id)?.FullName || '—';
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

  const withCrud = (label, api, form, setForm, empty, modalId, reload) => ({
    openAdd: () => { setForm(empty); showBsModal(modalId); },
    openEdit: (row) => { setForm({ ...empty, ...row }); showBsModal(modalId); },
    save: async (e) => {
      e.preventDefault();
      const res = await api.save(form);
      if (res.success) { showToast(`${label} saved.`, 'success'); hideBsModal(modalId); reload(); }
      else showToast(res.message || `Could not save ${label.toLowerCase()}.`, 'danger');
    },
    remove: async (row, idKey) => {
      const displayName = row.FullName || row.PlateNumber || row.CompanyName || row.PartName || row.Subject || row[idKey];
      if (!(await ConfirmDelete(label.toLowerCase(), displayName))) return;
      const res = await api.remove(row[idKey]);
      showToast(res.success ? `${label} deleted.` : res.message || `Could not delete ${label.toLowerCase()}.`, res.success ? 'success' : 'danger');
      if (res.success) reload();
    },
  });

  const recalcInvoiceTotals = (next) => {
    const labour = parseFloat(next.LabourCharges) || 0;
    const parts = parseFloat(next.SparePartsCost) || 0;
    const taxRate = parseFloat(next.TaxRate) || 0;
    const discountRate = parseFloat(next.DiscountRate) || 0;
    const taxAmount = Math.round((labour + parts) * (taxRate / 100) * 100) / 100;
    const discountAmount = Math.round((labour + parts + taxAmount) * (discountRate / 100) * 100) / 100;
    const total = Math.round((labour + parts + taxAmount - discountAmount) * 100) / 100;
    return { ...next, TaxAmount: taxAmount, DiscountAmount: discountAmount, TotalAmount: total };
  };
  const updateInvoiceField = (field, value) => setInvoiceForm((f) => recalcInvoiceTotals({ ...f, [field]: value }));
  const openAddInvoice = () => { setInvoiceForm(emptyInvoice); showBsModal('invoiceModal'); };
  const openEditInvoice = (row) => {
    setInvoiceForm(recalcInvoiceTotals({
      ...emptyInvoice, ...row,
      TaxRate: row.TaxRate ?? 18, DiscountRate: row.DiscountRate ?? 0,
      TaxAmount: row.Taxes ?? 0, DiscountAmount: row.Discounts ?? 0,
    }));
    showBsModal('invoiceModal');
  };

  const viewCustomer = useViewModal('viewCustomerModal');
  const viewVehicle = useViewModal('viewVehicleModal');
  const viewJob = useViewModal('viewJobModal');
  const viewInvoice = useViewModal('viewInvoiceModal');
  const viewPayment = useViewModal('viewPaymentModal');
  const viewNotification = useViewModal('viewNotificationModal');

  const customerCrud = withCrud('Customer', { save: customersApi.saveCustomer, remove: customersApi.removeCustomer }, customerForm, setCustomerForm, emptyCustomer, 'customerModal', loadAll);
  const vehicleCrud = withCrud('Vehicle', { save: customersApi.saveVehicle, remove: customersApi.removeVehicle }, vehicleForm, setVehicleForm, emptyVehicle, 'vehicleModal', loadAll);
  const jobCrud = withCrud('Repair job', { save: jobsApi.saveJob, remove: jobsApi.removeJob }, jobForm, setJobForm, emptyJob, 'jobModal', loadAll);
  jobCrud.save = async (e) => {
    e.preventDefault();
    const payload = { ...jobForm };
    if (payload.JobID) delete payload.Status;
    const res = await jobsApi.saveJob(payload);
    if (res.success) { showToast('Repair job saved.', 'success'); hideBsModal('jobModal'); loadAll(); }
    else showToast(res.message || 'Could not save repair job.', 'danger');
  };

  const changeJobStatus = async (job, action) => {
    if (updatingJobId === job.JobID) return;
    setUpdatingJobId(job.JobID);
    const res = action === 'next' ? await jobsApi.nextJob(job.JobID) : await jobsApi.cancelJob(job.JobID);
    if (res.success) {
      if (res.data) setJobs((current) => current.map((item) => item.JobID === job.JobID ? { ...item, ...res.data } : item));
      showToast(res.message || 'Repair job status updated.', 'success');
      loadAll();
    } else showToast(res.message || 'Could not update repair job status.', 'danger');
    setUpdatingJobId(null);
  };
  const invoiceCrud = withCrud('Invoice', { save: billingApi.saveInvoice, remove: billingApi.removeInvoice }, invoiceForm, setInvoiceForm, emptyInvoice, 'invoiceModal', loadAll);
  const paymentCrud = withCrud('Payment', { save: billingApi.savePayment, remove: billingApi.removePayment }, paymentForm, setPaymentForm, emptyPayment, 'paymentModal', loadAll);

  const saveProfile = async (e) => {
    e.preventDefault();
    if (phoneError(profileForm.phone)) { showToast(phoneError(profileForm.phone), 'danger'); return; }
    const res = await authApi.updateProfile(profileForm);
    showToast(res.success ? 'Profile updated.' : res.message || 'Could not update profile.', res.success ? 'success' : 'danger');
  };

  const saveCustomerWithVehicle = async (e) => {
    e.preventDefault();
    if (phoneError(customerForm.Phone)) { showToast(phoneError(customerForm.Phone), 'danger'); return; }

    const payload = { ...customerForm };
    const vehiclePayload = payload.vehicle;
    const vehicleIdentityFields = ['PlateNumber', 'Manufacturer', 'Model', 'Year', 'ChassisNumber', 'EngineNumber', 'Mileage'];
    const hasVehicleData = !!vehiclePayload && vehicleIdentityFields.some((field) => {
      const value = vehiclePayload[field];
      return value !== '' && value !== null && value !== undefined;
    });

    if (hasVehicleData) payload.vehicle = vehiclePayload;
    else delete payload.vehicle;

    const res = await customersApi.saveCustomer(payload);
    if (res.success) {
      showToast('Customer saved.', 'success');
      hideBsModal('customerModal');
      loadAll();
      setCustomerForm(emptyCustomer);
    } else {
      showToast(res.message || 'Could not save customer.', 'danger');
    }
  };

  // ---- Derived, real-data stats for the per-page summary cards ----
  const NOT_ACTIVE_JOB_STATUSES = ['Delivered', 'Ready', 'Cancelled'];
  const openJobVehicleIds = useMemo(
    () => new Set(jobs.filter((j) => !NOT_ACTIVE_JOB_STATUSES.includes(j.Status)).map((j) => j.VehicleID)),
    [jobs]
  );
  const activeCustomerCount = useMemo(
    () => new Set(vehicles.filter((v) => openJobVehicleIds.has(v.VehicleID)).map((v) => v.CustomerID)).size,
    [vehicles, openJobVehicleIds]
  );
  const isThisMonth = (dateStr) => {
    if (!dateStr) return false;
    const d = new Date(dateStr);
    const now = new Date();
    return !Number.isNaN(d.getTime()) && d.getMonth() === now.getMonth() && d.getFullYear() === now.getFullYear();
  };

  const pageTitles = {
    dashboard: 'Dashboard', financial: 'Financial Overview', customers: 'Customers', vehicles: 'Vehicles', jobs: 'Repair Jobs',
    invoices: 'Invoices', payments: 'Payments', notifications: 'Notifications',
  };

  const markOneRead = async (n) => {
    if (n.IsRead || n.is_read) return;
    setNotifications((prev) => prev.map((x) => (x.NotificationID === n.NotificationID ? { ...x, IsRead: true } : x)));
    await notificationsApi.markRead(n.NotificationID);
  };
  // Opening the Notifications tab is "viewing" them - automatically mark
  // whatever is currently unread as read/seen.
  useEffect(() => {
    if (activeTab !== 'notifications') return;
    setNotifications((prev) => {
      if (!prev.some((n) => !n.IsRead && !n.is_read)) return prev;
      notificationsApi.markAllRead();
      return prev.map((n) => ({ ...n, IsRead: true }));
    });
  }, [activeTab]);

  return (
    <DashboardShell
      brandSub="Reception Desk"
      navSections={NAV_SECTIONS.map((s) => ({ ...s, items: s.items.map((it) => (it.key === 'notifications' ? { ...it, badge: unreadCount } : it)) }))}
      activeTab={activeTab}
      onTabChange={setActiveTab}
      pageTitle={pageTitles[activeTab]}
      userName={user?.name}
      userRole="Receptionist"
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
              <WelcomeBanner name={user?.name} subtitle="Here's what's happening at the front desk today." />
              <div className="row g-3">
                <StatCard icon="bi-people-fill" color="blue" value={customers.length} label="Customers" />
                <StatCard icon="bi-car-front-fill" color="green" value={vehicles.length} label="Vehicles" />
                <StatCard icon="bi-wrench-adjustable" color="orange" value={jobs.filter((j) => !NOT_ACTIVE_JOB_STATUSES.includes(normalizeJobStatus(j.Status))).length} label="Active Jobs" />
                <StatCard icon="bi-receipt-cutoff" color="purple" value={Number(totalInvoiceValue || 0).toLocaleString('en-US')} label={`${invoices.length} Invoices Total`} />
              </div>
            </>
          )}

          {activeTab === 'financial' && (
            <>
              <div className="row g-3 mb-3">
                <StatCard icon="bi-receipt-cutoff" color="blue" value={financialSummary.totalInvoices} label="Total Invoices" colClass="col-12 col-sm-6 col-lg-3" />
                <StatCard icon="bi-cash-coin" color="green" value={new Intl.NumberFormat('en-US', { style: 'currency', currency: 'RWF', maximumFractionDigits: 0 }).format(financialSummary.totalPaid)} label="Total Amount Paid" colClass="col-12 col-sm-6 col-lg-3" />
                <StatCard icon="bi-wallet2" color="amber" value={new Intl.NumberFormat('en-US', { style: 'currency', currency: 'RWF', maximumFractionDigits: 0 }).format(financialSummary.totalUnpaid)} label="Total Unpaid Amount" colClass="col-12 col-sm-6 col-lg-3" />
                <StatCard icon="bi-arrow-down-circle-fill" color="red" value={new Intl.NumberFormat('en-US', { style: 'currency', currency: 'RWF', maximumFractionDigits: 0 }).format(financialSummary.outstandingDebts)} label="Outstanding Customer Debts" colClass="col-12 col-sm-6 col-lg-3" />
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
                      <div className="fs-4 fw-bold">{new Intl.NumberFormat('en-US', { style: 'currency', currency: 'RWF', maximumFractionDigits: 0 }).format(financialSummary.totalDue)}</div>
                    </div>
                  </div>
                  <div className="col-md-6">
                    <div className="border rounded p-3 h-100">
                      <div className="text-muted small mb-1">Amounts Already Paid</div>
                      <div className="fs-4 fw-bold text-success">{new Intl.NumberFormat('en-US', { style: 'currency', currency: 'RWF', maximumFractionDigits: 0 }).format(financialSummary.totalPaid)}</div>
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
                            <td>{new Intl.NumberFormat('en-US', { style: 'currency', currency: 'RWF', maximumFractionDigits: 0 }).format(invoice.TotalAmount || 0)}</td>
                            <td>{new Intl.NumberFormat('en-US', { style: 'currency', currency: 'RWF', maximumFractionDigits: 0 }).format(invoice.TotalPaid || 0)}</td>
                            <td>{new Intl.NumberFormat('en-US', { style: 'currency', currency: 'RWF', maximumFractionDigits: 0 }).format(Math.max(balance, 0))}</td>
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

          {activeTab === 'customers' && (
            <>
              <div className="row g-3 mb-3">
                <StatCard icon="bi-people-fill" color="blue" value={customers.length} label="Total Customers" />
                <StatCard icon="bi-person-check-fill" color="green" value={activeCustomerCount} label="Active Customers" />
                <StatCard icon="bi-person-plus-fill" color="purple" value={customers.filter((c) => isThisMonth(c.RegistrationDate)).length} label="New This Month" />
              </div>
              <DataTable
                onRefresh={loadAll}
                title="Customers" icon="bi-people-fill" addLabel="Add Customer" onAdd={customerCrud.openAdd} searchPlaceholder="Search customers..."
                columns={[{ key: 'FullName', label: 'Full Name' }, { key: 'Phone', label: 'Phone' }, { key: 'Email', label: 'Email' }, { key: 'Address', label: 'Address' }]}
                rows={customers}
                renderActions={(r) => (
                  <>
                    <button className="btn-action view" title="View" onClick={() => viewCustomer.open(r)}><i className="bi bi-eye"></i></button>
                    <button className="btn-icon" title="Edit" onClick={() => customerCrud.openEdit(r)}><i className="bi bi-pencil"></i></button>
                    <button className="btn-icon danger" title="Delete" onClick={() => customerCrud.remove(r, 'CustomerID')}><i className="bi bi-trash"></i></button>
                  </>
                )}
              />
              <DetailsModal
                id="viewCustomerModal" title="Customer Details" icon="bi-person-lines-fill"
                fields={viewCustomer.row && [
                  { label: 'Full Name', value: viewCustomer.row.FullName },
                  { label: 'Phone', value: viewCustomer.row.Phone },
                  { label: 'Email', value: viewCustomer.row.Email },
                  { label: 'Address', value: viewCustomer.row.Address },
                  { label: 'Registered', value: viewCustomer.row.RegistrationDate },
                  { label: 'Vehicles Owned', value: viewCustomer.row.VehicleCount },
                ]}
              />
              <Modal id="customerModal" title={customerForm.CustomerID ? 'Edit Customer' : 'Add Customer'} icon="bi-person-plus">
                <form onSubmit={saveCustomerWithVehicle}>
                  <div className="row g-3">
                    <div className="col-md-6"><label className="form-label-custom">Full Name</label><input className="form-control form-control-custom" required value={customerForm.FullName ?? ''} onChange={(e) => setCustomerForm((f) => ({ ...f, FullName: e.target.value }))} /></div>
                    <div className="col-md-6">
                      <label className="form-label-custom">Phone</label>
                      <input
                        className={`form-control form-control-custom${customerForm.Phone && phoneError(customerForm.Phone) ? ' is-invalid' : ''}`}
                        required inputMode="numeric" maxLength={10} placeholder="07XXXXXXXX"
                        value={customerForm.Phone ?? ''} onChange={(e) => setCustomerForm((f) => ({ ...f, Phone: digitsOnly(e.target.value) }))}
                      />
                      {customerForm.Phone && phoneError(customerForm.Phone) && <div className="invalid-feedback d-block">{phoneError(customerForm.Phone)}</div>}
                    </div>
                    <div className="col-md-6"><label className="form-label-custom">Email</label><input type="email" className="form-control form-control-custom" value={customerForm.Email ?? ''} onChange={(e) => setCustomerForm((f) => ({ ...f, Email: e.target.value }))} /></div>
                    <div className="col-md-6"><label className="form-label-custom">Address</label><input className="form-control form-control-custom" value={customerForm.Address ?? ''} onChange={(e) => setCustomerForm((f) => ({ ...f, Address: e.target.value }))} /></div>
                  </div>

                  {!customerForm.CustomerID && (
                    <div className="border rounded p-3 mt-4">
                      <div className="d-flex align-items-center justify-content-between mb-3">
                        <h6 className="mb-0">Vehicle Information</h6>
                        <small className="text-muted">Optional but recommended</small>
                      </div>
                      <div className="row g-3">
                        <div className="col-md-6"><label className="form-label-custom">Plate Number</label><input className="form-control form-control-custom" value={customerForm.vehicle?.PlateNumber ?? ''} onChange={(e) => setCustomerForm((f) => ({ ...f, vehicle: { ...(f.vehicle || {}), PlateNumber: e.target.value } }))} /></div>
                        <div className="col-md-6"><label className="form-label-custom">Make</label><input className="form-control form-control-custom" value={customerForm.vehicle?.Manufacturer ?? ''} onChange={(e) => setCustomerForm((f) => ({ ...f, vehicle: { ...(f.vehicle || {}), Manufacturer: e.target.value } }))} /></div>
                        <div className="col-md-6"><label className="form-label-custom">Model</label><input className="form-control form-control-custom" value={customerForm.vehicle?.Model ?? ''} onChange={(e) => setCustomerForm((f) => ({ ...f, vehicle: { ...(f.vehicle || {}), Model: e.target.value } }))} /></div>
                        <div className="col-md-6"><label className="form-label-custom">Year</label><input className="form-control form-control-custom" value={customerForm.vehicle?.Year ?? ''} onChange={(e) => setCustomerForm((f) => ({ ...f, vehicle: { ...(f.vehicle || {}), Year: e.target.value } }))} /></div>
                        <div className="col-md-6"><label className="form-label-custom">Chassis Number</label><input className="form-control form-control-custom" value={customerForm.vehicle?.ChassisNumber ?? ''} onChange={(e) => setCustomerForm((f) => ({ ...f, vehicle: { ...(f.vehicle || {}), ChassisNumber: e.target.value } }))} /></div>
                        <div className="col-md-6"><label className="form-label-custom">Engine Number</label><input className="form-control form-control-custom" value={customerForm.vehicle?.EngineNumber ?? ''} onChange={(e) => setCustomerForm((f) => ({ ...f, vehicle: { ...(f.vehicle || {}), EngineNumber: e.target.value } }))} /></div>
                        <div className="col-md-6">
                          <label className="form-label-custom">Fuel Type</label>
                          <select className="form-select form-control-custom" value={customerForm.vehicle?.FuelType ?? 'Petrol'} onChange={(e) => setCustomerForm((f) => ({ ...f, vehicle: { ...(f.vehicle || {}), FuelType: e.target.value } }))}>
                            <option>Petrol</option><option>Diesel</option><option>Electric</option><option>Hybrid</option>
                          </select>
                        </div>
                        <div className="col-md-6">
                          <label className="form-label-custom">Transmission</label>
                          <select className="form-select form-control-custom" value={customerForm.vehicle?.Transmission ?? 'Manual'} onChange={(e) => setCustomerForm((f) => ({ ...f, vehicle: { ...(f.vehicle || {}), Transmission: e.target.value } }))}>
                            <option>Manual</option><option>Automatic</option>
                          </select>
                        </div>
                      </div>
                    </div>
                  )}

                  <button type="submit" className="btn-primary-full btn-save mt-3"><i className="bi bi-check-circle"></i> Save Customer</button>
                </form>
              </Modal>
            </>
          )}

          {activeTab === 'vehicles' && (
            <>
              <div className="row g-3 mb-3">
                <StatCard icon="bi-car-front-fill" color="blue" value={vehicles.length} label="Total Vehicles" />
                <StatCard icon="bi-tools" color="orange" value={openJobVehicleIds.size} label="Currently In Service" />
                <StatCard icon="bi-check-circle-fill" color="green" value={vehicles.length - openJobVehicleIds.size} label="Not In Service" />
              </div>
              <DataTable
                onRefresh={loadAll}
                title="Vehicles" icon="bi-car-front-fill" addLabel="Add Vehicle" onAdd={vehicleCrud.openAdd} searchPlaceholder="Search vehicles..."
                columns={[
                  { key: 'PlateNumber', label: 'Plate Number' }, { key: 'Manufacturer', label: 'Make' }, { key: 'Model', label: 'Model' }, { key: 'Year', label: 'Year' },
                  { key: 'CustomerID', label: 'Owner', render: (r) => customerName(r.CustomerID) },
                ]}
                rows={vehicles}
                renderActions={(r) => (
                  <>
                    <button className="btn-action view" title="View" onClick={() => viewVehicle.open(r)}><i className="bi bi-eye"></i></button>
                    <button className="btn-icon" title="Edit" onClick={() => vehicleCrud.openEdit(r)}><i className="bi bi-pencil"></i></button>
                    <button className="btn-icon danger" title="Delete" onClick={() => vehicleCrud.remove(r, 'VehicleID')}><i className="bi bi-trash"></i></button>
                  </>
                )}
              />
              <DetailsModal
                id="viewVehicleModal" title="Vehicle Details" icon="bi-car-front-fill"
                fields={viewVehicle.row && [
                  { label: 'Plate Number', value: viewVehicle.row.PlateNumber },
                  { label: 'Owner', value: customerName(viewVehicle.row.CustomerID) },
                  { label: 'Make', value: viewVehicle.row.Manufacturer },
                  { label: 'Model', value: viewVehicle.row.Model },
                  { label: 'Year', value: viewVehicle.row.Year },
                  { label: 'Chassis Number', value: viewVehicle.row.ChassisNumber },
                  { label: 'Engine Number', value: viewVehicle.row.EngineNumber },
                  { label: 'Fuel Type', value: viewVehicle.row.FuelType },
                  { label: 'Transmission', value: viewVehicle.row.Transmission },
                  { label: 'Mileage', value: viewVehicle.row.Mileage },
                ]}
              />
              <Modal id="vehicleModal" title={vehicleForm.VehicleID ? 'Edit Vehicle' : 'Add Vehicle'} icon="bi-car-front">
                <form onSubmit={vehicleCrud.save}>
                  <div className="row g-3">
                    <div className="col-md-6">
                      <label className="form-label-custom">Owner (Customer)</label>
                      <select className="form-select form-control-custom" required value={vehicleForm.CustomerID ?? ''} onChange={(e) => setVehicleForm((f) => ({ ...f, CustomerID: e.target.value }))}>
                        <option value="">Select customer...</option>
                        {customers.map((c) => <option key={c.CustomerID} value={c.CustomerID}>{c.FullName}</option>)}
                      </select>
                    </div>
                    <div className="col-md-6"><label className="form-label-custom">Plate Number</label><input className="form-control form-control-custom" required value={vehicleForm.PlateNumber ?? ''} onChange={(e) => setVehicleForm((f) => ({ ...f, PlateNumber: e.target.value }))} /></div>
                    <div className="col-md-6"><label className="form-label-custom">Make</label><input className="form-control form-control-custom" required value={vehicleForm.Manufacturer ?? ''} onChange={(e) => setVehicleForm((f) => ({ ...f, Manufacturer: e.target.value }))} /></div>
                    <div className="col-md-6"><label className="form-label-custom">Model</label><input className="form-control form-control-custom" required value={vehicleForm.Model ?? ''} onChange={(e) => setVehicleForm((f) => ({ ...f, Model: e.target.value }))} /></div>
                    <div className="col-md-6"><label className="form-label-custom">Year</label><input className="form-control form-control-custom" value={vehicleForm.Year ?? ''} onChange={(e) => setVehicleForm((f) => ({ ...f, Year: e.target.value }))} /></div>
                    <div className="col-md-6"><label className="form-label-custom">Chassis Number</label><input className="form-control form-control-custom" value={vehicleForm.ChassisNumber ?? ''} onChange={(e) => setVehicleForm((f) => ({ ...f, ChassisNumber: e.target.value }))} /></div>
                    <div className="col-md-6"><label className="form-label-custom">Engine Number</label><input className="form-control form-control-custom" value={vehicleForm.EngineNumber ?? ''} onChange={(e) => setVehicleForm((f) => ({ ...f, EngineNumber: e.target.value }))} /></div>
                    <div className="col-md-6">
                      <label className="form-label-custom">Fuel Type</label>
                      <select className="form-select form-control-custom" value={vehicleForm.FuelType ?? ''} onChange={(e) => setVehicleForm((f) => ({ ...f, FuelType: e.target.value }))}>
                        <option>Petrol</option><option>Diesel</option><option>Electric</option><option>Hybrid</option>
                      </select>
                    </div>
                    <div className="col-md-6">
                      <label className="form-label-custom">Transmission</label>
                      <select className="form-select form-control-custom" value={vehicleForm.Transmission ?? ''} onChange={(e) => setVehicleForm((f) => ({ ...f, Transmission: e.target.value }))}>
                        <option>Manual</option><option>Automatic</option>
                      </select>
                    </div>
                  </div>
                  <button type="submit" className="btn-primary-full btn-save mt-3"><i className="bi bi-check-circle"></i> Save Vehicle</button>
                </form>
              </Modal>
            </>
          )}

          {activeTab === 'jobs' && (
            <>
              <div className="row g-3 mb-3">
                <StatCard icon="bi-wrench-adjustable" color="blue" value={jobs.length} label="Total Jobs" colClass="col-6 col-sm-6 col-lg-3" />
                <StatCard icon="bi-lightning-charge-fill" color="orange" value={jobs.filter((j) => JOB_WORKFLOW_STATUSES.slice(1, 4).includes(normalizeJobStatus(j.Status))).length} label="Active Jobs" colClass="col-6 col-sm-6 col-lg-3" />
                <StatCard icon="bi-check-circle-fill" color="green" value={jobs.filter((j) => ['Ready', 'Delivered'].includes(normalizeJobStatus(j.Status))).length} label="Completed Jobs" colClass="col-6 col-sm-6 col-lg-3" />
                <StatCard icon="bi-hourglass-split" color="red" value={jobs.filter((j) => j.Status === 'Pending').length} label="Pending Jobs" colClass="col-6 col-sm-6 col-lg-3" />
              </div>
              <DataTable
                onRefresh={loadAll}
                title="Repair Jobs" icon="bi-wrench-adjustable" addLabel="New Job" onAdd={jobCrud.openAdd} searchPlaceholder="Search repair jobs..."
                columns={[
                  { key: 'VehicleID', label: 'Vehicle', render: (r) => vehiclePlate(r.VehicleID) },
                  { key: 'MechanicID', label: 'Mechanic', render: (r) => mechanicName(r.MechanicID) },
                  { key: 'Description', label: 'Description', render: (r) => <TruncatedText text={r.Description} limit={36} /> },
                  { key: 'Status', label: 'Status', render: (r) => <StatusBadge status={jobStatusLabel(r.Status)} /> },
                  { key: 'UpdateStatus', label: 'Update Status', render: (r) => {
                    const status = normalizeJobStatus(r.Status);
                    const busy = updatingJobId === r.JobID;
                    return (
                      <div className="job-status-actions">
                        <button
                          type="button"
                          className="job-status-btn job-next-btn"
                          onClick={() => changeJobStatus(r, 'next')}
                          disabled={status === 'Cancelled' || status === 'Delivered' || busy}
                          title={status === 'Cancelled' ? 'Cancelled jobs cannot continue' : 'Advance to the next workflow status'}
                        >
                          <i className={`bi ${busy ? 'bi-arrow-repeat spin' : 'bi-arrow-right'}`}></i>
                          {busy ? 'Updating...' : 'Next'}
                        </button>
                        <button
                          type="button"
                          className="job-status-btn job-cancel-btn"
                          onClick={() => changeJobStatus(r, 'cancel')}
                          disabled={status === 'Cancelled' || busy}
                          title="Mark this repair job as cancelled"
                        >
                          <i className="bi bi-x-circle"></i> Cancelled
                        </button>
                      </div>
                    );
                  } },
                ]}
                rows={jobs}
                renderActions={(r) => (
                  <>
                    <button className="btn-action view" title="View" onClick={() => viewJob.open(r)}><i className="bi bi-eye"></i></button>
                    <button className="btn-icon" title="Edit" onClick={() => jobCrud.openEdit(r)}><i className="bi bi-pencil"></i></button>
                    <button className="btn-icon danger" title="Delete" onClick={() => jobCrud.remove(r, 'JobID')}><i className="bi bi-trash"></i></button>
                  </>
                )}
              />
              <DetailsModal
                id="viewJobModal" title="Repair Job Details" icon="bi-wrench-adjustable"
                fields={viewJob.row && [
                  { label: 'Job #', value: viewJob.row.JobID },
                  { label: 'Vehicle', value: vehiclePlate(viewJob.row.VehicleID) },
                  { label: 'Customer', value: viewJob.row.CustomerName || customerName(viewJob.row.CustomerID) },
                  { label: 'Mechanic', value: mechanicName(viewJob.row.MechanicID) },
                  { label: 'Start Date', value: viewJob.row.StartDate },
                  { label: 'End Date', value: viewJob.row.EndDate },
                  { label: 'Description', value: viewJob.row.Description },
                  { label: 'Status', value: jobStatusLabel(viewJob.row.Status) },
                ]}
                actions={viewJob.row && <>
                  {normalizeJobStatus(viewJob.row.Status) !== 'Cancelled' && normalizeJobStatus(viewJob.row.Status) !== 'Delivered' && <button type="button" className="btn-blue btn-sm" onClick={() => changeJobStatus(viewJob.row, 'next')}><i className="bi bi-arrow-right"></i> Next</button>}
                  {normalizeJobStatus(viewJob.row.Status) !== 'Cancelled' && <button type="button" className="btn-icon danger" title="Cancel job" onClick={() => changeJobStatus(viewJob.row, 'cancel')}><i className="bi bi-x-circle"></i></button>}
                </>}
              />
              <Modal id="jobModal" title={jobForm.JobID ? 'Edit Repair Job' : 'New Repair Job'} icon="bi-wrench-adjustable">
                <form onSubmit={jobCrud.save}>
                  <div className="row g-3">
                    <div className="col-md-6">
                      <label className="form-label-custom">Vehicle</label>
                      <select className="form-select form-control-custom" required value={jobForm.VehicleID ?? ''} onChange={(e) => setJobForm((f) => ({ ...f, VehicleID: e.target.value }))}>
                        <option value="">Select vehicle...</option>
                        {vehicles.map((v) => <option key={v.VehicleID} value={v.VehicleID}>{v.PlateNumber} — {v.Manufacturer} {v.Model}</option>)}
                      </select>
                    </div>
                    <div className="col-md-6">
                      <label className="form-label-custom">Assign Mechanic</label>
                      <select className="form-select form-control-custom" value={jobForm.MechanicID ?? ''} onChange={(e) => setJobForm((f) => ({ ...f, MechanicID: e.target.value }))}>
                        <option value="">Unassigned</option>
                        {mechanics.map((m) => <option key={m.MechanicID} value={m.MechanicID}>{m.FullName}</option>)}
                      </select>
                    </div>
                    <div className="col-12"><label className="form-label-custom">Description</label><textarea className="form-control form-control-custom" rows={3} required value={jobForm.Description ?? ''} onChange={(e) => setJobForm((f) => ({ ...f, Description: e.target.value }))}></textarea></div>
                    {!jobForm.JobID && (
                      <div className="col-md-6">
                        <label className="form-label-custom">Status</label>
                        <select className="form-select form-control-custom" value={jobForm.Status ?? ''} onChange={(e) => setJobForm((f) => ({ ...f, Status: e.target.value }))}>
                          {JOB_ALL_STATUSES.map((status) => <option key={status} value={status}>{jobStatusLabel(status)}</option>)}
                        </select>
                      </div>
                    )}
                  </div>
                  <div className="d-flex flex-wrap gap-2 mt-3">
                    <button type="submit" className="btn-primary-full btn-save flex-grow-1"><i className="bi bi-check-circle"></i> Save Job</button>
                  </div>
                </form>
              </Modal>
            </>
          )}

          {activeTab === 'invoices' && (
            <>
              <div className="row g-3 mb-3">
                <StatCard icon="bi-receipt-cutoff" color="blue" value={invoices.length} label="Total Invoices" colClass="col-6 col-sm-6 col-lg-3" />
                <StatCard icon="bi-check-circle-fill" color="green" value={invoices.filter((i) => i.PaymentStatus === 'Paid').length} label="Paid" colClass="col-6 col-sm-6 col-lg-3" />
                <StatCard icon="bi-hourglass-split" color="orange" value={invoices.filter((i) => i.PaymentStatus === 'Partial').length} label="Partially Paid" colClass="col-6 col-sm-6 col-lg-3" />
                <StatCard icon="bi-exclamation-circle-fill" color="red" value={invoices.filter((i) => i.PaymentStatus === 'Pending').length} label="Pending" colClass="col-6 col-sm-6 col-lg-3" />
              </div>
              <DataTable
                onRefresh={loadAll}
                title="Invoices" icon="bi-receipt-cutoff" addLabel="New Invoice" onAdd={openAddInvoice} searchPlaceholder="Search invoices..."
                columns={[
                  { key: 'CustomerName', label: 'Customer' }, { key: 'LabourCharges', label: 'Labour (RWF)' }, { key: 'SparePartsCost', label: 'Spare Parts (RWF)' },
                  { key: 'Taxes', label: 'Taxes (RWF)' }, { key: 'Discounts', label: 'Discounts (RWF)' }, { key: 'TotalAmount', label: 'Total (RWF)' },
                  { key: 'PaymentStatus', label: 'Payment Status', render: (r) => <StatusBadge status={r.PaymentStatus} /> },
                ]}
                rows={invoices}
                renderActions={(r) => (
                  <>
                    <button className="btn-action view" title="View / Print" onClick={() => viewInvoice.open(r)}><i className="bi bi-printer"></i></button>
                    <button className="btn-icon" title="Edit" onClick={() => openEditInvoice(r)}><i className="bi bi-pencil"></i></button>
                    <button className="btn-icon danger" title="Delete" onClick={() => invoiceCrud.remove(r, 'InvoiceID')}><i className="bi bi-trash"></i></button>
                  </>
                )}
              />
              <DetailsModal
                id="viewInvoiceModal" title="Invoice Details" icon="bi-receipt-cutoff" printable
                fields={viewInvoice.row && [
                  { label: 'Invoice #', value: viewInvoice.row.InvoiceID },
                  { label: 'Customer', value: viewInvoice.row.CustomerName || customerName(viewInvoice.row.CustomerID) },
                  { label: 'Phone', value: viewInvoice.row.CustomerPhone },
                  { label: 'Vehicle', value: viewInvoice.row.PlateNumber },
                  { label: 'Invoice Date', value: viewInvoice.row.InvoiceDate },
                  { label: 'Labour Charges', value: `${Number(viewInvoice.row.LabourCharges || 0).toLocaleString('en-US')} RWF` },
                  { label: 'Spare Parts Cost', value: `${Number(viewInvoice.row.SparePartsCost || 0).toLocaleString('en-US')} RWF` },
                  { label: 'Taxes', value: `${Number(viewInvoice.row.Taxes || 0).toLocaleString('en-US')} RWF` },
                  { label: 'Discounts', value: `${Number(viewInvoice.row.Discounts || 0).toLocaleString('en-US')} RWF` },
                  { label: 'Total Amount', value: `${Number(viewInvoice.row.TotalAmount || 0).toLocaleString('en-US')} RWF` },
                  { label: 'Total Paid', value: `${Number(viewInvoice.row.TotalPaid || 0).toLocaleString('en-US')} RWF` },
                  { label: 'Payment Status', value: viewInvoice.row.PaymentStatus },
                ]}
              />
              <Modal id="invoiceModal" title={invoiceForm.InvoiceID ? 'Edit Invoice' : 'Add Invoice'} icon="bi-receipt-cutoff">
                <form onSubmit={invoiceCrud.save}>
                  <div className="row g-3">
                    <div className="col-md-6">
                      <label className="form-label-custom">Customer</label>
                      <select className="form-select form-control-custom" required value={invoiceForm.CustomerID ?? ''} onChange={(e) => setInvoiceForm((f) => ({ ...f, CustomerID: e.target.value }))}>
                        <option value="">Select customer...</option>
                        {customers.map((c) => <option key={c.CustomerID} value={c.CustomerID}>{c.FullName}</option>)}
                      </select>
                    </div>
                    <div className="col-md-6">
                      <label className="form-label-custom">Job</label>
                      <select className="form-select form-control-custom" value={invoiceForm.JobID ?? ''} onChange={(e) => setInvoiceForm((f) => ({ ...f, JobID: e.target.value }))}>
                        <option value="">Optional</option>
                        {jobs.map((j) => <option key={j.JobID} value={j.JobID}>#{j.JobID} — {customerName(j.CustomerID)}</option>)}
                      </select>
                    </div>
                    <div className="col-md-6">
                      <label className="form-label-custom">Vehicle</label>
                      <select className="form-select form-control-custom" value={invoiceForm.VehicleID ?? ''} onChange={(e) => setInvoiceForm((f) => ({ ...f, VehicleID: e.target.value }))}>
                        <option value="">Select vehicle (optional)</option>
                        {vehicles.map((v) => <option key={v.VehicleID} value={v.VehicleID}>{v.PlateNumber} — {v.Model}</option>)}
                      </select>
                    </div>
                    <div className="col-md-6">
                      <label className="form-label-custom">Invoice Date</label>
                      <input type="date" className="form-control form-control-custom" required max={todayStr()} value={invoiceForm.InvoiceDate ?? ''} onChange={(e) => setInvoiceForm((f) => ({ ...f, InvoiceDate: e.target.value }))} />
                    </div>
                    <div className="col-md-6">
                      <label className="form-label-custom">Labour Charges (RWF)</label>
                      <input type="number" min="0" step="0.01" className="form-control form-control-custom" value={invoiceForm.LabourCharges ?? ''} onChange={(e) => updateInvoiceField('LabourCharges', e.target.value)} />
                    </div>
                    <div className="col-md-6">
                      <label className="form-label-custom">Spare Parts Cost (RWF)</label>
                      <input type="number" min="0" step="0.01" className="form-control form-control-custom" value={invoiceForm.SparePartsCost ?? ''} onChange={(e) => updateInvoiceField('SparePartsCost', e.target.value)} />
                    </div>
                    <div className="col-md-3">
                      <label className="form-label-custom">Tax Rate (%)</label>
                      <input type="number" min="0" max="100" step="0.1" className="form-control form-control-custom" value={invoiceForm.TaxRate ?? ''} onChange={(e) => updateInvoiceField('TaxRate', e.target.value)} />
                    </div>
                    <div className="col-md-3">
                      <label className="form-label-custom">Tax Amount (RWF)</label>
                      <input type="number" min="0" step="0.01" className="form-control form-control-custom" value={invoiceForm.TaxAmount ?? ''} onChange={(e) => setInvoiceForm((f) => ({ ...f, TaxAmount: e.target.value }))} />
                    </div>
                    <div className="col-md-3">
                      <label className="form-label-custom">Discount Rate (%)</label>
                      <input type="number" min="0" max="100" step="0.1" className="form-control form-control-custom" value={invoiceForm.DiscountRate ?? ''} onChange={(e) => updateInvoiceField('DiscountRate', e.target.value)} />
                    </div>
                    <div className="col-md-3">
                      <label className="form-label-custom">Discount Amount (RWF)</label>
                      <input type="number" min="0" step="0.01" className="form-control form-control-custom" value={invoiceForm.DiscountAmount ?? ''} onChange={(e) => setInvoiceForm((f) => ({ ...f, DiscountAmount: e.target.value }))} />
                    </div>
                    <div className="col-md-6">
                      <label className="form-label-custom">Total Amount (auto-calc)</label>
                      <input type="number" step="0.01" className="form-control form-control-custom" readOnly value={invoiceForm.TotalAmount ?? ''} />
                    </div>
                  </div>
                  <button type="submit" className="btn-primary-full btn-save mt-3"><i className="bi bi-check-circle"></i> Save Invoice</button>
                </form>
              </Modal>
            </>
          )}

          {activeTab === 'payments' && (
            <>
              <div className="row g-3 mb-3">
                <StatCard icon="bi-cash-coin" color="blue" value={payments.length} label="Total Payments" />
                <StatCard icon="bi-graph-up-arrow" color="green" value={`${payments.reduce((sum, p) => sum + Number(p.Amount || 0), 0).toLocaleString('en-US')} RWF`} label="Total Collected" />
                <StatCard icon="bi-calendar-check" color="purple" value={payments.filter((p) => isThisMonth(p.PaymentDate)).length} label="Payments This Month" />
              </div>
              <DataTable
                onRefresh={loadAll}
                title="Payments" icon="bi-cash-coin" addLabel="Record Payment" onAdd={paymentCrud.openAdd} searchPlaceholder="Search payments..."
                filters={[{ key: 'PaymentMethod', label: 'Method', options: Array.from(new Set(payments.map((payment) => payment.PaymentMethod).filter(Boolean))) }]}
                columns={[
                  { key: 'InvoiceID', label: 'Invoice #' }, { key: 'Amount', label: 'Amount Paid' },
                  { key: 'PaymentMethod', label: 'Method' }, { key: 'PaymentDate', label: 'Date' },
                ]}
                rows={payments}
                renderActions={(r) => (
                  <>
                    <button className="btn-action view" title="View" onClick={() => viewPayment.open(r)}><i className="bi bi-eye"></i></button>
                    <button className="btn-icon" title="Edit" onClick={() => paymentCrud.openEdit(r)}><i className="bi bi-pencil"></i></button>
                    <button className="btn-icon danger" title="Delete" onClick={() => paymentCrud.remove(r, 'PaymentID')}><i className="bi bi-trash"></i></button>
                  </>
                )}
              />
              <DetailsModal
                id="viewPaymentModal" title="Payment Details" icon="bi-cash-coin"
                fields={viewPayment.row && [
                  { label: 'Payment #', value: viewPayment.row.PaymentID },
                  { label: 'Invoice #', value: viewPayment.row.InvoiceID },
                  { label: 'Amount Paid', value: `${Number(viewPayment.row.Amount || 0).toLocaleString('en-US')} RWF` },
                  { label: 'Method', value: viewPayment.row.PaymentMethod },
                  { label: 'Status', value: viewPayment.row.PaymentStatus },
                  { label: 'Date', value: viewPayment.row.PaymentDate },
                ]}
              />
              <Modal id="paymentModal" title={paymentForm.PaymentID ? 'Edit Payment' : 'Record Payment'} icon="bi-cash-coin">
                <form onSubmit={paymentCrud.save}>
                  <div className="row g-3">
                    <div className="col-md-6">
                      <label className="form-label-custom">Invoice</label>
                      <select className="form-select form-control-custom" required value={paymentForm.InvoiceID ?? ''} onChange={(e) => setPaymentForm((f) => ({ ...f, InvoiceID: e.target.value }))}>
                        <option value="">Select invoice...</option>
                        {invoices.map((i) => <option key={i.InvoiceID} value={i.InvoiceID}>#{i.InvoiceID} — {i.TotalAmount} RWF</option>)}
                      </select>
                    </div>
                    <div className="col-md-6">
                      <label className="form-label-custom">Payment Method</label>
                      <select className="form-select form-control-custom" value={paymentForm.PaymentMethod ?? ''} onChange={(e) => setPaymentForm((f) => ({ ...f, PaymentMethod: e.target.value }))}>
                        <option>Cash</option><option>Mobile Money</option><option>Bank Transfer</option><option>Card</option>
                      </select>
                    </div>
                    <div className="col-md-6"><label className="form-label-custom">Amount Paid (RWF)</label><input type="number" className="form-control form-control-custom" required value={paymentForm.Amount ?? ''} onChange={(e) => setPaymentForm((f) => ({ ...f, Amount: e.target.value }))} /></div>
                    <div className="col-md-6"><label className="form-label-custom">Payment Date</label><input type="date" className="form-control form-control-custom" required max={todayStr()} value={paymentForm.PaymentDate ?? ''} onChange={(e) => setPaymentForm((f) => ({ ...f, PaymentDate: e.target.value }))} /></div>
                  </div>
                  <button type="submit" className="btn-primary-full btn-save mt-3"><i className="bi bi-check-circle"></i> Save Payment</button>
                </form>
              </Modal>
            </>
          )}

          {activeTab === 'notifications' && (
            <div className="card-custom p-4">
              <div className="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
                <h6 style={{ fontWeight: 700 }}><i className="bi bi-bell-fill" style={{ color: 'var(--primary-blue)' }}></i> All Notifications</h6>
                <button className="btn-outline-blue btn-sm" onClick={async () => { const r = await notificationsApi.markAllRead(); if (r.success) loadAll(); }}><i className="bi bi-check-all"></i> Mark All Read</button>
              </div>
              {notifications.length === 0 ? (
                <div className="text-center py-4 text-muted">No notifications yet.</div>
              ) : notifications.map((n) => (
                <div key={n.NotificationID} className={`list-group-item d-flex gap-3 align-items-center py-3 border-bottom ${(n.IsRead || n.is_read) ? 'opacity-75' : ''}`}>
                  <i className={`bi ${NOTIFICATION_ICONS[n.Type] || 'bi-info-circle-fill'}`} style={{ color: NOTIFICATION_COLORS[n.Type] || '#64748b', fontSize: '1.3rem' }}></i>
                  <div className="flex-grow-1">
                    <div style={{ fontWeight: 600, fontSize: '0.95rem' }}>{n.Message}</div>
                    <div style={{ fontSize: '0.8rem', color: 'var(--text-muted)' }}>{fmtDateTime(n.CreatedAt)}</div>
                  </div>
                  {!(n.IsRead || n.is_read) && <span className="badge bg-primary rounded-pill">New</span>}
                  <button className="btn-action view" title="View" onClick={() => { viewNotification.open(n); markOneRead(n); }}><i className="bi bi-eye"></i></button>
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
    </DashboardShell>
  );
}
