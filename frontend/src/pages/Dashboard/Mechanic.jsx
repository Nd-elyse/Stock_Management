import React, { useCallback, useEffect, useMemo, useState } from 'react';
import '../../assets/staff.css';
import { DashboardShell, DataTable, Modal, DetailsModal, useViewModal, StatCard, WelcomeBanner, StatusBadge, showBsModal, hideBsModal, ConfirmDelete } from '../../components';
import { phoneError, digitsOnly, todayStr } from '../../utils/validators';
import { useAuth, useToast } from '../../context';
import { jobsApi, customersApi, inventoryApi, notificationsApi, authApi } from '../../api';
import { JOB_WORKFLOW_STATUSES, normalizeJobStatus } from '../../utils/jobStatus';

// Single flat "Main" section, matching Mechanic.php's sidebar exactly - no
// sub-grouping, and no Settings link (Settings lives only in the profile
// dropdown, opening a modal, same as every PHP dashboard).
const NAV_SECTIONS = [
  {
    title: 'Main',
    items: [
      { key: 'dashboard', label: 'Dashboard', icon: 'bi-grid-1x2-fill' },
      { key: 'assigned', label: 'My Jobs', icon: 'bi-clipboard-check-fill' },
      { key: 'parts', label: 'Request Parts', icon: 'bi-boxes' },
      { key: 'history', label: 'Job History', icon: 'bi-clock-history' },
      { key: 'notifications', label: 'Notifications', icon: 'bi-bell-fill' },
    ],
  },
];

// Same Status -> badge-class mapping as job_status_badge_class() in the
// original Mechanic.php, so colors match exactly.
function jobStatusBadgeClass(status) {
  switch (status) {
    case 'Delivered':
    case 'Ready':
      return 'badge-delivered';
    case 'Pending':
      return 'badge-pending';
    case 'InProgress':
    case 'Diagnosed':
      return 'badge-inprogress';
    case 'AwaitingParts':
      return 'badge-awaiting';
    case 'Cancelled':
      return 'badge-danger';
    default:
      return 'badge-ok';
  }
}
const JobStatusBadge = ({ status }) => <span className={`badge-status ${jobStatusBadgeClass(status)}`}>{status || 'Pending'}</span>;

const ACTIVE_STATUSES = JOB_WORKFLOW_STATUSES.slice(0, -2);

const emptyRequest = { RequestID: null, SparePartID: '', JobID: '', QuantityRequested: 1, Reason: '' };

