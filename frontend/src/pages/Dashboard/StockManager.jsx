import React, { useCallback, useEffect, useMemo, useState } from 'react';
import '../../assets/staff.css';
import { DashboardShell, DataTable, Modal, DetailsModal, useViewModal, printElementById, StatCard, WelcomeBanner, TruncatedText, StatusBadge, showBsModal, hideBsModal, ConfirmDelete } from '../../components';
import { phoneError, digitsOnly, todayStr } from '../../utils/validators';
import { useAuth, useToast } from '../../context';
import { inventoryApi, notificationsApi, authApi } from '../../api';

const NOTIFICATION_ICONS = { job: 'bi-plus-circle-fill', stock: 'bi-box-seam-fill', payment: 'bi-cash-coin', system: 'bi-info-circle-fill' };
const NOTIFICATION_COLORS = { job: '#2563eb', stock: '#d97706', payment: '#16a34a', system: '#64748b' };

function fmtDateTime(v) {
  if (!v) return '-';
  const d = new Date(v);
  if (Number.isNaN(d.getTime())) return v;
  return d.toLocaleString('en-US', { month: 'short', day: '2-digit', hour: 'numeric', minute: '2-digit' });
}

const NAV_SECTIONS = [
  { title: 'Overview', items: [{ key: 'dashboard', label: 'Dashboard', icon: 'bi-speedometer2' }] },
  {
    title: 'Inventory',
    items: [
      { key: 'spareparts', label: 'Spare Parts', icon: 'bi-boxes' },
      { key: 'categories', label: 'Categories', icon: 'bi-tags-fill' },
      { key: 'suppliers', label: 'Suppliers', icon: 'bi-truck' },
      { key: 'purchases', label: 'Purchases', icon: 'bi-cart-check-fill' },
    ],
  },
  {
    title: 'Requests',
    items: [{ key: 'requests', label: 'Part Requests', icon: 'bi-box-seam' }, { key: 'transactions', label: 'Stock Log', icon: 'bi-journal-text' }],
  },
  { title: 'Account', items: [{ key: 'notifications', label: 'Notifications', icon: 'bi-bell-fill' }] },
];

const emptyPart = { SparePartID: null, PartName: '', CategoryID: '', SupplierID: '', Quantity: '', ReorderLevel: '', UnitPrice: '' };
const emptyCategory = { CategoryID: null, CategoryName: '', Description: '' };
const emptySupplier = { SupplierID: null, CompanyName: '', Phone: '', Email: '', Address: '' };
const emptyPurchase = { SparePartID: '', SupplierID: '', Quantity: '', UnitPrice: '', PurchaseDate: '' };

