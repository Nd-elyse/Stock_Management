import React, { useCallback, useEffect, useMemo, useState } from 'react';
import '../../assets/staff.css';
import { DashboardShell, DataTable, Modal, StatCard, StatusBadge, showBsModal, hideBsModal, ConfirmDelete } from '../../components';
import { useAuth, useToast } from '../../context';
import { usersApi, jobsApi, inventoryApi, notificationsApi, contactApi, authApi } from '../../api';

const NAV_SECTIONS = [
  { title: 'Overview', items: [{ key: 'dashboard', label: 'Dashboard', icon: 'bi-speedometer2' }] },
  {
    title: 'Management',
    items: [
      { key: 'users', label: 'Users', icon: 'bi-people-fill' },
      { key: 'mechanics', label: 'Mechanics', icon: 'bi-wrench' },
      { key: 'suppliers', label: 'Suppliers', icon: 'bi-truck' },
      { key: 'spareparts', label: 'Spare Parts', icon: 'bi-boxes' },
    ],
  },
  {
    title: 'Insights',
    items: [
      { key: 'reports', label: 'Reports', icon: 'bi-bar-chart-line-fill' },
      { key: 'messages', label: 'Messages', icon: 'bi-envelope-fill' },
      { key: 'notifications', label: 'Notifications', icon: 'bi-bell-fill' },
    ],
  },
  ];

const emptyUser = { UserID: null, Username: '', Password: '', Role: 'Receptionist', FullName: '', Email: '', Phone: '', Status: 'Active' };

