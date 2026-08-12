import React, { useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { useAuth, useToast } from '../../context';
import { authApi, contactApi } from '../../api';
import './Login.css';

const ROLES = [
  { key: 'admin', label: 'Admin', icon: 'bi-shield-fill-check', apiRole: 'Admin' },
  { key: 'receptionist', label: 'Reception', icon: 'bi-person-badge', apiRole: 'Receptionist' },
  { key: 'mechanic', label: 'Mechanic', icon: 'bi-wrench', apiRole: 'Mechanic' },
  { key: 'stock', label: 'Stock', icon: 'bi-boxes', apiRole: 'Stock Manager' },
];

function closeModal(id) {
  const el = document.getElementById(id);
  if (window.bootstrap && el) {
    const instance = window.bootstrap.Modal.getInstance(el) || new window.bootstrap.Modal(el);
    instance.hide();
  }
}
function openModal(id) {
  const el = document.getElementById(id);
  if (window.bootstrap && el) window.bootstrap.Modal.getOrCreateInstance(el).show();
}

export default function Login() {
  const { login, verifyOtp, resendOtp, cancelOtp, dashboardPathFor } = useAuth();
  const { showToast } = useToast();
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const sessionExpired = searchParams.get('session') === 'expired';

  const [selectedRole, setSelectedRole] = useState('admin');
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [rememberMe, setRememberMe] = useState(false);
  const [showPassword, setShowPassword] = useState(false);
  const [loginError, setLoginError] = useState('');
  const [submitting, setSubmitting] = useState(false);

  const [otpCode, setOtpCode] = useState('');
  const [otpError, setOtpError] = useState('');
  const [otpSubmitting, setOtpSubmitting] = useState(false);

  const [forgotStep, setForgotStep] = useState(1);
  const [forgotUsername, setForgotUsername] = useState('');
  const [forgotEmail, setForgotEmail] = useState('');
  const [forgotOtp, setForgotOtp] = useState('');
  const [forgotNewPassword, setForgotNewPassword] = useState('');
  const [forgotConfirmPassword, setForgotConfirmPassword] = useState('');
  const [forgotError, setForgotError] = useState('');
  const [forgotBusy, setForgotBusy] = useState(false);

  const [contactForm, setContactForm] = useState({ name: '', email: '', phone: '', subject: 'Account Access Request', message: '' });
  const [contactBusy, setContactBusy] = useState(false);

  const goToDashboard = (role) => navigate(dashboardPathFor(role), { replace: true });

  const handleLogin = async (e) => {
    e.preventDefault();
    setLoginError('');
    if (!username.trim() || !password) {
      setLoginError('Please enter both username and password.');
      return;
    }
    setSubmitting(true);
    const roleObj = ROLES.find((r) => r.key === selectedRole);
    const result = await login(username.trim(), password, roleObj.apiRole, rememberMe);
    setSubmitting(false);
    if (result.requiresOtp) {
      openModal('otpModal');
      return;
    }
    if (result.success) {
      showToast('Signed in successfully.', 'success');
      goToDashboard(result.role);
    } else {
      setLoginError(result.message || 'Invalid credentials. Please try again.');
    }
  };

  const handleOtpVerify = async (e) => {
    e.preventDefault();
    setOtpError('');
    if (!/^\d{6}$/.test(otpCode)) {
      setOtpError('Enter the 6-digit code.');
      return;
    }
    setOtpSubmitting(true);
    const result = await verifyOtp(otpCode);
    setOtpSubmitting(false);
    if (result.success) {
      closeModal('otpModal');
      showToast('Signed in successfully.', 'success');
      goToDashboard(result.role);
    } else {
      setOtpError(result.message || 'Invalid or expired code.');
    }
  };

  const handleResendOtp = async (e) => {
    e.preventDefault();
    const res = await resendOtp();
    showToast(res.success ? 'A new code has been sent.' : res.message || 'Could not resend code.', res.success ? 'success' : 'danger');
  };

  const handleForgotStart = async (e) => {
    e.preventDefault();
    setForgotError('');
    setForgotBusy(true);
    const res = await authApi.forgotStart(forgotUsername, forgotEmail);
    setForgotBusy(false);
    if (res.success) setForgotStep(2);
    else setForgotError(res.message || 'Could not find that account.');
  };

  const handleForgotVerify = async (e) => {
    e.preventDefault();
    setForgotError('');
    setForgotBusy(true);
    const res = await authApi.forgotVerify(forgotUsername, forgotOtp);
    setForgotBusy(false);
    if (res.success) setForgotStep(3);
    else setForgotError(res.message || 'Invalid or expired code.');
  };

  const handleForgotReset = async (e) => {
    e.preventDefault();
    setForgotError('');
    if (forgotNewPassword !== forgotConfirmPassword) {
      setForgotError('Passwords do not match.');
      return;
    }
    setForgotBusy(true);
    const res = await authApi.forgotReset(forgotUsername, forgotNewPassword, forgotConfirmPassword);
    setForgotBusy(false);
    if (res.success) {
      showToast('Password reset. You can now sign in.', 'success');
      closeModal('forgotModal');
      setForgotStep(1);
      setForgotUsername('');
      setForgotEmail('');
      setForgotOtp('');
      setForgotNewPassword('');
      setForgotConfirmPassword('');
    } else {
      setForgotError(res.message || 'Could not reset password.');
    }
  };

  const handleForgotResend = async (e) => {
    e.preventDefault();
    const res = await authApi.forgotResend(forgotUsername);
    showToast(res.success ? 'A new code has been sent.' : res.message || 'Could not resend code.', res.success ? 'success' : 'danger');
  };

  const handleContactAdmin = async (e) => {
    e.preventDefault();
    setContactBusy(true);
    const res = await contactApi.send({
      full_name: contactForm.name,
      email: contactForm.email,
      phone: contactForm.phone,
      subject: contactForm.subject,
      message: contactForm.message,
    });
    setContactBusy(false);
    if (res.success) {
      showToast('Message sent to the admin team.', 'success');
      closeModal('contactAdminModal');
      setContactForm({ name: '', email: '', phone: '', subject: 'Account Access Request', message: '' });
    } else {
      showToast(res.message || 'Could not send message.', 'danger');
    }
  };

  return (
    <>
      <div className="auth-wrapper">
        <div className="auth-visual">
          <div className="auth-logo"><i className="bi bi-wrench-adjustable-circle-fill"></i> Garage<span style={{ fontWeight: 300 }}>Manager</span></div>
          <h2>Run your entire garage from one dashboard.</h2>
          <p>Sign in with your staff account to manage customers, vehicles, repair jobs, spare parts, invoices, and reports — all in real time.</p>
          <ul className="auth-points">
            <li><i className="bi bi-shield-lock-fill"></i> Secure, role-based access</li>
            <li><i className="bi bi-lightning-charge-fill"></i> Real-time job &amp; stock tracking</li>
            <li><i className="bi bi-bar-chart-line-fill"></i> Printable reports on demand</li>
          </ul>
        </div>

        <div className="auth-form-panel">
          <Link to="/" className="back-home"><i className="bi bi-arrow-left"></i> Back to Home</Link>

          <div className="auth-card">
            <div className="d-flex align-items-center gap-2 mb-1">
              <div style={{ width: 42, height: 42, borderRadius: 12, background: 'var(--primary-blue)', display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}>
                <i className="bi bi-wrench-adjustable-circle-fill" style={{ color: '#fff', fontSize: '1.3rem' }}></i>
              </div>
              <h3 className="mb-0">Welcome Back</h3>
            </div>
            <p className="auth-subtitle">Select your role and sign in to continue</p>

            <div className="role-selector" id="roleSelector">
              {ROLES.map((r) => (
                <div key={r.key} className={`role-chip${selectedRole === r.key ? ' active' : ''}`} onClick={() => setSelectedRole(r.key)}>
                  <i className={`bi ${r.icon}`}></i> {r.label}
                </div>
              ))}
            </div>

            {loginError && (
              <div className="login-alert show danger">
                <i className="bi bi-exclamation-circle-fill"></i>
                <span>{loginError}</span>
              </div>
            )}

            {sessionExpired && (
              <div className="login-alert show danger">
                <i className="bi bi-exclamation-circle-fill"></i>
                <span>Your session has expired. Please log in again.</span>
              </div>
            )}

            <form onSubmit={handleLogin} noValidate>
              <div className="mb-3">
                <label className="form-label-custom" htmlFor="loginUsername">Username</label>
                <input
                  type="text"
                  className="form-control form-control-custom"
                  id="loginUsername"
                  placeholder="Enter your username"
                  value={username}
                  onChange={(e) => setUsername(e.target.value)}
                  autoFocus
                />
              </div>

              <div className="mb-3">
                <label className="form-label-custom" htmlFor="loginPassword">Password</label>
                <div className="input-group-custom">
                  <input
                    type={showPassword ? 'text' : 'password'}
                    className="form-control form-control-custom"
                    id="loginPassword"
                    placeholder="••••••••"
                    style={{ paddingRight: '2.6rem' }}
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                  />
                  <i className={`bi ${showPassword ? 'bi-eye-slash' : 'bi-eye'} password-toggle`} onClick={() => setShowPassword((s) => !s)}></i>
                </div>
              </div>

              <div className="d-flex justify-content-between align-items-center mb-3">
                <div className="form-check">
                  <input type="checkbox" className="form-check-input" id="rememberMe" checked={rememberMe} onChange={(e) => setRememberMe(e.target.checked)} />
                  <label className="form-check-label" htmlFor="rememberMe" style={{ fontSize: '0.85rem', color: 'var(--text-muted)' }}>Remember me</label>
                </div>
                <a href="#top" className="forgot-link" data-bs-toggle="modal" data-bs-target="#forgotModal">Forgot password?</a>
              </div>

              <button type="submit" className="btn-primary-full" disabled={submitting}>
                {submitting ? <><span className="spinner-border spinner-border-sm" /> Signing In...</> : <><i className="bi bi-box-arrow-in-right"></i> Sign In</>}
              </button>
            </form>

            <div className="text-center signup-text">
              Don't have an account? <a href="#top" data-bs-toggle="modal" data-bs-target="#contactAdminModal">Contact Admin</a>
            </div>
          </div>
        </div>
      </div>

      {/* Forgot Password Modal */}
      <div className="modal fade" id="forgotModal" tabIndex="-1" data-bs-backdrop="static">
        <div className="modal-dialog modal-dialog-centered">
          <div className="modal-content">
            <div className="modal-header">
              <h5 className="modal-title"><i className="bi bi-key-fill"></i> Reset Password</h5>
              <button type="button" className="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div className="modal-body">
              {forgotError && (
                <div className="login-alert show danger">
                  <i className="bi bi-exclamation-circle-fill"></i>
                  <span>{forgotError}</span>
                </div>
              )}

              {forgotStep === 1 && (
                <div>
                  <p className="text-muted mb-3">Enter your username and registered email to receive a one-time verification code.</p>
                  <form onSubmit={handleForgotStart}>
                    <div className="mb-3">
                      <label className="form-label-custom">Username</label>
                      <input type="text" className="form-control form-control-custom" required value={forgotUsername} onChange={(e) => setForgotUsername(e.target.value)} />
                    </div>
                    <div className="mb-3">
                      <label className="form-label-custom">Registered Email</label>
                      <input type="email" className="form-control form-control-custom" required value={forgotEmail} onChange={(e) => setForgotEmail(e.target.value)} />
                    </div>
                    <button type="submit" className="btn-primary-full btn-save" disabled={forgotBusy}><i className="bi bi-send"></i> Send Code</button>
                  </form>
                </div>
              )}

              {forgotStep === 2 && (
                <div>
                  <p className="text-muted mb-3">Enter the 6-digit code sent to your email.</p>
                  <form onSubmit={handleForgotVerify}>
                    <div className="mb-3">
                      <label className="form-label-custom">Verification Code</label>
                      <input
                        type="text"
                        className="form-control form-control-custom"
                        maxLength={6}
                        inputMode="numeric"
                        placeholder="000000"
                        required
                        style={{ letterSpacing: 6, fontSize: '1.2rem', textAlign: 'center' }}
                        value={forgotOtp}
                        onChange={(e) => setForgotOtp(e.target.value)}
                      />
                    </div>
                    <button type="submit" className="btn-primary-full btn-save" disabled={forgotBusy}><i className="bi bi-check-circle"></i> Verify Code</button>
                  </form>
                  <div className="text-center signup-text mt-2">
                    <a href="#top" onClick={handleForgotResend}>Resend code</a>
                  </div>
                </div>
              )}

              {forgotStep === 3 && (
                <div>
                  <p className="text-muted mb-3">Choose a new password for your account.</p>
                  <form onSubmit={handleForgotReset}>
                    <div className="mb-3">
                      <label className="form-label-custom">New Password</label>
                      <input type="password" className="form-control form-control-custom" required minLength={8} value={forgotNewPassword} onChange={(e) => setForgotNewPassword(e.target.value)} />
                    </div>
                    <div className="mb-3">
                      <label className="form-label-custom">Confirm New Password</label>
                      <input type="password" className="form-control form-control-custom" required minLength={8} value={forgotConfirmPassword} onChange={(e) => setForgotConfirmPassword(e.target.value)} />
                    </div>
                    <button type="submit" className="btn-primary-full btn-save" disabled={forgotBusy}><i className="bi bi-shield-check"></i> Reset Password</button>
                  </form>
                </div>
              )}
            </div>
          </div>
        </div>
      </div>

      {/* Contact Admin Modal */}
      <div className="modal fade" id="contactAdminModal" tabIndex="-1">
        <div className="modal-dialog modal-dialog-centered">
          <div className="modal-content">
            <div className="modal-header">
              <h5 className="modal-title"><i className="bi bi-envelope-fill"></i> Contact Admin</h5>
              <button type="button" className="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div className="modal-body">
              <p className="text-muted mb-3">Send a message to the admin to request account access or support.</p>
              <form onSubmit={handleContactAdmin}>
                <div className="mb-3">
                  <label className="form-label-custom">Full Name</label>
                  <input type="text" className="form-control form-control-custom" required value={contactForm.name} onChange={(e) => setContactForm((f) => ({ ...f, name: e.target.value }))} />
                </div>
                <div className="mb-3">
                  <label className="form-label-custom">Email</label>
                  <input type="email" className="form-control form-control-custom" required value={contactForm.email} onChange={(e) => setContactForm((f) => ({ ...f, email: e.target.value }))} />
                </div>
                <div className="mb-3">
                  <label className="form-label-custom">Phone (optional)</label>
                  <input type="tel" className="form-control form-control-custom" value={contactForm.phone} onChange={(e) => setContactForm((f) => ({ ...f, phone: e.target.value }))} />
                </div>
                <div className="mb-3">
                  <label className="form-label-custom">Subject</label>
                  <select className="form-select form-control-custom" required value={contactForm.subject} onChange={(e) => setContactForm((f) => ({ ...f, subject: e.target.value }))}>
                    <option>Account Access Request</option>
                    <option>Technical Support</option>
                    <option>General Inquiry</option>
                  </select>
                </div>
                <div className="mb-3">
                  <label className="form-label-custom">Message</label>
                  <textarea className="form-control form-control-custom" rows={3} required value={contactForm.message} onChange={(e) => setContactForm((f) => ({ ...f, message: e.target.value }))}></textarea>
                </div>
                <button type="submit" className="btn-primary-full w-100" disabled={contactBusy}><i className="bi bi-send"></i> Send Message</button>
              </form>
            </div>
          </div>
        </div>
      </div>

      {/* OTP Verification Modal */}
      <div className="modal fade" id="otpModal" tabIndex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
        <div className="modal-dialog modal-dialog-centered">
          <div className="modal-content">
            <div className="modal-header">
              <h5 className="modal-title"><i className="bi bi-shield-lock-fill"></i> Verify Your Identity</h5>
            </div>
            <div className="modal-body">
              <p className="text-muted mb-3">We've sent a 6-digit code to your email. Enter it below to continue.</p>
              {otpError && (
                <div className="login-alert show danger">
                  <i className="bi bi-exclamation-circle-fill"></i>
                  <span>{otpError}</span>
                </div>
              )}
              <form onSubmit={handleOtpVerify}>
                <div className="mb-3">
                  <label className="form-label-custom">Verification Code</label>
                  <input
                    type="text"
                    className="form-control form-control-custom"
                    maxLength={6}
                    inputMode="numeric"
                    placeholder="000000"
                    required
                    autoFocus
                    style={{ letterSpacing: 6, fontSize: '1.3rem', textAlign: 'center' }}
                    value={otpCode}
                    onChange={(e) => setOtpCode(e.target.value)}
                  />
                </div>
                <button type="submit" className="btn-primary-full w-100" disabled={otpSubmitting}><i className="bi bi-check-circle"></i> Verify &amp; Sign In</button>
              </form>
              <div className="text-center signup-text mt-2">
                <a href="#top" onClick={handleResendOtp}>Resend code</a>
                <span className="mx-2">|</span>
                <a href="#top" onClick={(e) => { e.preventDefault(); cancelOtp(); closeModal('otpModal'); }}>Cancel</a>
              </div>
            </div>
          </div>
        </div>
      </div>
    </>
  );
}