export default function StockManager() {
  const { user } = useAuth();
  const { showToast } = useToast();
  const [activeTab, setActiveTab] = useState('dashboard');

  const [spareParts, setSpareParts] = useState([]);
  const [categories, setCategories] = useState([]);
  const [suppliers, setSuppliers] = useState([]);
  const [purchases, setPurchases] = useState([]);
  const [requests, setRequests] = useState([]);
  const [transactions, setTransactions] = useState([]);
  const [notifications, setNotifications] = useState([]);
  const [loading, setLoading] = useState(true);

  const [partForm, setPartForm] = useState(emptyPart);
  const [categoryForm, setCategoryForm] = useState(emptyCategory);
  const [supplierForm, setSupplierForm] = useState(emptySupplier);
  const [purchaseForm, setPurchaseForm] = useState(emptyPurchase);
  const [profileForm, setProfileForm] = useState({ full_name: user?.name || '', username: user?.username || '', email: user?.email || '', phone: user?.phone || '', current_password: '', new_password: '', confirm_password: '' });

  const loadAll = useCallback(async () => {
    setLoading(true);
    const [sp, cat, sup, pur, req, tx, notif] = await Promise.all([
      inventoryApi.listSpareParts(),
      inventoryApi.listCategories(),
      inventoryApi.listSuppliers(),
      inventoryApi.listPurchases(),
      inventoryApi.listSparePartRequests(),
      inventoryApi.listStockTransactions(),
      notificationsApi.listAll(),
    ]);
    if (sp.success) setSpareParts(sp.data || []);
    if (cat.success) setCategories(cat.data || []);
    if (sup.success) setSuppliers(sup.data || []);
    if (pur.success) setPurchases(pur.data || []);
    if (req.success) setRequests(req.data || []);
    if (tx.success) setTransactions(tx.data || []);
    if (notif.success) setNotifications(notif.data || []);
    setLoading(false);
  }, []);

  useEffect(() => { loadAll(); }, [loadAll]);

  const unreadCount = useMemo(() => notifications.filter((n) => !n.IsRead && !n.is_read).length, [notifications]);
  const lowStockCount = useMemo(() => spareParts.filter((p) => Number(p.Quantity) <= Number(p.ReorderLevel || 0)).length, [spareParts]);
  const isThisMonth = (dateStr) => {
    if (!dateStr) return false;
    const d = new Date(dateStr);
    const now = new Date();
    return !Number.isNaN(d.getTime()) && d.getMonth() === now.getMonth() && d.getFullYear() === now.getFullYear();
  };
  const categoryName = (id) => categories.find((c) => c.CategoryID === id)?.CategoryName || id;
  const supplierName = (id) => suppliers.find((s) => s.SupplierID === id)?.CompanyName || id;
  const partName = (id) => spareParts.find((p) => p.SparePartID === id)?.PartName || id;
  const partStock = (id) => spareParts.find((p) => p.SparePartID === id)?.Quantity ?? '-';

  const withCrud = (label, api, form, setForm, empty, modalId, idKey) => ({
    openAdd: () => { setForm(empty); showBsModal(modalId); },
    openEdit: (row) => { setForm({ ...empty, ...row }); showBsModal(modalId); },
    save: async (e) => {
      e.preventDefault();
      const res = await api.save(form);
      if (res.success) { showToast(`${label} saved.`, 'success'); hideBsModal(modalId); loadAll(); }
      else showToast(res.message || `Could not save ${label.toLowerCase()}.`, 'danger');
    },
    remove: async (row) => {
      const displayName = row.FullName || row.PartName || row.CompanyName || row.CategoryName || row[idKey];
      if (!(await ConfirmDelete(label.toLowerCase(), displayName))) return;
      const res = await api.remove(row[idKey]);
      showToast(res.success ? `${label} deleted.` : res.message || `Could not delete ${label.toLowerCase()}.`, res.success ? 'success' : 'danger');
      if (res.success) loadAll();
    },
  });

  const partCrud = withCrud('Spare part', { save: inventoryApi.saveSparePart, remove: inventoryApi.removeSparePart }, partForm, setPartForm, emptyPart, 'partModal', 'SparePartID');
  const categoryCrud = withCrud('Category', { save: inventoryApi.saveCategory, remove: inventoryApi.removeCategory }, categoryForm, setCategoryForm, emptyCategory, 'categoryModal', 'CategoryID');
  const supplierCrud = withCrud('Supplier', { save: inventoryApi.saveSupplier, remove: inventoryApi.removeSupplier }, supplierForm, setSupplierForm, emptySupplier, 'supplierModal', 'SupplierID');

  const viewPart = useViewModal('viewPartModal');
  const viewCategory = useViewModal('viewCategoryModal');
  const viewSupplier = useViewModal('viewSupplierModal');
  const viewPurchase = useViewModal('viewPurchaseModal');
  const viewNotification = useViewModal('viewNotificationModal');
  const viewRequest = useViewModal('viewRequestModal');
  const viewTransaction = useViewModal('viewTransactionModal');

  const openPurchase = () => { setPurchaseForm(emptyPurchase); showBsModal('purchaseModal'); };
  const savePurchase = async (e) => {
    e.preventDefault();
    const res = await inventoryApi.savePurchase(purchaseForm);
    if (res.success) { showToast('Purchase recorded and stock updated.', 'success'); hideBsModal('purchaseModal'); loadAll(); }
    else showToast(res.message || 'Could not record purchase.', 'danger');
  };

  const approveRequest = async (r) => {
    const res = await inventoryApi.approveSparePartRequest(r.RequestID);
    showToast(res.success ? 'Request approved and stock deducted.' : res.message || 'Could not approve request.', res.success ? 'success' : 'danger');
    if (res.success) loadAll();
  };
  const rejectRequest = async (r) => {
    const reason = window.prompt('Reason for rejecting this request:');
    if (reason === null) return;
    const res = await inventoryApi.rejectSparePartRequest(r.RequestID, reason);
    showToast(res.success ? 'Request rejected.' : res.message || 'Could not reject request.', res.success ? 'success' : 'danger');
    if (res.success) loadAll();
  };
  const deleteRequest = async (r) => {
    if (!(await ConfirmDelete('request', r.SparePartName || partName(r.SparePartID)))) return;
    const res = await inventoryApi.removeSparePartRequest(r.RequestID);
    showToast(res.success ? 'Request deleted.' : res.message || 'Could not delete request.', res.success ? 'success' : 'danger');
    if (res.success) loadAll();
  };
  const deleteTransaction = async (t) => {
    if (!(await ConfirmDelete('stock transaction record', t.PartName || partName(t.SparePartID)))) return;
    const res = await inventoryApi.removeStockTransaction(t.TransactionID);
    showToast(res.success ? 'Transaction record deleted.' : res.message || 'Could not delete transaction.', res.success ? 'success' : 'danger');
    if (res.success) loadAll();
  };

  const saveProfile = async (e) => {
    e.preventDefault();
    if (phoneError(profileForm.phone)) { showToast(phoneError(profileForm.phone), 'danger'); return; }
    const res = await authApi.updateProfile(profileForm);
    showToast(res.success ? 'Profile updated.' : res.message || 'Could not update profile.', res.success ? 'success' : 'danger');
  };

  const pageTitles = {
    dashboard: 'Dashboard', spareparts: 'Spare Parts', categories: 'Categories', suppliers: 'Suppliers',
    purchases: 'Purchases', requests: 'Part Requests', transactions: 'Stock Log', notifications: 'Notifications',
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
      brandSub="Stock Room"
      navSections={NAV_SECTIONS.map((s) => ({ ...s, items: s.items.map((it) => (it.key === 'notifications' ? { ...it, badge: unreadCount } : it)) }))}
      activeTab={activeTab}
      onTabChange={setActiveTab}
      pageTitle={pageTitles[activeTab]}
      userName={user?.name}
      userRole="Stock Manager"
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
              <WelcomeBanner name={user?.name} subtitle="Here's a live view of your inventory today." />
              <div className="row g-3">
                <StatCard icon="bi-boxes" color="blue" value={spareParts.length} label="Spare Parts" />
                <StatCard icon="bi-exclamation-triangle" color="orange" value={lowStockCount} label="Low Stock" />
                <StatCard icon="bi-truck" color="green" value={suppliers.length} label="Suppliers" />
                <StatCard icon="bi-box-seam" color="purple" value={requests.filter((r) => r.Status === 'Pending' || !r.Status).length} label="Pending Requests" />
              </div>
            </>
          )}

          {activeTab === 'spareparts' && (
            <>
              <div className="row g-3 mb-3">
                <StatCard icon="bi-boxes" color="blue" value={spareParts.length} label="Total Parts" colClass="col-6 col-sm-6 col-lg-3" />
                <StatCard icon="bi-exclamation-triangle-fill" color="orange" value={lowStockCount} label="Low Stock" colClass="col-6 col-sm-6 col-lg-3" />
                <StatCard icon="bi-x-octagon-fill" color="red" value={spareParts.filter((p) => Number(p.Quantity) === 0).length} label="Out of Stock" colClass="col-6 col-sm-6 col-lg-3" />
                <StatCard icon="bi-cash-stack" color="green" value={`${spareParts.reduce((sum, p) => sum + Number(p.Quantity || 0) * Number(p.UnitPrice || 0), 0).toLocaleString('en-US')} RWF`} label="Inventory Value" colClass="col-6 col-sm-6 col-lg-3" />
              </div>
              <DataTable
                onRefresh={loadAll}
                title="Spare Parts Inventory" icon="bi-boxes" addLabel="Add Spare Part" onAdd={partCrud.openAdd} searchPlaceholder="Search spare parts..."
                columns={[
                  { key: 'PartName', label: 'Part Name' },
                  { key: 'CategoryID', label: 'Category', render: (r) => categoryName(r.CategoryID) },
                  { key: 'SupplierID', label: 'Supplier', render: (r) => supplierName(r.SupplierID) },
                  { key: 'Quantity', label: 'In Stock', render: (r) => (
                    <span className={Number(r.Quantity) <= Number(r.ReorderLevel || 0) ? 'text-danger fw-bold' : ''}>{r.Quantity}</span>
                  ) },
                  { key: 'UnitPrice', label: 'Unit Price (RWF)' },
                ]}
                rows={spareParts}
                renderActions={(r) => (
                  <>
                    <button className="btn-action view" title="View" onClick={() => viewPart.open(r)}><i className="bi bi-eye"></i></button>
                    <button className="btn-icon" title="Edit" onClick={() => partCrud.openEdit(r)}><i className="bi bi-pencil"></i></button>
                    <button className="btn-icon danger" title="Delete" onClick={() => partCrud.remove(r)}><i className="bi bi-trash"></i></button>
                  </>
                )}
              />
              <DetailsModal
                id="viewPartModal" title="Spare Part Details" icon="bi-boxes"
                fields={viewPart.row && [
                  { label: 'Part Name', value: viewPart.row.PartName },
                  { label: 'Category', value: viewPart.row.CategoryName || categoryName(viewPart.row.CategoryID) },
                  { label: 'Supplier', value: viewPart.row.SupplierName || supplierName(viewPart.row.SupplierID) },
                  { label: 'Unit Price', value: `${Number(viewPart.row.UnitPrice || 0).toLocaleString('en-US')} RWF` },
                  { label: 'Quantity In Stock', value: viewPart.row.Quantity },
                  { label: 'Reorder Level', value: viewPart.row.ReorderLevel },
                  { label: 'Stock Status', value: Number(viewPart.row.Quantity) <= Number(viewPart.row.ReorderLevel || 0) ? 'Low Stock' : 'In Stock' },
                ]}
              />
              <Modal id="partModal" title={partForm.SparePartID ? 'Edit Spare Part' : 'Add Spare Part'} icon="bi-boxes">
                <form onSubmit={partCrud.save}>
                  <div className="row g-3">
                    <div className="col-md-6"><label className="form-label-custom">Part Name</label><input className="form-control form-control-custom" required value={partForm.PartName ?? ''} onChange={(e) => setPartForm((f) => ({ ...f, PartName: e.target.value }))} /></div>
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
                    <div className="col-md-6"><label className="form-label-custom">Unit Price (RWF)</label><input type="number" className="form-control form-control-custom" required value={partForm.UnitPrice ?? ''} onChange={(e) => setPartForm((f) => ({ ...f, UnitPrice: e.target.value }))} /></div>
                    <div className="col-md-6"><label className="form-label-custom">Quantity In Stock</label><input type="number" min="0" className="form-control form-control-custom" required value={partForm.Quantity ?? ''} onChange={(e) => setPartForm((f) => ({ ...f, Quantity: e.target.value }))} /></div>
                    <div className="col-md-6"><label className="form-label-custom">Reorder Level</label><input type="number" min="0" className="form-control form-control-custom" required value={partForm.ReorderLevel ?? ''} onChange={(e) => setPartForm((f) => ({ ...f, ReorderLevel: e.target.value }))} /></div>
                  </div>
                  <button type="submit" className="btn-primary-full btn-save mt-3"><i className="bi bi-check-circle"></i> Save Spare Part</button>
                </form>
              </Modal>
            </>
          )}

          {activeTab === 'categories' && (
            <>
              <DataTable
                onRefresh={loadAll}
                title="Categories" icon="bi-tags-fill" addLabel="Add Category" onAdd={categoryCrud.openAdd} searchPlaceholder="Search categories..."
                columns={[{ key: 'CategoryName', label: 'Category Name' }, { key: 'Description', label: 'Description' }]}
                rows={categories}
                renderActions={(r) => (
                  <>
                    <button className="btn-action view" title="View" onClick={() => viewCategory.open(r)}><i className="bi bi-eye"></i></button>
                    <button className="btn-icon" title="Edit" onClick={() => categoryCrud.openEdit(r)}><i className="bi bi-pencil"></i></button>
                    <button className="btn-icon danger" title="Delete" onClick={() => categoryCrud.remove(r)}><i className="bi bi-trash"></i></button>
                  </>
                )}
              />
              <DetailsModal
                id="viewCategoryModal" title="Category Details" icon="bi-tags-fill"
                fields={viewCategory.row && [
                  { label: 'Category Name', value: viewCategory.row.CategoryName },
                  { label: 'Description', value: viewCategory.row.Description },
                  { label: 'Spare Parts in Category', value: spareParts.filter((p) => p.CategoryID === viewCategory.row.CategoryID).length },
                ]}
              />
              <Modal id="categoryModal" title={categoryForm.CategoryID ? 'Edit Category' : 'Add Category'} icon="bi-tags-fill">
                <form onSubmit={categoryCrud.save}>
                  <div className="mb-3"><label className="form-label-custom">Category Name</label><input className="form-control form-control-custom" required value={categoryForm.CategoryName ?? ''} onChange={(e) => setCategoryForm((f) => ({ ...f, CategoryName: e.target.value }))} /></div>
                  <div className="mb-3"><label className="form-label-custom">Description</label><textarea className="form-control form-control-custom" rows={2} value={categoryForm.Description ?? ''} onChange={(e) => setCategoryForm((f) => ({ ...f, Description: e.target.value }))}></textarea></div>
                  <button type="submit" className="btn-primary-full btn-save"><i className="bi bi-check-circle"></i> Save Category</button>
                </form>
              </Modal>
            </>
          )}

          {activeTab === 'suppliers' && (
            <>
              <div className="row g-3 mb-3">
                <StatCard icon="bi-truck" color="blue" value={suppliers.length} label="Total Suppliers" />
                <StatCard icon="bi-cart-check-fill" color="green" value={suppliers.filter((s) => spareParts.some((p) => p.SupplierID === s.SupplierID)).length} label="Supplying Parts" />
                <StatCard icon="bi-box-seam-fill" color="purple" value={spareParts.length ? Math.round(spareParts.length / Math.max(suppliers.length, 1)) : 0} label="Avg. Parts / Supplier" />
              </div>
              <DataTable
                onRefresh={loadAll}
                title="Suppliers" icon="bi-truck" addLabel="Add Supplier" onAdd={supplierCrud.openAdd} searchPlaceholder="Search suppliers..."
                columns={[{ key: 'CompanyName', label: 'Supplier' }, { key: 'Phone', label: 'Phone' }, { key: 'Email', label: 'Email' }, { key: 'Address', label: 'Address' }]}
                rows={suppliers}
                renderActions={(r) => (
                  <>
                    <button className="btn-action view" title="View" onClick={() => viewSupplier.open(r)}><i className="bi bi-eye"></i></button>
                    <button className="btn-icon" title="Edit" onClick={() => supplierCrud.openEdit(r)}><i className="bi bi-pencil"></i></button>
                    <button className="btn-icon danger" title="Delete" onClick={() => supplierCrud.remove(r)}><i className="bi bi-trash"></i></button>
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
                ]}
              />
              <Modal id="supplierModal" title={supplierForm.SupplierID ? 'Edit Supplier' : 'Add Supplier'} icon="bi-truck">
                <form onSubmit={(e) => { e.preventDefault(); if (phoneError(supplierForm.Phone)) { showToast(phoneError(supplierForm.Phone), 'danger'); return; } supplierCrud.save(e); }}>
                  <div className="row g-3">
                    <div className="col-md-6"><label className="form-label-custom">Supplier Name</label><input className="form-control form-control-custom" required value={supplierForm.CompanyName ?? ''} onChange={(e) => setSupplierForm((f) => ({ ...f, CompanyName: e.target.value }))} /></div>
                    <div className="col-md-6">
                      <label className="form-label-custom">Phone</label>
                      <input
                        className={`form-control form-control-custom${supplierForm.Phone && phoneError(supplierForm.Phone) ? ' is-invalid' : ''}`}
                        required inputMode="numeric" maxLength={10} placeholder="07XXXXXXXX"
                        value={supplierForm.Phone ?? ''} onChange={(e) => setSupplierForm((f) => ({ ...f, Phone: digitsOnly(e.target.value) }))}
                      />
                      {supplierForm.Phone && phoneError(supplierForm.Phone) && <div className="invalid-feedback d-block">{phoneError(supplierForm.Phone)}</div>}
                    </div>
                    <div className="col-md-6"><label className="form-label-custom">Email</label><input type="email" className="form-control form-control-custom" value={supplierForm.Email ?? ''} onChange={(e) => setSupplierForm((f) => ({ ...f, Email: e.target.value }))} /></div>
                    <div className="col-12"><label className="form-label-custom">Address</label><input className="form-control form-control-custom" value={supplierForm.Address ?? ''} onChange={(e) => setSupplierForm((f) => ({ ...f, Address: e.target.value }))} /></div>
                  </div>
                  <button type="submit" className="btn-primary-full btn-save mt-3"><i className="bi bi-check-circle"></i> Save Supplier</button>
                </form>
              </Modal>
            </>
          )}

          {activeTab === 'purchases' && (
            <>
              <div className="row g-3 mb-3">
                <StatCard icon="bi-cart-check-fill" color="blue" value={purchases.length} label="Total Purchases" colClass="col-6 col-sm-6 col-lg-3" />
                <StatCard icon="bi-calendar-check" color="purple" value={purchases.filter((p) => isThisMonth(p.PurchaseDate)).length} label="This Month" colClass="col-6 col-sm-6 col-lg-3" />
                <StatCard icon="bi-cash-stack" color="green" value={`${purchases.reduce((sum, p) => sum + Number(p.TotalAmount || 0), 0).toLocaleString('en-US')} RWF`} label="Total Spent" colClass="col-6 col-sm-6 col-lg-3" />
                <StatCard icon="bi-box-seam" color="orange" value={purchases.reduce((sum, p) => sum + Number(p.Quantity || 0), 0)} label="Units Purchased" colClass="col-6 col-sm-6 col-lg-3" />
              </div>
              <DataTable
                onRefresh={loadAll}
                title="Purchase Orders" icon="bi-cart-check-fill" addLabel="Record Purchase" onAdd={openPurchase} searchPlaceholder="Search purchases..."
                columns={[
                  { key: 'SparePartID', label: 'Part', render: (r) => partName(r.SparePartID) },
                  { key: 'SupplierID', label: 'Supplier', render: (r) => r.SupplierName || supplierName(r.SupplierID) },
                  { key: 'Quantity', label: 'Quantity' },
                  { key: 'UnitPrice', label: 'Unit Cost' },
                  { key: 'TotalAmount', label: 'Total (RWF)', render: (r) => Number(r.TotalAmount || 0).toLocaleString('en-US') },
                  { key: 'PurchaseDate', label: 'Date' },
                  { key: 'Status', label: 'Status', render: (r) => <StatusBadge status={r.Status || 'Pending'} okValues={['Received', 'Processed', 'Approved']} /> },
                  { key: 'UserName', label: 'Recorded By', render: (r) => r.UserName || '-' },
                ]}
                rows={purchases}
                renderActions={(r) => (
                  <>
                    <button className="btn-action view" title="View" onClick={() => viewPurchase.open(r)}><i className="bi bi-eye"></i></button>
                    <button className="btn-action view" title="Print" onClick={() => { viewPurchase.open(r); setTimeout(() => printElementById('viewPurchaseModal-body', 'Purchase Details'), 300); }}><i className="bi bi-printer"></i></button>
                    <button className="btn-icon danger" title="Delete" onClick={async () => {
                      if (!(await ConfirmDelete('purchase record', `Purchase #${r.PurchaseID}`))) return;
                      const res = await inventoryApi.removePurchase(r.PurchaseID);
                      if (res.success) { showToast('Purchase removed.', 'success'); loadAll(); }
                    }}><i className="bi bi-trash"></i></button>
                  </>
                )}
              />
              <DetailsModal
                id="viewPurchaseModal" title="Purchase Details" icon="bi-cart-check-fill" printable
                fields={viewPurchase.row && [
                  { label: 'Purchase #', value: viewPurchase.row.PurchaseID },
                  { label: 'Part', value: partName(viewPurchase.row.SparePartID) },
                  { label: 'Supplier', value: viewPurchase.row.SupplierName || supplierName(viewPurchase.row.SupplierID) },
                  { label: 'Quantity', value: viewPurchase.row.Quantity },
                  { label: 'Unit Cost', value: `${Number(viewPurchase.row.UnitPrice || 0).toLocaleString('en-US')} RWF` },
                  { label: 'Total Amount', value: `${Number(viewPurchase.row.TotalAmount || 0).toLocaleString('en-US')} RWF` },
                  { label: 'Purchase Date', value: viewPurchase.row.PurchaseDate },
                  { label: 'Status', value: viewPurchase.row.Status },
                  { label: 'Recorded By', value: viewPurchase.row.UserName },
                ]}
              />
              <Modal id="purchaseModal" title="Record Purchase" icon="bi-cart-check-fill">
                <form onSubmit={savePurchase}>
                  <div className="row g-3">
                    <div className="col-md-6">
                      <label className="form-label-custom">Spare Part</label>
                      <select className="form-select form-control-custom" required value={purchaseForm.SparePartID ?? ''} onChange={(e) => setPurchaseForm((f) => ({ ...f, SparePartID: e.target.value }))}>
                        <option value="">Select part...</option>
                        {spareParts.map((p) => <option key={p.SparePartID} value={p.SparePartID}>{p.PartName}</option>)}
                      </select>
                    </div>
                    <div className="col-md-6">
                      <label className="form-label-custom">Supplier</label>
                      <select className="form-select form-control-custom" required value={purchaseForm.SupplierID ?? ''} onChange={(e) => setPurchaseForm((f) => ({ ...f, SupplierID: e.target.value }))}>
                        <option value="">Select supplier...</option>
                        {suppliers.map((s) => <option key={s.SupplierID} value={s.SupplierID}>{s.CompanyName}</option>)}
                      </select>
                    </div>
                    <div className="col-md-6"><label className="form-label-custom">Quantity</label><input type="number" min="1" className="form-control form-control-custom" required value={purchaseForm.Quantity ?? ''} onChange={(e) => setPurchaseForm((f) => ({ ...f, Quantity: e.target.value }))} /></div>
                    <div className="col-md-6"><label className="form-label-custom">Unit Cost (RWF)</label><input type="number" className="form-control form-control-custom" required value={purchaseForm.UnitPrice ?? ''} onChange={(e) => setPurchaseForm((f) => ({ ...f, UnitPrice: e.target.value }))} /></div>
                    <div className="col-md-6"><label className="form-label-custom">Purchase Date</label><input type="date" className="form-control form-control-custom" required max={todayStr()} value={purchaseForm.PurchaseDate ?? ''} onChange={(e) => setPurchaseForm((f) => ({ ...f, PurchaseDate: e.target.value }))} /></div>
                  </div>
                  <button type="submit" className="btn-primary-full btn-save mt-3"><i className="bi bi-check-circle"></i> Save Purchase</button>
                </form>
              </Modal>
            </>
          )}

          {activeTab === 'requests' && (
            <>
              <div className="row g-3 mb-3">
                <StatCard icon="bi-box-seam" color="blue" value={requests.length} label="Total Requests" colClass="col-6 col-sm-6 col-lg-3" />
                <StatCard icon="bi-hourglass-split" color="orange" value={requests.filter((r) => !r.Status || r.Status === 'Pending').length} label="Pending" colClass="col-6 col-sm-6 col-lg-3" />
                <StatCard icon="bi-check-circle-fill" color="green" value={requests.filter((r) => r.Status === 'Approved' || r.Status === 'Fulfilled').length} label="Approved" colClass="col-6 col-sm-6 col-lg-3" />
                <StatCard icon="bi-x-circle-fill" color="red" value={requests.filter((r) => r.Status === 'Rejected').length} label="Rejected" colClass="col-6 col-sm-6 col-lg-3" />
              </div>
              <DataTable
                onRefresh={loadAll}
                title="Mechanic Part Requests" icon="bi-box-seam" searchPlaceholder="Search requests..."
                columns={[
                  { key: 'MechanicName', label: 'Mechanic', render: (r) => r.MechanicName || '-' },
                  { key: 'JobID', label: 'Job', render: (r) => r.JobID || '-' },
                  { key: 'SparePartID', label: 'Part', render: (r) => r.SparePartName || partName(r.SparePartID) },
                  { key: 'QuantityRequested', label: 'Qty' },
                  { key: 'SparePartID_stock', label: 'Stock', render: (r) => partStock(r.SparePartID) },
                  { key: 'Reason', label: 'Reason', render: (r) => <TruncatedText text={r.Reason} limit={28} /> },
                  { key: 'Status', label: 'Status', render: (r) => <StatusBadge status={r.Status || 'Pending'} okValues={['Fulfilled', 'Approved']} lowValues={['Rejected']} /> },
                  { key: 'DecidedAt', label: 'Date', render: (r) => fmtDateTime(r.DecidedAt || r.RequestedAt) || '-' },
                ]}
                rows={requests}
                renderActions={(r) => (
                  <>
                    <button className="btn-action view" title="View" onClick={() => viewRequest.open(r)}><i className="bi bi-eye"></i></button>
                    {(!r.Status || r.Status === 'Pending') && (
                      <>
                        <button className="btn-icon" title="Approve" onClick={() => approveRequest(r)}><i className="bi bi-check-lg"></i></button>
                        <button className="btn-icon danger" title="Reject" onClick={() => rejectRequest(r)}><i className="bi bi-x-lg"></i></button>
                      </>
                    )}
                    {r.Status && r.Status !== 'Pending' && (
                      <button className="btn-icon danger" title="Delete" onClick={() => deleteRequest(r)}><i className="bi bi-trash"></i></button>
                    )}
                  </>
                )}
              />
              <DetailsModal
                id="viewRequestModal" title="Spare Part Request" icon="bi-box-seam"
                fields={viewRequest.row && [
                  { label: 'Part', value: viewRequest.row.SparePartName || partName(viewRequest.row.SparePartID) },
                  { label: 'Requested By', value: viewRequest.row.MechanicName },
                  { label: 'Job #', value: viewRequest.row.JobID },
                  { label: 'Vehicle Plate', value: viewRequest.row.JobPlate },
                  { label: 'Quantity Requested', value: viewRequest.row.QuantityRequested },
                  { label: 'Reason', value: viewRequest.row.Reason },
                  { label: 'Status', value: viewRequest.row.Status || 'Pending' },
                  { label: 'Decided At', value: fmtDateTime(viewRequest.row.DecidedAt) },
                ]}
              />
            </>
          )}

          {activeTab === 'transactions' && (
            <>
              <DataTable
                onRefresh={loadAll}
                title="Stock Movement History" icon="bi-clock-history" searchPlaceholder="Search stock log..."
                columns={[
                  { key: 'TransactionDate', label: 'Date', render: (r) => r.TransactionDate || fmtDateTime(r.CreatedAt) },
                  { key: 'SparePartID', label: 'Part', render: (r) => r.PartName || partName(r.SparePartID) },
                  { key: 'TransactionType', label: 'Type', render: (r) => <StatusBadge status={r.TransactionType} okValues={['Purchase', 'Restock']} lowValues={[]} /> },
                  {
                    key: 'Moved', label: 'Moved',
                    render: (r) => {
                      const moved = (r.AfterQty != null && r.BeforeQty != null) ? Number(r.AfterQty) - Number(r.BeforeQty) : null;
                      if (moved == null) return '-';
                      return <span style={{ fontWeight: 700, color: moved < 0 ? 'var(--danger, #dc2626)' : '#16a34a' }}>{moved > 0 ? `+${moved}` : moved}</span>;
                    },
                  },
                  { key: 'BeforeQty', label: 'Before', render: (r) => r.BeforeQty ?? '-' },
                  { key: 'AfterQty', label: 'After', render: (r) => r.AfterQty ?? '-' },
                  { key: 'UserName', label: 'User', render: (r) => <span style={{ color: 'var(--primary-blue)', fontWeight: 600 }}>{r.UserName || 'System'}</span> },
                ]}
                rows={transactions}
                renderActions={(r) => (
                  <>
                    <button className="btn-action view" title="View" onClick={() => viewTransaction.open(r)}><i className="bi bi-eye"></i></button>
                    <button className="btn-icon danger" title="Delete" onClick={() => deleteTransaction(r)}><i className="bi bi-trash"></i></button>
                  </>
                )}
              />
              <DetailsModal
                id="viewTransactionModal" title="Stock Transaction" icon="bi-journal-text"
                fields={viewTransaction.row && [
                  { label: 'Part', value: viewTransaction.row.PartName || partName(viewTransaction.row.SparePartID) },
                  { label: 'Type', value: viewTransaction.row.TransactionType },
                  { label: 'Quantity Change', value: viewTransaction.row.Quantity },
                  { label: 'Stock Before', value: viewTransaction.row.BeforeQty },
                  { label: 'Stock After', value: viewTransaction.row.AfterQty },
                  { label: 'Unit Price', value: viewTransaction.row.UnitPrice != null ? `${Number(viewTransaction.row.UnitPrice).toLocaleString('en-US')} RWF` : null },
                  { label: 'Performed By', value: viewTransaction.row.UserName },
                  { label: 'Date', value: fmtDateTime(viewTransaction.row.TransactionDate || viewTransaction.row.CreatedAt) },
                ]}
              />
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