export default function Admin() {
  const { user } = useAuth();
  const { showToast } = useToast();
  const [activeTab, setActiveTab] = useState('dashboard');

  const [users, setUsers] = useState([]);
  const [mechanics, setMechanics] = useState([]);
  const [suppliers, setSuppliers] = useState([]);
  const [spareParts, setSpareParts] = useState([]);
  const [notifications, setNotifications] = useState([]);
  const [messages, setMessages] = useState([]);
  const [loading, setLoading] = useState(true);

  const [userForm, setUserForm] = useState(emptyUser);
  const [profileForm, setProfileForm] = useState({ full_name: user?.name || '', username: user?.username || '', email: user?.email || '', current_password: '', new_password: '', confirm_password: '' });

  const loadAll = useCallback(async () => {
    setLoading(true);
    const [u, m, s, sp, n, msg] = await Promise.all([
      usersApi.list(),
      jobsApi.listMechanics(),
      inventoryApi.listSuppliers(),
      inventoryApi.listSpareParts(),
      notificationsApi.listAll(),
      contactApi.list(),
    ]);
    if (u.success) setUsers(u.data || []);
    if (m.success) setMechanics(m.data || []);
    if (s.success) setSuppliers(s.data || []);
    if (sp.success) setSpareParts(sp.data || []);
    if (n.success) setNotifications(n.data || []);
    if (msg.success) setMessages(msg.data || []);
    setLoading(false);
  }, []);

  useEffect(() => { loadAll(); }, [loadAll]);

  const unreadCount = useMemo(() => notifications.filter((n) => !n.IsRead && !n.is_read).length, [notifications]);

  // ---- Users CRUD ----
  const openAddUser = () => { setUserForm(emptyUser); showBsModal('userModal'); };
  const openEditUser = (u) => { setUserForm({ ...emptyUser, ...u, Password: '' }); showBsModal('userModal'); };
  const saveUser = async (e) => {
    e.preventDefault();
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
    if (!ConfirmDelete('user')) return;
    const res = await usersApi.remove(u.UserID);
    showToast(res.success ? 'User deleted.' : res.message || 'Could not delete user.', res.success ? 'success' : 'danger');
    if (res.success) loadAll();
  };

  // ---- Notifications ----
  const markAllRead = async () => {
    const res = await notificationsApi.markAllRead();
    if (res.success) { showToast('All notifications marked as read.', 'success'); loadAll(); }
  };

  // ---- Profile / Settings ----
  const saveProfile = async (e) => {
    e.preventDefault();
    const res = await authApi.updateProfile(profileForm);
    showToast(res.success ? 'Profile updated.' : res.message || 'Could not update profile.', res.success ? 'success' : 'danger');
  };

  const pageTitles = {
    dashboard: 'Dashboard', users: 'User Management', mechanics: 'Mechanics', suppliers: 'Suppliers',
    spareparts: 'Spare Parts', reports: 'Reports', messages: 'Contact Messages', notifications: 'Notifications',
  };

  return (
    <DashboardShell
      brandSub="Admin Portal"
      navSections={NAV_SECTIONS.map((s) => ({
        ...s,
        items: s.items.map((it) => (it.key === 'notifications' ? { ...it, badge: unreadCount } : it)),
      }))}
      activeTab={activeTab}
      onTabChange={setActiveTab}
      pageTitle={pageTitles[activeTab]}
      userName={user?.name}
      userRole="Administrator"
      unreadCount={unreadCount}
    >
      {loading ? (
        <div className="text-center py-5"><span className="spinner-border" /></div>
      ) : (
        <>
          {activeTab === 'dashboard' && (
            <div className="row g-3">
              <StatCard icon="bi-people-fill" color="blue" value={users.length} label="Total Users" />
              <StatCard icon="bi-wrench" color="green" value={mechanics.length} label="Mechanics" />
              <StatCard icon="bi-truck" color="orange" value={suppliers.length} label="Suppliers" />
              <StatCard icon="bi-boxes" color="purple" value={spareParts.length} label="Spare Parts" />
              <div className="col-12">
                <div className="table-card mt-2">
                  <div className="table-header"><h6><i className="bi bi-envelope-fill"></i> Recent Messages</h6></div>
                  <div className="table-responsive">
                    <table className="table table-custom">
                      <thead><tr><th>From</th><th>Subject</th><th>Received</th></tr></thead>
                      <tbody>
                        {messages.slice(0, 5).map((m, i) => (
                          <tr key={m.MessageID ?? i}><td>{m.FullName || m.full_name}</td><td>{m.Subject || m.subject}</td><td>{m.CreatedAt || m.created_at}</td></tr>
                        ))}
                        {messages.length === 0 && <tr><td colSpan={3} className="text-center text-muted py-3">No messages yet.</td></tr>}
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
            </div>
          )}

          {activeTab === 'users' && (
            <DataTable
              title="System Users"
              icon="bi-people-fill"
              addLabel="Add User"
              onAdd={openAddUser}
              searchPlaceholder="Search users..."
              columns={[
                { key: 'Username', label: 'Username' },
                { key: 'FullName', label: 'Full Name' },
                { key: 'Role', label: 'Role' },
                { key: 'Email', label: 'Email' },
                { key: 'Phone', label: 'Phone' },
                { key: 'Status', label: 'Status', render: (r) => <StatusBadge status={r.Status} /> },
              ]}
              rows={users}
              renderActions={(r) => (
                <>
                  <button className="btn-icon" title="Edit" onClick={() => openEditUser(r)}><i className="bi bi-pencil"></i></button>
                  <button className="btn-icon danger" title="Delete" onClick={() => deleteUser(r)}><i className="bi bi-trash"></i></button>
                </>
              )}
            />
          )}

          {activeTab === 'mechanics' && (
            <DataTable
              title="Mechanics"
              icon="bi-wrench"
              searchPlaceholder="Search mechanics..."
              columns={[
                { key: 'FullName', label: 'Full Name' },
                { key: 'Phone', label: 'Phone' },
                { key: 'Specialization', label: 'Specialization' },
                { key: 'Status', label: 'Status', render: (r) => <StatusBadge status={r.Status} /> },
              ]}
              rows={mechanics}
              emptyText="No mechanics found. Mechanics are added by the Admin from this list."
            />
          )}

          {activeTab === 'suppliers' && (
            <DataTable
              title="Suppliers"
              icon="bi-truck"
              searchPlaceholder="Search suppliers..."
              columns={[
                { key: 'SupplierName', label: 'Supplier' },
                { key: 'ContactPerson', label: 'Contact Person' },
                { key: 'Phone', label: 'Phone' },
                { key: 'Email', label: 'Email' },
              ]}
              rows={suppliers}
              emptyText="No suppliers found. Suppliers are managed by the Stock Manager."
            />
          )}

          {activeTab === 'spareparts' && (
            <DataTable
              title="Spare Parts Inventory"
              icon="bi-boxes"
              searchPlaceholder="Search spare parts..."
              columns={[
                { key: 'PartName', label: 'Part Name' },
                { key: 'CategoryName', label: 'Category' },
                { key: 'QuantityInStock', label: 'In Stock' },
                { key: 'UnitPrice', label: 'Unit Price' },
              ]}
              rows={spareParts}
              emptyText="No spare parts found. Inventory is managed by the Stock Manager."
            />
          )}

          {activeTab === 'reports' && (
            <div className="row g-3">
              <StatCard icon="bi-people-fill" color="blue" value={users.length} label="Users" />
              <StatCard icon="bi-wrench" color="green" value={mechanics.length} label="Mechanics" />
              <StatCard icon="bi-boxes" color="purple" value={spareParts.length} label="Spare Parts" />
              <StatCard icon="bi-truck" color="orange" value={suppliers.length} label="Suppliers" />
              <div className="col-12 text-end">
                <button className="btn-blue btn-sm" onClick={() => window.print()}><i className="bi bi-printer"></i> Print Report</button>
              </div>
            </div>
          )}

          {activeTab === 'messages' && (
            <DataTable
              title="Contact Messages"
              icon="bi-envelope-fill"
              searchPlaceholder="Search messages..."
              addLabel="Mark All Read"
              onAdd={async () => { const r = await contactApi.markAllRead(); if (r.success) loadAll(); }}
              columns={[
                { key: 'FullName', label: 'From' },
                { key: 'Email', label: 'Email' },
                { key: 'Subject', label: 'Subject' },
                { key: 'Message', label: 'Message' },
                { key: 'CreatedAt', label: 'Received' },
              ]}
              rows={messages}
              renderActions={(r) => (
                <button className="btn-icon danger" title="Delete" onClick={async () => {
                  if (!ConfirmDelete('message')) return;
                  const res = await contactApi.remove(r.MessageID);
                  showToast(res.success ? 'Message deleted.' : res.message || 'Could not delete.', res.success ? 'success' : 'danger');
                  if (res.success) loadAll();
                }}><i className="bi bi-trash"></i></button>
              )}
            />
          )}

          {activeTab === 'notifications' && (
            <DataTable
              title="Notifications"
              icon="bi-bell-fill"
              searchPlaceholder="Search notifications..."
              addLabel="Mark All Read"
              onAdd={markAllRead}
              columns={[
                { key: 'Title', label: 'Title' },
                { key: 'Message', label: 'Message' },
                { key: 'CreatedAt', label: 'Date' },
                { key: 'IsRead', label: 'Status', render: (r) => <StatusBadge status={r.IsRead || r.is_read ? 'Active' : 'Pending'} /> },
              ]}
              rows={notifications}
              renderActions={(r) => (
                <button className="btn-icon danger" title="Delete" onClick={async () => {
                  if (!ConfirmDelete('notification')) return;
                  const res = await notificationsApi.remove(r.NotificationID);
                  if (res.success) loadAll();
                }}><i className="bi bi-trash"></i></button>
              )}
            />
          )}

          <Modal id="profileModal" title="Profile Settings" icon="bi-gear">
            <form onSubmit={saveProfile}>
              <div className="row g-3">
                <div className="col-md-6">
                  <label className="form-label-custom">Full Name</label>
                  <input className="form-control form-control-custom" required value={profileForm.full_name} onChange={(e) => setProfileForm((f) => ({ ...f, full_name: e.target.value }))} />
                </div>
                <div className="col-md-6">
                  <label className="form-label-custom">Username</label>
                  <input className="form-control form-control-custom" required value={profileForm.username} onChange={(e) => setProfileForm((f) => ({ ...f, username: e.target.value }))} />
                </div>
                <div className="col-md-6">
                  <label className="form-label-custom">Email</label>
                  <input type="email" className="form-control form-control-custom" required value={profileForm.email} onChange={(e) => setProfileForm((f) => ({ ...f, email: e.target.value }))} />
                </div>
                <div className="col-12"><hr /><p className="text-muted small mb-0">Change Password (optional)</p></div>
                <div className="col-md-6">
                  <label className="form-label-custom">Current Password</label>
                  <input type="password" className="form-control form-control-custom" placeholder="Required to change password or username" value={profileForm.current_password} onChange={(e) => setProfileForm((f) => ({ ...f, current_password: e.target.value }))} />
                </div>
                <div className="col-md-6">
                  <label className="form-label-custom">New Password</label>
                  <input type="password" className="form-control form-control-custom" placeholder="Min 6 characters" value={profileForm.new_password} onChange={(e) => setProfileForm((f) => ({ ...f, new_password: e.target.value }))} />
                </div>
                <div className="col-md-6">
                  <label className="form-label-custom">Confirm New Password</label>
                  <input type="password" className="form-control form-control-custom" value={profileForm.confirm_password} onChange={(e) => setProfileForm((f) => ({ ...f, confirm_password: e.target.value }))} />
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
              <label className="form-label-custom">Username</label>
              <input className="form-control form-control-custom" required value={userForm.Username} onChange={(e) => setUserForm((f) => ({ ...f, Username: e.target.value }))} />
            </div>
            <div className="col-md-6">
              <label className="form-label-custom">{userForm.UserID ? 'New Password (optional)' : 'Password'}</label>
              <input type="password" className="form-control form-control-custom" required={!userForm.UserID} value={userForm.Password} onChange={(e) => setUserForm((f) => ({ ...f, Password: e.target.value }))} />
            </div>
            <div className="col-md-6">
              <label className="form-label-custom">Full Name</label>
              <input className="form-control form-control-custom" required value={userForm.FullName} onChange={(e) => setUserForm((f) => ({ ...f, FullName: e.target.value }))} />
            </div>
            <div className="col-md-6">
              <label className="form-label-custom">Role</label>
              <select className="form-select form-control-custom" value={userForm.Role} onChange={(e) => setUserForm((f) => ({ ...f, Role: e.target.value }))}>
                <option value="Admin">Admin</option>
                <option value="Receptionist">Receptionist</option>
                <option value="Mechanic">Mechanic</option>
                <option value="Stock Manager">Stock Manager</option>
              </select>
            </div>
            <div className="col-md-6">
              <label className="form-label-custom">Email</label>
              <input type="email" className="form-control form-control-custom" required value={userForm.Email} onChange={(e) => setUserForm((f) => ({ ...f, Email: e.target.value }))} />
            </div>
            <div className="col-md-6">
              <label className="form-label-custom">Phone</label>
              <input className="form-control form-control-custom" value={userForm.Phone} onChange={(e) => setUserForm((f) => ({ ...f, Phone: e.target.value }))} />
            </div>
            <div className="col-md-6">
              <label className="form-label-custom">Status</label>
              <select className="form-select form-control-custom" value={userForm.Status} onChange={(e) => setUserForm((f) => ({ ...f, Status: e.target.value }))}>
                <option value="Active">Active</option>
                <option value="Inactive">Inactive</option>
              </select>
            </div>
          </div>
          <button type="submit" className="btn-primary-full btn-save mt-3"><i className="bi bi-check-circle"></i> Save User</button>
        </form>
      </Modal>
    </DashboardShell>
  );
}
