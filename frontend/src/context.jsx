import React, { createContext, useCallback, useContext, useRef, useState } from 'react';
import { authApi, setToken } from './api';

/* ============================================================
   AUTH CONTEXT
   ------------------------------------------------------------
   Updated for the Laravel/Sanctum backend: login is now a two-step
   username/password -> OTP flow that ends with a bearer token (stored
   via setToken/api.js) instead of a PHP session cookie.
   ============================================================ */
const AuthContext = createContext(null);
const STORAGE_KEY = 'gm_auth_user';

const ROLE_PATHS = {
  Admin: '/dashboard/admin',
  Receptionist: '/dashboard/receptionist',
  Mechanic: '/dashboard/mechanic',
  'Stock Manager': '/dashboard/stock',
};

export function AuthProvider({ children }) {
  const [user, setUser] = useState(() => {
    try {
      const raw = sessionStorage.getItem(STORAGE_KEY);
      return raw ? JSON.parse(raw) : null;
    } catch {
      return null;
    }
  });
  const [ready, setReady] = useState(true);
  const pendingUsernameRef = useRef('');

  const persist = (value) => {
    setUser(value);
    if (value) sessionStorage.setItem(STORAGE_KEY, JSON.stringify(value));
    else sessionStorage.removeItem(STORAGE_KEY);
  };

  const login = useCallback(async (username, password) => {
    const res = await authApi.login(username, password);
    if (res.success && res.otp_required) {
      pendingUsernameRef.current = res.username || username;
      return { requiresOtp: true, message: res.message };
    }
    return { success: false, message: res.message || 'Invalid credentials. Please try again.' };
  }, []);

  const verifyOtp = useCallback(async (otp) => {
    const res = await authApi.verifyOtp(pendingUsernameRef.current, otp);
    if (res.success && res.token) {
      setToken(res.token);
      persist({
        id: res.user.id,
        name: res.user.full_name,
        username: res.user.username,
        email: res.user.email,
        phone: res.user.phone,
        role: res.user.role,
        mechanicId: res.user.mechanic_id,
      });
      return { success: true, role: res.user.role };
    }
    return { success: false, message: res.message || 'Invalid or expired code.' };
  }, []);

  const resendOtp = useCallback(() => authApi.resendOtp(pendingUsernameRef.current), []);
  const cancelOtp = useCallback(() => authApi.cancelOtp(pendingUsernameRef.current), []);

  const logout = useCallback(async () => {
    try {
      await authApi.logout();
    } finally {
      setToken('');
      persist(null);
    }
  }, []);

  const dashboardPathFor = (role) => ROLE_PATHS[role] || '/login';

  return (
    <AuthContext.Provider value={{ user, ready, login, verifyOtp, resendOtp, cancelOtp, logout, dashboardPathFor, setUser: persist }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used within AuthProvider');
  return ctx;
}

/* ============================================================
   TOAST CONTEXT - reproduces showToast() from the original main.js
   (unchanged from the previous PHP-backed version)
   ============================================================ */
const ToastContext = createContext(null);
const ICONS = {
  success: 'bi-check-circle-fill',
  danger: 'bi-x-circle-fill',
  warning: 'bi-exclamation-triangle-fill',
  info: 'bi-info-circle-fill',
};

export function ToastProvider({ children }) {
  const [toasts, setToasts] = useState([]);
  const idRef = useRef(0);

  const showToast = useCallback((message, type = 'success') => {
    const id = ++idRef.current;
    setToasts((prev) => [...prev, { id, message, type }]);
    setTimeout(() => setToasts((prev) => prev.filter((t) => t.id !== id)), 4000);
  }, []);

  return (
    <ToastContext.Provider value={{ showToast }}>
      {children}
      <div className="toast-container position-fixed top-0 end-0 p-3" style={{ zIndex: 9999 }}>
        {toasts.map((t) => (
          <div key={t.id} className={`toast show align-items-center text-white bg-${t.type} border-0 mb-2`} role="alert">
            <div className="d-flex">
              <div className="toast-body">
                <i className={`bi ${ICONS[t.type] || ICONS.info} me-2`}></i>
                {t.message}
              </div>
              <button type="button" className="btn-close btn-close-white me-2 m-auto" onClick={() => setToasts((prev) => prev.filter((x) => x.id !== t.id))}></button>
            </div>
          </div>
        ))}
      </div>
    </ToastContext.Provider>
  );
}

export function useToast() {
  const ctx = useContext(ToastContext);
  if (!ctx) throw new Error('useToast must be used within ToastProvider');
  return ctx;
}
