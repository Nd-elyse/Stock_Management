import React, { useCallback, useEffect, useMemo, useState } from 'react';
import '../../assets/staff.css';
import { DashboardShell, DataTable, Modal, DetailsModal, useViewModal, StatCard, showBsModal, hideBsModal, ConfirmDelete } from '../../components';
import { useAuth, useToast } from '../../context';
import { jobsApi, customersApi, inventoryApi, notificationsApi, authApi } from '../../api';

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
    case 'In Progress':
    case 'Diagnosed':
      return 'badge-inprogress';
    case 'Awaiting Parts':
      return 'badge-awaiting';
    case 'Cancelled':
      return 'badge-danger';
    default:
      return 'badge-ok';
  }
}
const JobStatusBadge = ({ status }) => <span className={`badge-status ${jobStatusBadgeClass(status)}`}>{status || 'Pending'}</span>;

const JOB_STATUS_OPTIONS = ['Pending', 'Diagnosed', 'In Progress', 'Awaiting Parts', 'Ready', 'Delivered', 'Cancelled'];
const HISTORY_STATUSES = ['Delivered', 'Ready', 'Completed', 'Cancelled'];
const ACTIVE_STATUSES = ['Pending', 'Diagnosed', 'In Progress'];

const emptyRequest = { RequestID: null, SparePartID: '', JobID: '', QuantityRequested: 1, Reason: '' };