export default function Mechanic() {
  const { user } = useAuth();
  const { showToast } = useToast();
  const [activeTab, setActiveTab] = useState('dashboard');
  const [refreshing, setRefreshing] = useState(false);
  const [updatingJobId, setUpdatingJobId] = useState(null);
  const handleRefresh = async () => { if (refreshing) return; setRefreshing(true); try { await loadAll(); } finally { setRefreshing(false); } };
  const viewNotification = useViewModal('viewNotificationModal');
  const viewJob = useViewModal('viewJobModal');

  const [jobs, setJobs] = useState([]);
  const [jobHistoryRecords, setJobHistoryRecords] = useState([]);
  const [vehicles, setVehicles] = useState([]);
  const [spareParts, setSpareParts] = useState([]);
  const [requests, setRequests] = useState([]);
  const [notifications, setNotifications] = useState([]);
  const [loading, setLoading] = useState(true);

  const [notesJobId, setNotesJobId] = useState(null);
  const [notesPlate, setNotesPlate] = useState('');
  const [notesText, setNotesText] = useState('');
  const [requestForm, setRequestForm] = useState(emptyRequest);
  const [profileForm, setProfileForm] = useState({
    full_name: user?.name || '', username: user?.username || '', email: user?.email || '', phone: user?.phone || '',
    current_password: '', new_password: '', confirm_password: '',
  });

  const loadAll = useCallback(async () => {
    setLoading(true);
    const [j, h, v, sp, req, notif] = await Promise.all([
      jobsApi.listJobs(),
      jobsApi.listJobHistory(),
      customersApi.listVehicles(),
      inventoryApi.listSpareParts(),
      inventoryApi.listSparePartRequests(),
      notificationsApi.listAll(),
    ]);
    if (j.success) setJobs(j.data || []);
    if (h.success) setJobHistoryRecords(h.data || []);
    if (v.success) setVehicles(v.data || []);
    if (sp.success) setSpareParts(sp.data || []);
    if (req.success) setRequests(req.data || []);
    if (notif.success) setNotifications(notif.data || []);
    setLoading(false);
  }, []);

  useEffect(() => { loadAll(); }, [loadAll]);

  const unreadCount = useMemo(() => notifications.filter((n) => !n.IsRead).length, [notifications]);
  const vehiclePlate = (id) => vehicles.find((v) => v.VehicleID === id)?.PlateNumber || '-';
  const selectedPart = spareParts.find((part) => String(part.SparePartID) === String(requestForm.SparePartID));
  const requestedQuantity = Number(requestForm.QuantityRequested || 0);
  const selectedUnitCost = Number(selectedPart?.UnitPrice || 0);
  const requestedTotalCost = selectedUnitCost * requestedQuantity;

  const myJobs = jobs; // /jobs is already scoped to the signed-in mechanic on the backend
  const activeJobs = myJobs.filter((j) => ACTIVE_STATUSES.includes(normalizeJobStatus(j.Status)));
  const awaitingPartsJobs = myJobs.filter((j) => normalizeJobStatus(j.Status) === 'AwaitingParts');
  const completedJobs = myJobs.filter((j) => ['Delivered', 'Ready'].includes(normalizeJobStatus(j.Status)));
  const jobHistory = jobHistoryRecords;
  const todayStr = new Date().toISOString().slice(0, 10);
  const partsRequestedToday = requests.filter((r) => (r.RequestedAt || '').slice(0, 10) === todayStr).length;

  const openNotes = (job) => { setNotesJobId(job.JobID); setNotesPlate(vehiclePlate(job.VehicleID)); setNotesText(''); showBsModal('diagnosticsModal'); };
  const saveNotes = async (e) => {
    e.preventDefault();
    if (!notesText || notesText.trim().length < 5) {
      showToast('Please provide detailed notes (minimum 5 characters).', 'danger');
      return;
    }
    const res = await jobsApi.saveDiagnostics(notesJobId, { notes: notesText.trim() });
    if (res.success) {
      showToast('Diagnostics saved successfully.', 'success');
      hideBsModal('diagnosticsModal');
      loadAll();
    } else {
      showToast(res.message || 'Failed to save diagnostics.', 'danger');
    }
  };

  const advanceJob = async (job) => {
    if (updatingJobId === job.JobID) return;
    setUpdatingJobId(job.JobID);
    const res = await jobsApi.nextJob(job.JobID);
    if (res.success) {
      if (res.data) setJobs((current) => current.map((item) => item.JobID === job.JobID ? { ...item, ...res.data } : item));
      showToast(res.message || 'Job advanced.', 'success');
      loadAll();
    } else showToast(res.message || 'Failed to advance job.', 'danger');
    setUpdatingJobId(null);
  };
  const cancelJob = async (job) => {
    if (updatingJobId === job.JobID) return;
    setUpdatingJobId(job.JobID);
    const res = await jobsApi.cancelJob(job.JobID);
    if (res.success) {
      if (res.data) setJobs((current) => current.map((item) => item.JobID === job.JobID ? { ...item, ...res.data } : item));
      showToast(res.message || 'Job cancelled.', 'success');
      loadAll();
    } else showToast(res.message || 'Failed to cancel job.', 'danger');
    setUpdatingJobId(null);
  };

  const deleteHistoryRecord = async (record) => {
    if (!(await ConfirmDelete('history record', `Job #${record.JobID} status update`))) return;
    const res = await jobsApi.removeJobHistory(record.HistoryID);
    if (res.success) { showToast('History record deleted.', 'success'); loadAll(); }
    else showToast(res.message || 'Failed to delete history record.', 'danger');
  };

  const saveRequest = async (e) => {
    e.preventDefault();
    if (!requestForm.SparePartID) { showToast('Please select a spare part.', 'danger'); return; }
    if (!requestForm.QuantityRequested || Number(requestForm.QuantityRequested) < 1) { showToast('Quantity must be at least 1.', 'danger'); return; }
    if (selectedPart && requestedQuantity > Number(selectedPart.Quantity || 0)) { showToast('Requested quantity exceeds available stock.', 'danger'); return; }
    if (!requestForm.Reason || requestForm.Reason.trim().length < 5) { showToast('Please provide a reason for the request.', 'danger'); return; }
    const res = await inventoryApi.saveSparePartRequest(requestForm);
    if (res.success) { showToast(res.message || 'Request submitted successfully.', 'success'); hideBsModal('requestModal'); loadAll(); }
    else showToast(res.message || 'Could not submit request.', 'danger');
  };
  const cancelRequest = async (r) => {
    if (!(await ConfirmDelete('request'))) return;
    const res = await inventoryApi.removeSparePartRequest(r.RequestID);
    if (res.success) { showToast('Request cancelled successfully.', 'success'); loadAll(); }
  };

  const saveProfile = async (e) => {
    e.preventDefault();
    if (profileForm.new_password && profileForm.new_password !== profileForm.confirm_password) {
      showToast('New passwords do not match.', 'danger');
      return;
    }
    if (phoneError(profileForm.phone)) { showToast(phoneError(profileForm.phone), 'danger'); return; }
    const res = await authApi.updateProfile(profileForm);
    showToast(res.success ? (res.message || 'Profile updated successfully.') : (res.message || 'Could not update profile.'), res.success ? 'success' : 'danger');
    if (res.success) hideBsModal('profileModal');
  };

  const markAllRead = async () => { const r = await notificationsApi.markAllRead(); if (r.success) loadAll(); };
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

  const pageTitles = { dashboard: 'Dashboard', assigned: 'My Jobs', parts: 'Request Spare Parts', history: 'Job History', notifications: 'Notifications' };

  return (
    <DashboardShell
      brandSub="Mechanic Panel"
      navSections={NAV_SECTIONS.map((s) => ({ ...s, items: s.items.map((it) => (it.key === 'notifications' ? { ...it, badge: unreadCount } : it)) }))}
      activeTab={activeTab}
      onTabChange={setActiveTab}
      pageTitle={pageTitles[activeTab]}
      userName={user?.name}
      userRole="Mechanic"
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
              <WelcomeBanner name={user?.name} subtitle="Here's what's on your bench today." />
              <div className="row g-3">
                <StatCard icon="bi-clipboard-check-fill" color="blue" value={activeJobs.length} label="Active Jobs" />
                <StatCard icon="bi-hourglass-split" color="amber" value={awaitingPartsJobs.length} label="Await. Parts" />
                <StatCard icon="bi-check-circle-fill" color="green" value={completedJobs.length} label="Completed" />
                <StatCard icon="bi-tools" color="red" value={partsRequestedToday} label="Parts Today" />
              </div>
              <div className="table-card mt-4">
                <div className="table-header">
                  <h6><i className="bi bi-clipboard-check-fill" style={{ color: 'var(--primary-blue)' }}></i> My Active Jobs</h6>
                  <button className="btn-outline-blue btn-sm" onClick={() => setActiveTab('assigned')}>View All</button>
                </div>
                <div className="table-responsive">
                  <table className="table table-custom">
                    <thead><tr><th>Job ID</th><th>Vehicle</th><th>Status</th></tr></thead>
                    <tbody>
                      {myJobs.length === 0 ? (
                        <tr><td colSpan={3} className="text-center text-muted">No jobs assigned yet.</td></tr>
                      ) : myJobs.slice(0, 5).map((j, i) => (
                        <tr key={j.JobID}>
                          <td className="row-number">{i + 1}</td>
                          <td>{vehiclePlate(j.VehicleID)}</td>
                          <td><JobStatusBadge status={j.Status} /></td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
            </>
          )}

          {activeTab === 'assigned' && (
            <>
              <div className="row g-3 mb-3">
                <StatCard icon="bi-clipboard-check-fill" color="blue" value={myJobs.length} label="Total Jobs" colClass="col-6 col-sm-6 col-lg-3" />
                <StatCard icon="bi-lightning-charge-fill" color="orange" value={activeJobs.length} label="Active" colClass="col-6 col-sm-6 col-lg-3" />
                <StatCard icon="bi-hourglass-split" color="amber" value={awaitingPartsJobs.length} label="Awaiting Parts" colClass="col-6 col-sm-6 col-lg-3" />
                <StatCard icon="bi-check-circle-fill" color="green" value={completedJobs.length} label="Completed" colClass="col-6 col-sm-6 col-lg-3" />
              </div>
              <div className="table-card">
              <div className="table-header">
                <h6><i className="bi bi-clipboard-check-fill" style={{ color: 'var(--primary-blue)' }}></i> My Jobs</h6>
                <button type="button" className="btn-blue btn-sm btn-refresh" onClick={handleRefresh} disabled={refreshing} title="Refresh data">
                  <i className={`bi bi-arrow-clockwise${refreshing ? ' spin' : ''}`}></i> Refresh
                </button>
              </div>
              <div className="table-responsive">
                <table id="assignedTable" className="table table-custom">
                  <thead><tr><th>Job ID</th><th>Vehicle</th><th>Customer</th><th>Status</th><th>Action</th><th>Update Status</th></tr></thead>
                  <tbody>
                    {myJobs.length === 0 ? (
                      <tr><td colSpan={6} className="text-center text-muted">No jobs assigned yet.</td></tr>
                    ) : myJobs.map((j, i) => (
                      <tr key={j.JobID}>
                        <td className="row-number">{i + 1}</td>
                        <td>{vehiclePlate(j.VehicleID)}</td>
                        <td>{j.CustomerName || '-'}</td>
                        <td><JobStatusBadge status={normalizeJobStatus(j.Status)} /></td>
                        <td className="text-center">
                          <button className="btn-action view" title="View" onClick={() => viewJob.open(j)}><i className="bi bi-eye"></i></button>
                        </td>
                        <td>
                          <div className="job-status-actions">
                            <button
                              type="button"
                              className="job-status-btn job-next-btn"
                              onClick={() => advanceJob(j)}
                              disabled={normalizeJobStatus(j.Status) === 'Cancelled' || normalizeJobStatus(j.Status) === 'Delivered' || updatingJobId === j.JobID}
                              title={normalizeJobStatus(j.Status) === 'Cancelled' ? 'Cancelled jobs cannot continue' : 'Advance to the next workflow status'}
                            >
                              <i className={`bi ${updatingJobId === j.JobID ? 'bi-arrow-repeat spin' : 'bi-arrow-right'}`}></i>
                              {updatingJobId === j.JobID ? 'Updating...' : 'Next'}
                            </button>
                            <button
                              type="button"
                              className="job-status-btn job-cancel-btn"
                              onClick={() => cancelJob(j)}
                              disabled={normalizeJobStatus(j.Status) === 'Cancelled' || updatingJobId === j.JobID}
                              title="Mark this repair job as cancelled"
                            >
                              <i className="bi bi-x-circle"></i> Cancelled
                            </button>
                          </div>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              </div>
            </>
          )}
          <DetailsModal
            id="viewJobModal" title="Job Details" icon="bi-clipboard-check-fill"
            fields={viewJob.row && [
              { label: 'Job ID', value: viewJob.row.JobID },
              { label: 'Vehicle', value: vehiclePlate(viewJob.row.VehicleID) },
              { label: 'Customer', value: viewJob.row.CustomerName },
              { label: 'Status', value: viewJob.row.Status },
              { label: 'Start Date', value: viewJob.row.StartDate },
              { label: 'End Date', value: viewJob.row.EndDate },
            ]}
            actions={viewJob.row && <>
              {normalizeJobStatus(viewJob.row.Status) !== 'Delivered' && normalizeJobStatus(viewJob.row.Status) !== 'Cancelled' && <button type="button" className="btn-blue btn-sm" onClick={() => advanceJob(viewJob.row)}><i className="bi bi-arrow-right"></i> Next</button>}
              {normalizeJobStatus(viewJob.row.Status) !== 'Cancelled' && <button type="button" className="btn-icon danger" title="Cancel job" onClick={() => cancelJob(viewJob.row)}><i className="bi bi-x-circle"></i></button>}
            </>}
          />

          <Modal id="diagnosticsModal" title={`Record Notes${notesJobId ? ` - Job ${notesJobId} (${notesPlate})` : ''}`} icon="bi-clipboard2-plus">
            <form onSubmit={saveNotes}>
              <div className="row g-3">
                <div className="col-md-6">
                  <label className="form-label-custom">Job</label>
                  <input type="text" className="form-control form-control-custom" readOnly value={notesJobId || ''} />
                </div>
                <div className="col-12">
                  <label className="form-label-custom">Notes</label>
                  <textarea className="form-control form-control-custom" rows={6} placeholder="Write your notes here..." required value={notesText ?? ''} onChange={(e) => setNotesText(e.target.value)}></textarea>
                </div>
              </div>
              <button type="submit" className="btn-primary-full btn-save mt-3"><i className="bi bi-check-lg"></i> Save Notes</button>
            </form>
          </Modal>

          {activeTab === 'parts' && (
            <>
              <div className="row g-3 mb-3">
                <StatCard icon="bi-box-seam" color="blue" value={requests.length} label="Total Requests" colClass="col-6 col-sm-6 col-lg-3" />
                <StatCard icon="bi-hourglass-split" color="orange" value={requests.filter((r) => !r.Status || r.Status === 'Pending').length} label="Pending" colClass="col-6 col-sm-6 col-lg-3" />
                <StatCard icon="bi-check-circle-fill" color="green" value={requests.filter((r) => r.Status === 'Fulfilled').length} label="Fulfilled" colClass="col-6 col-sm-6 col-lg-3" />
                <StatCard icon="bi-x-circle-fill" color="red" value={requests.filter((r) => r.Status === 'Rejected').length} label="Rejected" colClass="col-6 col-sm-6 col-lg-3" />
              </div>
              <div className="card-custom p-4" style={{ maxWidth: 700, margin: '0 auto' }}>
                <h6 style={{ fontWeight: 700 }}><i className="bi bi-boxes" style={{ color: 'var(--primary-blue)' }}></i> Request Spare Parts</h6>
                <form onSubmit={saveRequest}>
                  <div className="row g-3 mt-2">
                    <div className="col-md-6">
                      <label className="form-label-custom">Job <span className="text-muted">(optional)</span></label>
                      <select className="form-select form-control-custom" value={requestForm.JobID ?? ''} onChange={(e) => setRequestForm((f) => ({ ...f, JobID: e.target.value }))}>
                        <option value="">Select job (optional)...</option>
                        {myJobs.filter((j) => ACTIVE_STATUSES.includes(normalizeJobStatus(j.Status))).map((j) => (
                          <option key={j.JobID} value={j.JobID}>{vehiclePlate(j.VehicleID)} - {j.CustomerName || 'Unknown'}</option>
                        ))}
                      </select>
                    </div>
                    <div className="col-md-6">
                      <label className="form-label-custom">Part Name</label>
                      <select className="form-select form-control-custom" required value={requestForm.SparePartID ?? ''} onChange={(e) => setRequestForm((f) => ({ ...f, SparePartID: e.target.value }))}>
                        <option value="">Select part...</option>
                        {spareParts.map((p) => <option key={p.SparePartID} value={p.SparePartID}>{p.PartName} (Stock: {p.Quantity})</option>)}
                      </select>
                    </div>
                    <div className="col-md-4">
                      <label className="form-label-custom">Quantity</label>
                      <input type="number" min="1" max={selectedPart?.Quantity ?? undefined} className="form-control form-control-custom" required value={requestForm.QuantityRequested ?? ''} onChange={(e) => setRequestForm((f) => ({ ...f, QuantityRequested: e.target.value }))} />
                    </div>
                    <div className="col-md-4"><label className="form-label-custom">Available Quantity</label><input type="text" className="form-control form-control-custom" readOnly value={selectedPart ? `${selectedPart.Quantity} units` : '-'} /></div>
                    <div className="col-md-4"><label className="form-label-custom">Unit Cost</label><input type="text" className="form-control form-control-custom" readOnly value={selectedPart ? `${selectedUnitCost.toLocaleString('en-US')} RWF` : '-'} /></div>
                    <div className="col-md-4"><label className="form-label-custom">Total Cost</label><input type="text" className="form-control form-control-custom" readOnly value={selectedPart ? `${requestedTotalCost.toLocaleString('en-US')} RWF` : '-'} /></div>
                    <div className="col-md-8">
                      <label className="form-label-custom">Reason</label>
                      <input type="text" className="form-control form-control-custom" placeholder="Why do you need this part?" value={requestForm.Reason ?? ''} onChange={(e) => setRequestForm((f) => ({ ...f, Reason: e.target.value }))} />
                    </div>
                  </div>
                  <button type="submit" className="btn-primary-full btn-save mt-4"><i className="bi bi-send"></i> Submit Request</button>
                </form>
              </div>

              <div className="table-card mt-4">
                <div className="table-header">
                  <h6><i className="bi bi-list-check" style={{ color: 'var(--primary-blue)' }}></i> My Requests</h6>
                  <button type="button" className="btn-blue btn-sm btn-refresh" onClick={handleRefresh} disabled={refreshing} title="Refresh data">
                    <i className={`bi bi-arrow-clockwise${refreshing ? ' spin' : ''}`}></i> Refresh
                  </button>
                </div>
                <div className="table-responsive">
                  <table className="table table-custom">
                    <thead><tr><th>Request ID</th><th>Part</th><th>Quantity</th><th>Unit Cost</th><th>Total Cost</th><th>Reason</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead>
                    <tbody>
                      {requests.length === 0 ? (
                        <tr><td colSpan={9} className="text-center text-muted">No requests yet.</td></tr>
                      ) : requests.map((r) => (
                        <tr key={r.RequestID}>
                          <td>{r.RequestID}</td>
                          <td>{r.SparePartName || spareParts.find((p) => p.SparePartID === r.SparePartID)?.PartName || 'N/A'}</td>
                          <td>{r.QuantityRequested}</td>
                          <td>{Number(r.UnitCost || 0).toLocaleString('en-US')} RWF</td>
                          <td>{Number(r.TotalCost || (Number(r.UnitCost || 0) * Number(r.QuantityRequested || 0))).toLocaleString('en-US')} RWF</td>
                          <td>{r.Reason || '-'}</td>
                          <td><StatusBadge status={r.Status || 'Pending'} okValues={['Fulfilled']} lowValues={['Rejected']} /></td>
                          <td>{r.RequestedAt ? new Date(r.RequestedAt).toLocaleDateString() : '-'}</td>
                          <td>{r.Status === 'Pending' ? <button className="btn-action delete" onClick={() => cancelRequest(r)}><i className="bi bi-trash"></i></button> : '-'}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
            </>
          )}

          {activeTab === 'history' && (
            <>
              <div className="row g-3 mb-3">
                <StatCard icon="bi-clock-history" color="blue" value={jobHistory.length} label="Total History" colClass="col-6 col-sm-6 col-lg-3" />
                  <StatCard icon="bi-truck" color="green" value={jobHistory.filter((j) => normalizeJobStatus(j.NewStatus) === 'Delivered').length} label="Delivered" colClass="col-6 col-sm-6 col-lg-3" />
                    <StatCard icon="bi-check-circle-fill" color="purple" value={jobHistory.filter((j) => normalizeJobStatus(j.NewStatus) === 'Ready').length} label="Ready" colClass="col-6 col-sm-6 col-lg-3" />
                  <StatCard icon="bi-x-circle-fill" color="red" value={jobHistory.filter((j) => normalizeJobStatus(j.NewStatus) === 'Cancelled').length} label="Cancelled" colClass="col-6 col-sm-6 col-lg-3" />
              </div>
              <div className="table-card">
              <div className="table-header">
                <h6><i className="bi bi-clock-history" style={{ color: 'var(--primary-blue)' }}></i> Job History</h6>
                <button type="button" className="btn-blue btn-sm btn-refresh" onClick={handleRefresh} disabled={refreshing} title="Refresh data">
                  <i className={`bi bi-arrow-clockwise${refreshing ? ' spin' : ''}`}></i> Refresh
                </button>
              </div>
              <div className="table-responsive">
                <table className="table table-custom">
                  <thead><tr><th>Job ID</th><th>Date</th><th>Vehicle</th><th>Status</th><th>Notes</th><th>Action</th></tr></thead>
                  <tbody>
                    {jobHistory.length === 0 ? (
                      <tr><td colSpan={6} className="text-center text-muted">No completed jobs yet.</td></tr>
                    ) : jobHistory.map((h, i) => (
                      <tr key={h.HistoryID ?? `${h.JobID}-${h.ChangedAt}-${i}`}>
                        <td className="row-number">{i + 1}</td>
                        <td>{h.ChangedAt ? new Date(h.ChangedAt).toLocaleString() : '-'}</td>
                        <td>{vehiclePlate(h.VehicleID)}</td>
                        <td><JobStatusBadge status={normalizeJobStatus(h.NewStatus)} /></td>
                        <td className="history-notes-cell">{h.Notes || '-'}</td>
                        <td>
                          <button className="btn-action view" title="View" onClick={() => viewJob.open(jobs.find((job) => job.JobID === h.JobID) || h)}><i className="bi bi-eye"></i></button>
                          <button className="btn-action delete" title="Delete history record" onClick={() => deleteHistoryRecord(h)}><i className="bi bi-trash"></i></button>
                        </td>
                      </tr>
                    ))}
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
              <div className="d-flex justify-content-between align-items-center mb-4">
                <h6 style={{ fontWeight: 700 }}><i className="bi bi-bell-fill" style={{ color: 'var(--primary-blue)' }}></i> All Notifications</h6>
                <button className="btn-outline-blue btn-sm" onClick={markAllRead}><i className="bi bi-check-all"></i> Mark All Read</button>
              </div>
              {notifications.length === 0 ? (
                <div className="text-center py-4 text-muted">No notifications yet.</div>
              ) : notifications.map((n) => (
                <div key={n.NotificationID} className={`list-group-item d-flex gap-3 align-items-center py-3 border-bottom ${n.IsRead ? 'opacity-75' : ''}`}>
                  <i className="bi bi-info-circle-fill" style={{ color: '#2563eb', fontSize: '1.3rem' }}></i>
                  <div className="flex-grow-1">
                    <div style={{ fontWeight: 600, fontSize: '0.95rem' }}>{n.Message}</div>
                    <div style={{ fontSize: '0.85rem', color: 'var(--text-muted)' }}>{n.CreatedAt ? new Date(n.CreatedAt).toLocaleString() : ''}</div>
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
              { label: 'Message', value: viewNotification.row.Message },
              { label: 'Sent', value: viewNotification.row.CreatedAt ? new Date(viewNotification.row.CreatedAt).toLocaleString() : '-' },
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
