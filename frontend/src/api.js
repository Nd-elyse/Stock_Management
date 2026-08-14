import axios from 'axios';

/* ============================================================
   AXIOS CLIENT + TOKEN AUTH
   ------------------------------------------------------------
   The Laravel backend uses Sanctum bearer tokens instead of PHP
   sessions/CSRF, so every write is authenticated with an
   `Authorization: Bearer <token>` header instead of a CSRF token +
   session cookie. The token is issued by /auth/verify-otp and kept in
   localStorage so it survives a page refresh (mirrors sessionStorage
   usage in context.jsx for the user object).
   ============================================================ */

const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || '/api';
const TOKEN_KEY = 'gm_auth_token';

export function getToken() {
  return localStorage.getItem(TOKEN_KEY) || '';
}

export function setToken(token) {
  if (token) localStorage.setItem(TOKEN_KEY, token);
  else localStorage.removeItem(TOKEN_KEY);
}

const client = axios.create({
  baseURL: API_BASE_URL,
  headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
});

client.interceptors.request.use((config) => {
  const token = getToken();
  if (token) {
    config.headers = config.headers || {};
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

client.interceptors.response.use(
  (response) => response,
  (error) => {
    // A 401 means the token is missing/expired - clear it so the app
    // routes back to the login screen instead of looping API calls.
    if (error.response && error.response.status === 401) {
      setToken('');
    }
    return Promise.reject(error);
  }
);

/** Always resolves to the parsed JSON body ({ success, message, data, ... }). */
/**
 * The React forms across this app were built using the database's own
 * PascalCase column names as form field keys (FullName, CustomerID, ...),
 * but the Laravel controllers validate snake_case request bodies
 * (full_name, customer_id, ...) - standard Laravel convention. Rather
 * than rewrite every form/field in the app (which would risk changing
 * behaviour/UX), outgoing write payloads are auto-converted here.
 * GET responses are untouched - Eloquent already returns the original
 * PascalCase column names, which is what these components expect.
 */
function toSnakeCase(value) {
  if (Array.isArray(value)) return value.map(toSnakeCase);
  if (value && typeof value === 'object' && !(value instanceof File) && !(value instanceof Blob)) {
    return Object.fromEntries(
      Object.entries(value).map(([k, v]) => [
        k.replace(/([a-z0-9])([A-Z])/g, '$1_$2').replace(/([A-Z]+)([A-Z][a-z])/g, '$1_$2').toLowerCase(),
        toSnakeCase(v),
      ])
    );
  }
  return value;
}

async function request(method, url, data, params) {
  try {
    const body = ['post', 'put', 'patch'].includes(method) ? toSnakeCase(data) : data;
    const res = await client.request({ method, url, data: body, params });
    return res.data;
  } catch (err) {
    if (err.response && err.response.data) return err.response.data;
    return { success: false, message: 'Network error. Please check your connection and try again.' };
  }
}

/* ============================================================
   AUTH  (/api/auth/*)
   ============================================================ */
export const authApi = {
  login: (username, password) => request('post', '/auth/login', { username, password }),
  verifyOtp: (username, otp) => request('post', '/auth/verify-otp', { username, otp }),
  resendOtp: (username) => request('post', '/auth/resend-otp', { username }),
  cancelOtp: (username) => request('post', '/auth/cancel-otp', { username }),
  renewSession: () => request('post', '/auth/session-renew'),
  forgotStart: (username, email) => request('post', '/auth/forgot-password', { username, email }),
  forgotResend: (username) => request('post', '/auth/forgot-resend-otp', { username }),
  forgotVerify: (username, otp) => request('post', '/auth/forgot-verify-otp', { username, otp }),
  forgotReset: (username, password, confirm) => request('post', '/auth/forgot-reset-password', { username, password, confirm }),
  logout: () => request('post', '/auth/logout'),
  me: () => request('get', '/me'),
  updateProfile: (payload) => request('put', '/auth/profile', payload),
};

/* ============================================================
   USERS  (/api/users) - Admin only
   ============================================================ */
export const usersApi = {
  list: () => request('get', '/users'),
  save: (payload) => request(payload.UserID ? 'put' : 'post', payload.UserID ? `/users/${payload.UserID}` : '/users', payload),
  remove: (id) => request('delete', `/users/${id}`),
};

/* ============================================================
   JOBS  (/api/jobs, /api/mechanics)
   ============================================================ */
export const jobsApi = {
  listMechanics: () => request('get', '/mechanics'),
  getMechanic: (id) => request('get', '/mechanics', null, { id }),
  saveMechanic: (payload) => request(payload.MechanicID ? 'put' : 'post', payload.MechanicID ? `/mechanics/${payload.MechanicID}` : '/mechanics', payload),
  removeMechanic: (id) => request('delete', `/mechanics/${id}`),

  listJobs: () => request('get', '/jobs'),
  getJob: (id) => request('get', '/jobs', null, { id }),
  saveJob: (payload) => request(payload.JobID ? 'put' : 'post', payload.JobID ? `/jobs/${payload.JobID}` : '/jobs', payload),
  removeJob: (id) => request('delete', `/jobs/${id}`),

  saveDiagnostics: (jobId, payload) => request('post', `/jobs/${jobId}/diagnostics`, payload),
};

/* ============================================================
   CUSTOMERS + VEHICLES  (/api/customers, /api/vehicles)
   ============================================================ */
export const customersApi = {
  listCustomers: () => request('get', '/customers'),
  getCustomer: (id) => request('get', '/customers', null, { id }),
  saveCustomer: (payload) => request(payload.CustomerID ? 'put' : 'post', payload.CustomerID ? `/customers/${payload.CustomerID}` : '/customers', payload),
  removeCustomer: (id) => request('delete', `/customers/${id}`),

  listVehicles: () => request('get', '/vehicles'),
  getVehicle: (id) => request('get', '/vehicles', null, { id }),
  saveVehicle: (payload) => request(payload.VehicleID ? 'put' : 'post', payload.VehicleID ? `/vehicles/${payload.VehicleID}` : '/vehicles', payload),
  removeVehicle: (id) => request('delete', `/vehicles/${id}`),
};

/* ============================================================
   INVENTORY  (/api/spare-parts, /api/categories, /api/suppliers,
   /api/purchases, /api/spare-part-requests, /api/stock-transactions)
   ============================================================ */
export const inventoryApi = {
  listSpareParts: () => request('get', '/spare-parts'),
  saveSparePart: (payload) => request(payload.SparePartID ? 'put' : 'post', payload.SparePartID ? `/spare-parts/${payload.SparePartID}` : '/spare-parts', payload),
  removeSparePart: (id) => request('delete', `/spare-parts/${id}`),
  adjustStock: (id, payload) => request('post', `/spare-parts/${id}/adjust`, payload),

  listCategories: () => request('get', '/categories'),
  saveCategory: (payload) => request(payload.CategoryID ? 'put' : 'post', payload.CategoryID ? `/categories/${payload.CategoryID}` : '/categories', payload),
  removeCategory: (id) => request('delete', `/categories/${id}`),

  listSuppliers: () => request('get', '/suppliers'),
  saveSupplier: (payload) => request(payload.SupplierID ? 'put' : 'post', payload.SupplierID ? `/suppliers/${payload.SupplierID}` : '/suppliers', payload),
  removeSupplier: (id) => request('delete', `/suppliers/${id}`),

  listPurchases: () => request('get', '/purchases'),
  savePurchase: (payload) => request('post', '/purchases', payload),
  removePurchase: (id) => request('delete', `/purchases/${id}`),

  listSparePartRequests: () => request('get', '/spare-part-requests'),
  saveSparePartRequest: (payload) => request('post', '/spare-part-requests', payload),
  approveSparePartRequest: (id) => request('put', `/spare-part-requests/${id}/approve`),
  rejectSparePartRequest: (id, reason) => request('put', `/spare-part-requests/${id}/reject`, { reason }),
  removeSparePartRequest: (id) => request('delete', `/spare-part-requests/${id}`),

  listStockTransactions: () => request('get', '/stock-transactions'),
  removeStockTransaction: (id) => request('delete', `/stock-transactions/${id}`),
};

/* ============================================================
   BILLING  (/api/invoices, /api/payments)
   ============================================================ */
export const billingApi = {
  listInvoices: () => request('get', '/invoices'),
  getInvoice: (id) => request('get', '/invoices', null, { id }),
  saveInvoice: (payload) => request(payload.InvoiceID ? 'put' : 'post', payload.InvoiceID ? `/invoices/${payload.InvoiceID}` : '/invoices', payload),
  removeInvoice: (id) => request('delete', `/invoices/${id}`),

  listPayments: () => request('get', '/payments'),
  getPayment: (id) => request('get', '/payments', null, { id }),
  savePayment: (payload) => request(payload.PaymentID ? 'put' : 'post', payload.PaymentID ? `/payments/${payload.PaymentID}` : '/payments', payload),
  removePayment: (id) => request('delete', `/payments/${id}`),
};

/* ============================================================
   NOTIFICATIONS + CONTACT MESSAGES + PUBLIC STATS
   ============================================================ */
export const notificationsApi = {
  listAll: () => request('get', '/notifications', null, { scope: 'all' }),
  save: (payload) => request(payload.NotificationID ? 'put' : 'post', payload.NotificationID ? `/notifications/${payload.NotificationID}` : '/notifications', payload),
  markRead: (id) => request('put', `/notifications/${id}/mark-read`),
  markAllRead: () => request('put', '/notifications/mark-all-read'),
  remove: (id) => request('delete', `/notifications/${id}`),
};

export const contactApi = {
  send: (payload) => request('post', '/contact-messages', payload),
  list: () => request('get', '/contact-messages'),
  markAllRead: () => request('put', '/contact-messages/mark-all-read'),
  markRead: (id) => request('put', `/contact-messages/${id}/mark-read`),
  remove: (id) => request('delete', `/contact-messages/${id}`),
};

export const passwordResetTicketsApi = {
  submit: (username, note) => request('post', '/password-resets', { username, note }),
  list: () => request('get', '/password-resets'),
  resolve: (id) => request('put', `/password-resets/${id}/resolve`),
  remove: (id) => request('delete', `/password-resets/${id}`),
};

export const statsApi = {
  getPublicStats: () => request('get', '/stats/public'),
  getDashboardStats: () => request('get', '/stats/dashboard'),
};

export const trackRepairApi = {
  lookup: (fullName, plateNumber) => request('post', '/track-repair', { full_name: fullName, plate_number: plateNumber }),
};

export default client;