export default function Mechanic() {
  const { user } = useAuth();
  const { showToast } = useToast();
  const [activeTab, setActiveTab] = useState('dashboard');
  const viewNotification = useViewModal('viewNotificationModal');
  const viewJob = useViewModal('viewJobModal');

  const [jobs, setJobs] = useState([]);
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
    const [j, v, sp, req, notif] = await Promise.all([
      jobsApi.listJobs(),
      customersApi.listVehicles(),
      inventoryApi.listSpareParts(),
      inventoryApi.listSparePartRequests(),
      notificationsApi.listAll(),
    ]);
    if (j.success) setJobs(j.data || []);
    if (v.success) setVehicles(v.data || []);
    if (sp.success) setSpareParts(sp.data || []);
    if (req.success) setRequests(req.data || []);
    if (notif.success) setNotifications(notif.data || []);
    setLoading(false);
  }, []);

  useEffect(() => { loadAll(); }, [loadAll]);

  const unreadCount = useMemo(() => notifications.filter((n) => !n.IsRead).length, [notifications]);
  const vehiclePlate = (id) => vehicles.find((v) => v.VehicleID === id)?.PlateNumber || '-';

  const myJobs = jobs; // /jobs is already scoped to the signed-in mechanic on the backend
  const activeJobs = myJobs.filter((j) => ACTIVE_STATUSES.includes(j.Status));
  const awaitingPartsJobs = myJobs.filter((j) => j.Status === 'Awaiting Parts');
  const completedJobs = myJobs.filter((j) => ['Delivered', 'Ready', 'Completed'].includes(j.Status));
  const jobHistory = myJobs.filter((j) => HISTORY_STATUSES.includes(j.Status));
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

  const updateJobStatus = async (job, newStatus) => {
    const res = await jobsApi.saveJob({ JobID: job.JobID, Status: newStatus });
    if (res.success) { showToast('Status updated successfully', 'success'); loadAll(); }
    else showToast(res.message || 'Failed to update status.', 'danger');
  };

  const deleteHistoryJob = async (job) => {
    if (!(await ConfirmDelete('job', `Job ${job.JobID}`))) return;
    const res = await jobsApi.removeJob(job.JobID);
    if (res.success) { showToast('Job removed successfully.', 'success'); loadAll(); }
    else showToast(res.message || 'Failed to delete job.', 'danger');
  };

  const saveRequest = async (e) => {
    e.preventDefault();
    if (!requestForm.SparePartID) { showToast('Please select a spare part.', 'danger'); return; }
    if (!requestForm.QuantityRequested || Number(requestForm.QuantityRequested) < 1) { showToast('Quantity must be at least 1.', 'danger'); return; }
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
            <div className="table-card">
              <div className="table-header">
                <h6><i className="bi bi-clipboard-check-fill" style={{ color: 'var(--primary-blue)' }}></i> My Jobs</h6>
              </div>
              <div className="table-responsive">
                <table className="table table-custom">
                  <thead><tr><th>Job ID</th><th>Vehicle</th><th>Customer</th><th>Status</th><th>Action</th><th>Update Status</th></tr></thead>
                  <tbody>
                    {myJobs.length === 0 ? (
                      <tr><td colSpan={6} className="text-center text-muted">No jobs assigned yet.</td></tr>
                    ) : myJobs.map((j, i) => (
                      <tr key={j.JobID}>
                        <td className="row-number">{i + 1}</td>
                        <td>{vehiclePlate(j.VehicleID)}</td>
                        <td>{j.CustomerName || '-'}</td>
                        <td><JobStatusBadge status={j.Status} /></td>
                        <td className="text-center">
                          <button className="btn-action view" title="View" onClick={() => viewJob.open(j)}><i className="bi bi-eye"></i></button>
                          <button className="btn-icon" title="Record notes" onClick={() => openNotes(j)}><i className="bi bi-pencil-square"></i></button>
                        </td>
                        <td>
                          <select className="status-select" value={j.Status || 'Pending'} onChange={(e) => updateJobStatus(j, e.target.value)}>
                            {JOB_STATUS_OPTIONS.map((s) => <option key={s} value={s}>{s}</option>)}
                          </select>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
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
              <div className="card-custom p-4" style={{ maxWidth: 700, margin: '0 auto' }}>
                <h6 style={{ fontWeight: 700 }}><i className="bi bi-boxes" style={{ color: 'var(--primary-blue)' }}></i> Request Spare Parts</h6>
                <form onSubmit={saveRequest}>
                  <div className="row g-3 mt-2">
                    <div className="col-md-6">
                      <label className="form-label-custom">Job <span className="text-muted">(optional)</span></label>
                      <select className="form-select form-control-custom" value={requestForm.JobID ?? ''} onChange={(e) => setRequestForm((f) => ({ ...f, JobID: e.target.value }))}>
                        <option value="">Select job (optional)...</option>
                        {myJobs.filter((j) => ['Pending', 'Diagnosed', 'In Progress', 'Awaiting Parts'].includes(j.Status)).map((j) => (
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
                      <input type="number" min="1" className="form-control form-control-custom" required value={requestForm.QuantityRequested ?? ''} onChange={(e) => setRequestForm((f) => ({ ...f, QuantityRequested: e.target.value }))} />
                    </div>
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
                </div>
                <div className="table-responsive">
                  <table className="table table-custom">
                    <thead><tr><th>Request ID</th><th>Part</th><th>Quantity</th><th>Reason</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead>
                    <tbody>
                      {requests.length === 0 ? (
                        <tr><td colSpan={7} className="text-center text-muted">No requests yet.</td></tr>
                      ) : requests.map((r) => (
                        <tr key={r.RequestID}>
                          <td>{r.RequestID}</td>
                          <td>{r.SparePartName || spareParts.find((p) => p.SparePartID === r.SparePartID)?.PartName || 'N/A'}</td>
                          <td>{r.QuantityRequested}</td>
                          <td>{r.Reason || '-'}</td>
                          <td><span className={`badge-status ${r.Status === 'Fulfilled' ? 'badge-ok' : r.Status === 'Rejected' ? 'badge-danger' : 'badge-low'}`}>{r.Status || 'Pending'}</span></td>
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
            <div className="table-card">
              <div className="table-header">
                <h6><i className="bi bi-clock-history" style={{ color: 'var(--primary-blue)' }}></i> Job History</h6>
              </div>
              <div className="table-responsive">
                <table className="table table-custom">
                  <thead><tr><th>Job ID</th><th>Date</th><th>Vehicle</th><th>Status</th><th>Notes</th><th>Action</th></tr></thead>
                  <tbody>
                    {jobHistory.length === 0 ? (
                      <tr><td colSpan={6} className="text-center text-muted">No completed jobs yet.</td></tr>
                    ) : jobHistory.map((h, i) => (
                      <tr key={h.JobID}>
                        <td className="row-number">{i + 1}</td>
                        <td>{h.EndDate || '-'}</td>
                        <td>{vehiclePlate(h.VehicleID)}</td>
                        <td><span className="badge-status badge-delivered">{h.Status}</span></td>
                        <td>{h.Notes || '-'}</td>
                        <td>
                          <button className="btn-action view" title="View" onClick={() => viewJob.open(h)}><i className="bi bi-eye"></i></button>
                          <button className="btn-action delete" title="Delete" onClick={() => deleteHistoryJob(h)}><i className="bi bi-trash"></i></button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}

          {activeTab === 'notifications' && (
            <div className="card-custom p-4">
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
                  <input type="tel" className="form-control form-control-custom" value={profileForm.phone ?? ''} onChange={(e) => setProfileForm((f) => ({ ...f, phone: e.target.value }))} />
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
