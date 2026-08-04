<?php
require_once __DIR__ . '/../../backend/includes/csrf.php';
$csrf_token = generate_csrf_token();

// Check for session expired parameter
$sessionExpired = isset($_GET['session']) && $_GET['session'] === 'expired';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES); ?>" />
    <title>Login | GarageManager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="../style.css" />
</head>
<body>

<div id="pageLoader"><i class="bi bi-wrench-adjustable-circle-fill loader-icon"></i></div>

<div class="auth-wrapper">

    <!-- LEFT BRAND / VALUE PANEL (hidden on small screens) -->
    <div class="auth-visual">
        <div class="auth-logo"><i class="bi bi-wrench-adjustable-circle-fill"></i> Garage<span style="font-weight:300;">Manager</span></div>
        <h2>Run your entire garage from one dashboard.</h2>
        <p>Sign in with your staff account to manage customers, vehicles, repair jobs, spare parts, invoices, and reports — all in real time.</p>
        <ul class="auth-points">
            <li><i class="bi bi-shield-lock-fill"></i> Secure, role-based access</li>
            <li><i class="bi bi-lightning-charge-fill"></i> Real-time job &amp; stock tracking</li>
            <li><i class="bi bi-bar-chart-line-fill"></i> Printable reports on demand</li>
        </ul>
    </div>

    <!-- RIGHT LOGIN FORM PANEL -->
    <div class="auth-form-panel">
        <a href="../index.php" class="back-home"><i class="bi bi-arrow-left"></i> Back to Home</a>

        <div class="auth-card">
            <div class="d-flex align-items-center gap-2 mb-1">
                <div style="width:42px;height:42px;border-radius:12px;background:var(--primary-blue);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="bi bi-wrench-adjustable-circle-fill" style="color:#fff;font-size:1.3rem;"></i>
                </div>
                <h3 class="mb-0">Welcome Back</h3>
            </div>
            <p class="auth-subtitle">Select your role and sign in to continue</p>

            <!-- ROLE SELECTOR -->
            <div class="role-selector" id="roleSelector">
                <div class="role-chip active" data-role="admin"><i class="bi bi-shield-fill-check"></i> Admin</div>
                <div class="role-chip" data-role="receptionist"><i class="bi bi-person-badge"></i> Reception</div>
                <div class="role-chip" data-role="mechanic"><i class="bi bi-wrench"></i> Mechanic</div>
                <div class="role-chip" data-role="stock"><i class="bi bi-boxes"></i> Stock</div>
            </div>

            <!-- ALERT MESSAGE -->
            <div class="login-alert" id="loginAlert">
                <i class="bi bi-exclamation-circle-fill"></i>
                <span id="alertMessage">Invalid credentials. Please try again.</span>
            </div>

            <?php if ($sessionExpired): ?>
            <div class="login-alert show danger" id="sessionExpiredAlert">
                <i class="bi bi-exclamation-circle-fill"></i>
                <span>Your session has expired. Please log in again.</span>
            </div>
            <?php endif; ?>

            <!-- LOGIN FORM -->
            <form id="loginForm" onsubmit="handleLogin(event)" novalidate>
                <div class="mb-3">
                    <label class="form-label-custom" for="loginUsername">Username</label>
                    <input type="text" class="form-control form-control-custom" id="loginUsername" placeholder="Enter your username" required autofocus data-allow-numeric="true" pattern="[a-zA-Z0-9_]+" title="Username can contain letters, numbers, and underscores" />
                </div>

                <div class="mb-3">
                    <label class="form-label-custom" for="loginPassword">Password</label>
                    <div class="input-group-custom">
                        <input type="password" class="form-control form-control-custom" id="loginPassword" placeholder="••••••••" required style="padding-right:2.6rem;" />
                        <i class="bi bi-eye password-toggle" id="passwordToggle" onclick="togglePassword()"></i>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="rememberMe" />
                        <label class="form-check-label" for="rememberMe" style="font-size:0.85rem;color:var(--text-muted);">Remember me</label>
                    </div>
                    <a href="#" class="forgot-link" onclick="openForgotPasswordModal(event)" data-bs-toggle="modal" data-bs-target="#forgotModal">Forgot password?</a>
                </div>

                <button type="submit" class="btn-primary-full"><i class="bi bi-box-arrow-in-right"></i> Sign In</button>
            </form>

            <div class="text-center signup-text">
                Don't have an account? <a href="#" data-bs-toggle="modal" data-bs-target="#contactAdminModal">Contact Admin</a>
            </div>
        </div>
    </div>
</div>

