import React, { useCallback, useEffect, useMemo, useState } from 'react';
import '../../assets/staff.css';
import { DashboardShell, DataTable, Modal, DetailsModal, useViewModal, printElementById, StatCard, StatusBadge, showBsModal, hideBsModal, ConfirmDelete } from '../../components';
import { useAuth, useToast } from '../../context';
import { inventoryApi, notificationsApi, authApi } from '../../api';

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
  const [profileForm, setProfileForm] = useState({ full_name: user?.name || '', username: user?.username || '', email: user?.email || '', current_password: '', new_password: '', confirm_password: '' });

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
  const categoryName = (id) => categories.find((c) => c.CategoryID === id)?.CategoryName || id;
  const supplierName = (id) => suppliers.find((s) => s.SupplierID === id)?.CompanyName || id;
  const partName = (id) => spareParts.find((p) => p.SparePartID === id)?.PartName || id;

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
  const viewSupplier = useViewModal('viewSupplierModal');
  const viewPurchase = useViewModal('viewPurchaseModal');

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

  const saveProfile = async (e) => {
    e.preventDefault();
    const res = await authApi.updateProfile(profileForm);
    showToast(res.success ? 'Profile updated.' : res.message || 'Could not update profile.', res.success ? 'success' : 'danger');
  };

  const pageTitles = {
    dashboard: 'Dashboard', spareparts: 'Spare Parts', categories: 'Categories', suppliers: 'Suppliers',
    purchases: 'Purchases', requests: 'Part Requests', transactions: 'Stock Log', notifications: 'Notifications',
  };

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
    >
      {loading ? (
        <div className="text-center py-5"><span className="spinner-border" /></div>
      ) : (
        <>
          {activeTab === 'dashboard' && (
            <div className="row g-3">
              <StatCard icon="bi-boxes" color="blue" value={spareParts.length} label="Spare Parts" />
              <StatCard icon="bi-exclamation-triangle" color="orange" value={lowStockCount} label="Low Stock" />
              <StatCard icon="bi-truck" color="green" value={suppliers.length} label="Suppliers" />
              <StatCard icon="bi-box-seam" color="purple" value={requests.filter((r) => r.Status === 'Pending' || !r.Status).length} label="Pending Requests" />
            </div>
          )}

          {activeTab === 'spareparts' && (
            <>
              <DataTable
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
                    <button className="btn-icon" onClick={() => partCrud.openEdit(r)}><i className="bi bi-pencil"></i></button>
                    <button className="btn-icon danger" onClick={() => partCrud.remove(r)}><i className="bi bi-trash"></i></button>
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
                    <div className="col-md-6"><label className="form-label-custom">Part Name</label><input className="form-control form-control-custom" required value={partForm.PartName} onChange={(e) => setPartForm((f) => ({ ...f, PartName: e.target.value }))} /></div>
                    <div className="col-md-6">
                      <label className="form-label-custom">Category</label>
                      <select className="form-select form-control-custom" required value={partForm.CategoryID} onChange={(e) => setPartForm((f) => ({ ...f, CategoryID: e.target.value }))}>
                        <option value="">Select category...</option>
                        {categories.map((c) => <option key={c.CategoryID} value={c.CategoryID}>{c.CategoryName}</option>)}
                      </select>
                    </div>
                    <div className="col-md-6">
                      <label className="form-label-custom">Supplier</label>
                      <select className="form-select form-control-custom" required value={partForm.SupplierID} onChange={(e) => setPartForm((f) => ({ ...f, SupplierID: e.target.value }))}>
                        <option value="">Select supplier...</option>
                        {suppliers.map((s) => <option key={s.SupplierID} value={s.SupplierID}>{s.CompanyName}</option>)}
                      </select>
                    </div>
                    <div className="col-md-6"><label className="form-label-custom">Unit Price (RWF)</label><input type="number" className="form-control form-control-custom" required value={partForm.UnitPrice} onChange={(e) => setPartForm((f) => ({ ...f, UnitPrice: e.target.value }))} /></div>
                    <div className="col-md-6"><label className="form-label-custom">Quantity In Stock</label><input type="number" min="0" className="form-control form-control-custom" required value={partForm.Quantity} onChange={(e) => setPartForm((f) => ({ ...f, Quantity: e.target.value }))} /></div>
                    <div className="col-md-6"><label className="form-label-custom">Reorder Level</label><input type="number" min="0" className="form-control form-control-custom" required value={partForm.ReorderLevel} onChange={(e) => setPartForm((f) => ({ ...f, ReorderLevel: e.target.value }))} /></div>
                  </div>
                  <button type="submit" className="btn-primary-full btn-save mt-3"><i className="bi bi-check-circle"></i> Save Spare Part</button>
                </form>
              </Modal>
            </>
          )}

          {activeTab === 'categories' && (
            <>
              <DataTable
                title="Categories" icon="bi-tags-fill" addLabel="Add Category" onAdd={categoryCrud.openAdd} searchPlaceholder="Search categories..."
                columns={[{ key: 'CategoryName', label: 'Category Name' }, { key: 'Description', label: 'Description' }]}
                rows={categories}
                renderActions={(r) => (
                  <>
                    <button className="btn-icon" onClick={() => categoryCrud.openEdit(r)}><i className="bi bi-pencil"></i></button>
                    <button className="btn-icon danger" onClick={() => categoryCrud.remove(r)}><i className="bi bi-trash"></i></button>
                  </>
                )}
              />
              <Modal id="categoryModal" title={categoryForm.CategoryID ? 'Edit Category' : 'Add Category'} icon="bi-tags-fill">
                <form onSubmit={categoryCrud.save}>
                  <div className="mb-3"><label className="form-label-custom">Category Name</label><input className="form-control form-control-custom" required value={categoryForm.CategoryName} onChange={(e) => setCategoryForm((f) => ({ ...f, CategoryName: e.target.value }))} /></div>
                  <div className="mb-3"><label className="form-label-custom">Description</label><textarea className="form-control form-control-custom" rows={2} value={categoryForm.Description} onChange={(e) => setCategoryForm((f) => ({ ...f, Description: e.target.value }))}></textarea></div>
                  <button type="submit" className="btn-primary-full btn-save"><i className="bi bi-check-circle"></i> Save Category</button>
                </form>
              </Modal>
            </>
          )}

          {activeTab === 'suppliers' && (
            <>
              <DataTable
                title="Suppliers" icon="bi-truck" addLabel="Add Supplier" onAdd={supplierCrud.openAdd} searchPlaceholder="Search suppliers..."
                columns={[{ key: 'CompanyName', label: 'Supplier' }, { key: 'Phone', label: 'Phone' }, { key: 'Email', label: 'Email' }, { key: 'Address', label: 'Address' }]}
                rows={suppliers}
                renderActions={(r) => (
                  <>
                    <button className="btn-action view" title="View" onClick={() => viewSupplier.open(r)}><i className="bi bi-eye"></i></button>
                    <button className="btn-icon" onClick={() => supplierCrud.openEdit(r)}><i className="bi bi-pencil"></i></button>
                    <button className="btn-icon danger" onClick={() => supplierCrud.remove(r)}><i className="bi bi-trash"></i></button>
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
                <form onSubmit={supplierCrud.save}>
                  <div className="row g-3">
                    <div className="col-md-6"><label className="form-label-custom">Supplier Name</label><input className="form-control form-control-custom" required value={supplierForm.CompanyName} onChange={(e) => setSupplierForm((f) => ({ ...f, CompanyName: e.target.value }))} /></div>
                    <div className="col-md-6"><label className="form-label-custom">Phone</label><input className="form-control form-control-custom" required value={supplierForm.Phone} onChange={(e) => setSupplierForm((f) => ({ ...f, Phone: e.target.value }))} /></div>
                    <div className="col-md-6"><label className="form-label-custom">Email</label><input type="email" className="form-control form-control-custom" value={supplierForm.Email} onChange={(e) => setSupplierForm((f) => ({ ...f, Email: e.target.value }))} /></div>
                    <div className="col-12"><label className="form-label-custom">Address</label><input className="form-control form-control-custom" value={supplierForm.Address} onChange={(e) => setSupplierForm((f) => ({ ...f, Address: e.target.value }))} /></div>
                  </div>
                  <button type="submit" className="btn-primary-full btn-save mt-3"><i className="bi bi-check-circle"></i> Save Supplier</button>
                </form>
              </Modal>
            </>
          )}

          {activeTab === 'purchases' && (
            <>
              <DataTable
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
                    <button className="btn-icon danger" onClick={async () => {
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
                      <select className="form-select form-control-custom" required value={purchaseForm.SparePartID} onChange={(e) => setPurchaseForm((f) => ({ ...f, SparePartID: e.target.value }))}>
                        <option value="">Select part...</option>
                        {spareParts.map((p) => <option key={p.SparePartID} value={p.SparePartID}>{p.PartName}</option>)}
                      </select>
                    </div>
                    <div className="col-md-6">
                      <label className="form-label-custom">Supplier</label>
                      <select className="form-select form-control-custom" required value={purchaseForm.SupplierID} onChange={(e) => setPurchaseForm((f) => ({ ...f, SupplierID: e.target.value }))}>
                        <option value="">Select supplier...</option>
                        {suppliers.map((s) => <option key={s.SupplierID} value={s.SupplierID}>{s.CompanyName}</option>)}
                      </select>
                    </div>
                    <div className="col-md-6"><label className="form-label-custom">Quantity</label><input type="number" min="1" className="form-control form-control-custom" required value={purchaseForm.Quantity} onChange={(e) => setPurchaseForm((f) => ({ ...f, Quantity: e.target.value }))} /></div>
                    <div className="col-md-6"><label className="form-label-custom">Unit Cost (RWF)</label><input type="number" className="form-control form-control-custom" required value={purchaseForm.UnitPrice} onChange={(e) => setPurchaseForm((f) => ({ ...f, UnitPrice: e.target.value }))} /></div>
                    <div className="col-md-6"><label className="form-label-custom">Purchase Date</label><input type="date" className="form-control form-control-custom" required value={purchaseForm.PurchaseDate} onChange={(e) => setPurchaseForm((f) => ({ ...f, PurchaseDate: e.target.value }))} /></div>
                  </div>
                  <button type="submit" className="btn-primary-full btn-save mt-3"><i className="bi bi-check-circle"></i> Save Purchase</button>
                </form>
              </Modal>
            </>
          )}

          {activeTab === 'requests' && (
            <DataTable
              title="Spare Part Requests from Mechanics" icon="bi-box-seam" searchPlaceholder="Search requests..."
              columns={[
                { key: 'SparePartID', label: 'Part', render: (r) => partName(r.SparePartID) },
                { key: 'JobID', label: 'Job #' },
                { key: 'QuantityRequested', label: 'Qty' },
                { key: 'Status', label: 'Status', render: (r) => <StatusBadge status={r.Status || 'Pending'} /> },
              ]}
              rows={requests}
              renderActions={(r) => (
                (!r.Status || r.Status === 'Pending') ? (
                  <>
                    <button className="btn-icon" title="Approve" onClick={() => approveRequest(r)}><i className="bi bi-check-lg"></i></button>
                    <button className="btn-icon danger" title="Reject" onClick={() => rejectRequest(r)}><i className="bi bi-x-lg"></i></button>
                  </>
                ) : null
              )}
            />
          )}

          {activeTab === 'transactions' && (
            <DataTable
              title="Stock Movement Log" icon="bi-journal-text" searchPlaceholder="Search stock log..."
              columns={[
                { key: 'SparePartID', label: 'Part', render: (r) => partName(r.SparePartID) },
                { key: 'TransactionType', label: 'Type' },
                { key: 'Quantity', label: 'Quantity' },
                { key: 'CreatedAt', label: 'Date' },
              ]}
              rows={transactions}
            />
          )}

          {activeTab === 'notifications' && (
            <DataTable
              title="Notifications" icon="bi-bell-fill" searchPlaceholder="Search notifications..."
              addLabel="Mark All Read"
              onAdd={async () => { const r = await notificationsApi.markAllRead(); if (r.success) loadAll(); }}
              columns={[{ key: 'Title', label: 'Title' }, { key: 'Message', label: 'Message' }, { key: 'CreatedAt', label: 'Date' }]}
              rows={notifications}
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
    </DashboardShell>
  );
}