<!-- Forgot Password Modal (multi-step) -->
<div class="modal fade" id="forgotModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-key-fill"></i> Reset Password</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="login-alert" id="forgotAlert">
                    <i class="bi bi-exclamation-circle-fill"></i>
                    <span id="forgotAlertMessage"></span>
                </div>

                <!-- STEP 1: Username + Email -->
                <div id="forgotStep1">
                    <p class="text-muted mb-3">Enter your username and registered email to receive a one-time verification code.</p>
                    <form id="forgotStartForm" onsubmit="handleForgotStart(event)">
                        <div class="mb-3">
                            <label class="form-label-custom">Username</label>
                            <input type="text" id="forgotUsername" class="form-control form-control-custom" required />
                        </div>
                        <div class="mb-3">
                            <label class="form-label-custom">Registered Email</label>
                            <input type="email" id="forgotEmail" class="form-control form-control-custom" required />
                        </div>
                        <button type="submit" class="btn-primary-full btn-save"><i class="bi bi-send"></i> Send Code</button>
                    </form>
                </div>

                <!-- STEP 2: OTP -->
                <div id="forgotStep2" style="display:none;">
                    <p class="text-muted mb-3">Enter the 6-digit code sent to your email.</p>
                    <form id="forgotVerifyForm" onsubmit="handleForgotVerify(event)">
                        <div class="mb-3">
                            <label class="form-label-custom">Verification Code</label>
                            <input type="text" id="forgotOtp" class="form-control form-control-custom" maxlength="6"
                                   inputmode="numeric" pattern="[0-9]*" placeholder="000000" required
                                   data-allow-numeric="true" data-verification-code="true"
                                   style="letter-spacing:6px;font-size:1.2rem;text-align:center;" />
                        </div>
                        <button type="submit" class="btn-primary-full btn-save"><i class="bi bi-check-circle"></i> Verify Code</button>
                    </form>
                    <div class="text-center signup-text mt-2">
                        <a href="#" id="forgotResendLink" onclick="handleForgotResend(event)">Resend code</a>
                    </div>
                </div>

                <!-- STEP 3: New Password -->
                <div id="forgotStep3" style="display:none;">
                    <p class="text-muted mb-3">Choose a new password for your account.</p>
                    <form id="forgotResetForm" onsubmit="handleForgotReset(event)">
                        <div class="mb-3">
                            <label class="form-label-custom">New Password</label>
                            <input type="password" id="forgotNewPassword" class="form-control form-control-custom" required minlength="8" />
                        </div>
                        <div class="mb-3">
                            <label class="form-label-custom">Confirm New Password</label>
                            <input type="password" id="forgotConfirmPassword" class="form-control form-control-custom" required minlength="8" />
                        </div>
                        <button type="submit" class="btn-primary-full btn-save"><i class="bi bi-shield-check"></i> Reset Password</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Contact Admin Modal -->
<div class="modal fade" id="contactAdminModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-envelope-fill"></i> Contact Admin</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted mb-3">Send a message to the admin to request account access or support.</p>
                <form id="contactAdminForm" onsubmit="handleContactAdmin(event)">
                    <div class="mb-3">
                        <label class="form-label-custom">Full Name</label>
                        <input type="text" id="contactName" class="form-control form-control-custom" required />
                    </div>
                    <div class="mb-3">
                        <label class="form-label-custom">Email</label>
                        <input type="email" id="contactEmail" class="form-control form-control-custom" required />
                    </div>
                    <div class="mb-3">
                        <label class="form-label-custom">Phone (optional)</label>
                        <input type="tel" id="contactPhone" class="form-control form-control-custom" />
                    </div>
                    <div class="mb-3">
                        <label class="form-label-custom">Subject</label>
                        <select id="contactSubject" class="form-select form-control-custom" required>
                            <option>Account Access Request</option>
                            <option>Technical Support</option>
                            <option>General Inquiry</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label-custom">Message</label>
                        <textarea id="contactMessage" class="form-control form-control-custom" rows="3" required></textarea>
                    </div>
                    <button type="submit" class="btn-primary-full w-100"><i class="bi bi-send"></i> Send Message</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- OTP Verification Modal -->
<div class="modal fade" id="otpModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-shield-lock-fill"></i> Verify Your Identity</h5>
            </div>
            <div class="modal-body">
                <p class="text-muted mb-3" id="otpInfoText">We've sent a 6-digit code to your email. Enter it below to continue.</p>

                <div class="login-alert" id="otpAlert">
                    <i class="bi bi-exclamation-circle-fill"></i>
                    <span id="otpAlertMessage"></span>
                </div>

                <form id="otpForm" onsubmit="handleOtpVerify(event)">
                    <div class="mb-3">
                        <label class="form-label-custom">Verification Code</label>
                        <input type="text" id="otpCode" class="form-control form-control-custom" maxlength="6"
                               inputmode="numeric" pattern="[0-9]*" placeholder="000000" required autofocus
                               data-allow-numeric="true" data-verification-code="true"
                               style="letter-spacing:6px;font-size:1.3rem;text-align:center;" />
                    </div>
                    <button type="submit" class="btn-primary-full w-100"><i class="bi bi-check-circle"></i> Verify &amp; Sign In</button>
                </form>

                <div class="text-center signup-text mt-2">
                    <a href="#" id="resendOtpLink" onclick="handleResendOtp(event)">Resend code</a>
                    <span class="mx-2">|</span>
                    <a href="#" onclick="cancelOtp(event)">Cancel</a>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js" defer></script>
<script src="../main.js?v=<?php echo filemtime(__DIR__ . '/../main.js'); ?>" defer></script>
</body>
</html>
