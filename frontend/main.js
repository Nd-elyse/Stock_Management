/* ============================================================
   LOADING INDICATORS
   ------------------------------------------------------------
   Lightweight loading states for async operations
   ============================================================ */
(function() {
    var activeLoading = new Set();

    function showLoading(element) {
        if (!element) return;
        if (element.tagName === 'BUTTON') {
            element.dataset.originalText = element.innerHTML;
            element.disabled = true;
            element.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Loading...';
        } else if (element.tagName === 'FORM') {
            var submitBtn = element.querySelector('button[type="submit"]');
            if (submitBtn) showLoading(submitBtn);
        }
        activeLoading.add(element);
    }

    function hideLoading(element) {
        if (!element) return;
        if (element.tagName === 'BUTTON') {
            element.disabled = false;
            if (element.dataset.originalText) {
                element.innerHTML = element.dataset.originalText;
                delete element.dataset.originalText;
            }
        } else if (element.tagName === 'FORM') {
            var submitBtn = element.querySelector('button[type="submit"]');
            if (submitBtn) hideLoading(submitBtn);
        }
        activeLoading.delete(element);
    }

    function showGlobalLoading() {
        var existing = document.getElementById('globalLoadingOverlay');
        if (existing) return;
        
        var overlay = document.createElement('div');
        overlay.id = 'globalLoadingOverlay';
        overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.3);z-index:9999;display:flex;align-items:center;justify-content:center;';
        overlay.innerHTML = '<div class="spinner-border text-light" role="status" style="width:3rem;height:3rem;"><span class="visually-hidden">Loading...</span></div>';
        document.body.appendChild(overlay);
    }

    function hideGlobalLoading() {
        var overlay = document.getElementById('globalLoadingOverlay');
        if (overlay) overlay.remove();
    }

    window.showLoading = showLoading;
    window.hideLoading = hideLoading;
    window.showGlobalLoading = showGlobalLoading;
    window.hideGlobalLoading = hideGlobalLoading;
})();

/* ============================================================
   CSRF-AWARE FETCH LAYER
   ------------------------------------------------------------
   Every state-changing call to backend/api/*.php must carry the
   session CSRF token. Rather than editing dozens of individual
   fetch() call-sites, window.fetch is wrapped once here so that:
     - POST/PUT/DELETE/PATCH to our own /backend/api/ endpoints
       automatically receive the X-CSRF-Token header,
     - cookies (the PHP session) are always sent,
     - a non-JSON error page (PHP fatal, 403, 500) is surfaced as a
       readable message instead of "Unexpected token < in JSON".
   The token is rendered by every dashboard page as
   <meta name="csrf-token" content="...">.
   ============================================================ */
(function () {
    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }
    window.getCsrfToken = csrfToken;

    var nativeFetch = window.fetch.bind(window);
    var csrfRefreshPromise = null;

    function refreshCsrfToken() {
        if (csrfRefreshPromise) return csrfRefreshPromise;

        csrfRefreshPromise = nativeFetch('../../backend/api/csrf.php?action=refresh', { credentials: 'same-origin' })
            .then(function (res) {
                return res.json().then(function (data) {
                    if (data && data.success && data.csrf_token) {
                        var meta = document.querySelector('meta[name="csrf-token"]');
                        if (meta) {
                            meta.setAttribute('content', data.csrf_token);
                        }
                        return data.csrf_token;
                    }
                    return '';
                });
            })
            .finally(function () {
                csrfRefreshPromise = null;
            });

        return csrfRefreshPromise;
    }

    window.refreshCsrfToken = refreshCsrfToken;

    window.fetch = function (input, init) {
        init = init || {};
        var url = (typeof input === 'string') ? input : (input && input.url) || '';
        var method = (init.method || (input && input.method) || 'GET').toUpperCase();
        var isApi = url.indexOf('backend/api/') !== -1 || url.indexOf('/api/') === 0;
        var isWrite = ['POST', 'PUT', 'DELETE', 'PATCH'].indexOf(method) !== -1;

        if (isApi) {
            init.credentials = init.credentials || 'same-origin';
            if (isWrite) {
                var headers = new Headers(init.headers || (input && input.headers) || {});
                if (!headers.has('X-CSRF-Token')) headers.set('X-CSRF-Token', csrfToken());
                init.headers = headers;
            }
        }

        return nativeFetch(input, init).then(function (response) {
            if (response && response.status === 403 && isApi && isWrite) {
                return refreshCsrfToken().then(function (token) {
                    if (!token) return response;

                    var retryHeaders = new Headers(init.headers || (input && input.headers) || {});
                    retryHeaders.set('X-CSRF-Token', token);
                    init.headers = retryHeaders;
                    return nativeFetch(input, init);
                });
            }
            return response;
        });
    };

    /* Parse a fetch Response as JSON, but never throw a cryptic syntax error
       when the server replied with an HTML error page. */
    window.parseJsonResponse = function (res) {
        return res.text().then(function (text) {
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error('Non-JSON response from', res.url, '->', text.slice(0, 500));
                return {
                    success: false,
                    message: res.status === 403
                        ? 'Session expired or not authorised. Please refresh the page and sign in again.'
                        : 'Server error (' + res.status + '). Please try again.'
                };
            }
        });
    };

    /* Convenience helper used by the newer dashboard code. Always resolves
       with a { success, message, data } shaped object. */
    window.apiFetch = function (url, options) {
        options = options || {};
        if (options.body && typeof options.body !== 'string') {
            options.body = JSON.stringify(options.body);
            options.headers = Object.assign({ 'Content-Type': 'application/json' }, options.headers || {});
        }
        return window.fetch(url, options)
            .then(window.parseJsonResponse)
            .catch(function (err) {
                console.error('apiFetch error:', url, err);
                return { success: false, message: 'Network error. Please check your connection and try again.' };
            });
    };
})();

document.addEventListener('DOMContentLoaded', function () {


    /* ---------- Page loader (brief brand animation on first paint) ---------- */
    const loader = document.getElementById('pageLoader');
    if (loader) {
        window.addEventListener('load', function () {
            setTimeout(function () { loader.classList.add('hide'); }, 300);
        });
        /* safety net in case 'load' already fired */
        setTimeout(function () { loader.classList.add('hide'); }, 1500);
    }

    /* ---------- Public site: navbar shadow/blur on scroll ---------- */
    const mainNav = document.getElementById('mainNav');
    if (mainNav) {
        const onScroll = function () {
            mainNav.classList.toggle('scrolled', window.scrollY > 30);
        };
        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();
    }

    /* ---------- Back-to-top button (created once, reused site-wide) ---------- */
    let backToTop = document.getElementById('backToTop');
    if (!backToTop && (mainNav || document.querySelector('.footer-custom'))) {
        backToTop = document.createElement('button');
        backToTop.id = 'backToTop';
        backToTop.className = 'back-to-top';
        backToTop.innerHTML = '<i class="bi bi-arrow-up"></i>';
        backToTop.setAttribute('aria-label', 'Back to top');
        document.body.appendChild(backToTop);
        backToTop.addEventListener('click', function () {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
        window.addEventListener('scroll', function () {
            backToTop.classList.toggle('show', window.scrollY > 400);
        }, { passive: true });
    }

    /* ---------- Scroll-reveal animations (.reveal / .reveal-left / .reveal-right / .reveal-scale) ---------- */
    const revealTargets = document.querySelectorAll('.reveal, .reveal-left, .reveal-right, .reveal-scale');
    if (revealTargets.length) {
        if ('IntersectionObserver' in window) {
            const io = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('reveal-visible');
                        io.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.12, rootMargin: '0px 0px -60px 0px' });
            revealTargets.forEach(function (el) { io.observe(el); });
        } else {
            revealTargets.forEach(function (el) { el.classList.add('reveal-visible'); });
        }
    }

    /* ---------- Animated counters (elements: <span class="stat-number" data-count="1200">) ---------- */
    const counters = document.querySelectorAll('[data-count]');
    if (counters.length) {
        const animateCounter = function (el) {
            const target = parseInt(el.getAttribute('data-count'), 10) || 0;
            const duration = 1600;
            const start = performance.now();
            function tick(now) {
                const progress = Math.min((now - start) / duration, 1);
                const eased = 1 - Math.pow(1 - progress, 3); /* ease-out-cubic */
                el.textContent = Math.floor(eased * target).toLocaleString();
                if (progress < 1) {
                    requestAnimationFrame(tick);
                } else {
                    el.textContent = target.toLocaleString();
                }
            }
            requestAnimationFrame(tick);
        };
        if ('IntersectionObserver' in window) {
            const ioCounter = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        animateCounter(entry.target);
                        ioCounter.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.4 });
            counters.forEach(function (el) { ioCounter.observe(el); });
        } else {
            counters.forEach(animateCounter);
        }
    }

    /* NOTE: @keyframes slideIn now lives in style.css (merged in from
       staff.css), so it no longer needs to be injected here. */
});

function showToast(message, type) {
    type = type || 'success';
    let container = document.getElementById('toastContainer');
    let createdContainer = false;
    if (!container) {
        container = document.createElement('div');
        container.id = 'toastContainer';
        container.style.cssText = 'position:fixed;top:20px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:10px;';
        document.body.appendChild(container);
        createdContainer = true;
    }

    if (createdContainer) {
        const icons = { success: 'bi-check-circle-fill', danger: 'bi-x-circle-fill', warning: 'bi-exclamation-triangle-fill', info: 'bi-info-circle-fill' };
        const colors = { success: '#16a34a', danger: '#dc2626', warning: '#d97706', info: '#4A90D9' };
        const toast = document.createElement('div');
        toast.className = 'toast-custom';
        toast.style.cssText = 'padding:0.9rem 1.2rem;display:flex;align-items:center;gap:0.7rem;min-width:260px;max-width:340px;font-size:0.9rem;font-weight:600;color:#1f2937;animation:slideIn 0.3s ease;border-left-color:' + (colors[type] || colors.success) + ';';
        toast.innerHTML = '<i class="bi ' + (icons[type] || icons.success) + '" style="color:' + (colors[type] || colors.success) + ';font-size:1.2rem;"></i><span>' + message + '</span>';
        container.appendChild(toast);
        setTimeout(function () {
            toast.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(30px)';
            setTimeout(function () { toast.remove(); }, 300);
        }, 3000);
    } else {
        const toast = document.createElement('div');
        toast.className = `toast-custom toast-${type}`;

        let icon = 'bi-check-circle-fill';
        if (type === 'danger') icon = 'bi-x-circle-fill';
        else if (type === 'warning') icon = 'bi-exclamation-triangle-fill';
        else if (type === 'info') icon = 'bi-info-circle-fill';

        toast.innerHTML = `
            <div class="toast-icon"><i class="bi ${icon}"></i></div>
            <div class="toast-message">${message}</div>
            <button class="toast-close" onclick="this.parentElement.remove()">&times;</button>
        `;
        container.appendChild(toast);

        setTimeout(() => {
            if (toast.parentElement) toast.remove();
        }, 5000);
    }
}

/* Safe render helper - use for every dynamically-injected table/modal
   cell so undefined/null/empty values never show up blank or as the
   literal string "undefined". */
function safe(v, fallback) {
    if (fallback === undefined) fallback = '\u2014'; // em dash
    if (v === null || v === undefined || v === '' || v === 'null' || v === 'undefined') return fallback;
    return v;
}
window.safe = safe;

/* Show/hide the Mechanic-only fields (Specialization, Salary) in the
   Add/Edit User form depending on the selected Role. Mirrors the
   Users-Mechanics 1:0..1 relationship from the ERD (MechanicID is only
   set when Role = 'Mechanic'). */
function toggleRoleFields(selectEl, mechanicFieldsId) {
    const fields = document.getElementById(mechanicFieldsId);
    if (!fields) return;
    const isMechanic = selectEl.value === 'Mechanic';
    fields.style.display = isMechanic ? '' : 'none';
    fields.querySelectorAll('input, select').forEach(function (el) {
        el.required = isMechanic;
    });
}

/* Generic confirm-then-toast helper for Delete actions */
function confirmDelete(entityLabel, onConfirmMessage) {
    if (confirm('Are you sure you want to delete this ' + entityLabel + '? This action cannot be undone.')) {
        showToast(onConfirmMessage || (entityLabel + ' deleted successfully.'), 'danger');
        return true;
    }
    return false;
}

/* Approve / Reject helpers for service-request style workflows
   (Receptionist approving public Book-Service requests, Stock Manager
   approving mechanic part requests, etc.) */
function approveRequest(rowEl, successMessage) {
    const statusCell = rowEl.querySelector('.badge-status');
    if (statusCell) {
        statusCell.className = 'badge-status badge-delivered';
        statusCell.textContent = 'Approved';
    }
    const actionsCell = rowEl.querySelector('.row-actions');
    if (actionsCell) actionsCell.innerHTML = '<span style="color:#94a3b8;font-size:0.8rem;">No action needed</span>';
    showToast(successMessage || 'Request approved successfully!', 'success');
}
function rejectRequest(rowEl, successMessage) {
    if (!confirm('Are you sure you want to reject this request?')) return;
    const statusCell = rowEl.querySelector('.badge-status');
    if (statusCell) {
        statusCell.className = 'badge-status badge-cancelled';
        statusCell.textContent = 'Rejected';
    }
    const actionsCell = rowEl.querySelector('.row-actions');
    if (actionsCell) actionsCell.innerHTML = '<span style="color:#94a3b8;font-size:0.8rem;">No action needed</span>';
    showToast(successMessage || 'Request has been rejected.', 'danger');
}
/* ============================================================
   GLOBAL BUTTON LOADING GUARD
   Any button that triggers a network request (form submit or
   plain onclick handler) is disabled + shows a spinner until
   every request it started has settled. Prevents double submits
   and duplicate records.
   ============================================================ */
(function () {
    const pending = new WeakMap();      // button -> in-flight request count
    let activeButton = null;            // button that is currently "armed"
    let armTimer = null;
    const originalFetch = window.fetch;
    const OriginalXHR = window.XMLHttpRequest;
    const SAFETY_TIMEOUT = 20000;

    function isBusy(button) {
        return !!button && button.dataset.loading === 'true';
    }

    function lock(button) {
        if (!button || isBusy(button)) return;
        button.dataset.loading = 'true';
        button.setAttribute('aria-busy', 'true');
        const width = button.offsetWidth;
        if (width) button.style.minWidth = width + 'px';
        button.classList.add('is-loading');
        button.disabled = true;
        button.dataset.loadingTimer = String(window.setTimeout(function () {
            unlock(button, true);
        }, SAFETY_TIMEOUT));
    }

    function unlock(button, force) {
        if (!button || !isBusy(button)) return;
        if (!force && (pending.get(button) || 0) > 0) return;
        window.clearTimeout(Number(button.dataset.loadingTimer));
        delete button.dataset.loadingTimer;
        delete button.dataset.loading;
        button.removeAttribute('aria-busy');
        button.classList.remove('is-loading');
        button.style.minWidth = '';
        button.disabled = false;
        pending.delete(button);
    }

    /* Arm a button: the next request started (synchronously or on the same
       tick) is attributed to it. If nothing happens, release it again. */
    function arm(button) {
        if (!button || isBusy(button) || button.disabled) return;
        if (button.hasAttribute('data-no-loading')) return;
        activeButton = button;
        window.clearTimeout(armTimer);
        armTimer = window.setTimeout(function () {
            if (activeButton === button) activeButton = null;
        }, 0);
    }

    /* The button is only locked once a real request starts, so buttons that
       merely open a modal or switch a tab are never disabled. */
    function track(button) {
        if (!button) return function () {};
        lock(button);
        pending.set(button, (pending.get(button) || 0) + 1);
        let done = false;
        return function () {
            if (done) return;
            done = true;
            pending.set(button, Math.max(0, (pending.get(button) || 1) - 1));
            unlock(button);
        };
    }

    /* --- Triggers -------------------------------------------------- */
    document.addEventListener('submit', function (event) {
        const button = event.submitter
            || event.target.querySelector('button[type="submit"], input[type="submit"]');
        arm(button);
    }, true);

    document.addEventListener('click', function (event) {
        const button = event.target.closest('button, .btn, [role="button"]');
        // Real form submits are handled by the submit listener above.
        if (!button || (button.type === 'submit' && button.form)) return;
        if (button.closest('[data-bs-dismiss], [data-bs-toggle]')) return;
        if (button.hasAttribute('data-bs-toggle') || button.hasAttribute('data-bs-dismiss')) return;
        arm(button);
    }, true);

    /* --- Request interception -------------------------------------- */
    if (typeof originalFetch === 'function') {
        window.fetch = function () {
            const release = track(activeButton);
            let request;
            try {
                request = originalFetch.apply(this, arguments);
            } catch (error) {
                release();
                throw error;
            }
            return Promise.resolve(request).then(function (response) {
                release();
                return response;
            }, function (error) {
                release();
                throw error;
            });
        };
    }

    if (typeof OriginalXHR === 'function') {
        window.XMLHttpRequest = function () {
            const xhr = new OriginalXHR();
            const originalSend = xhr.send;
            xhr.send = function () {
                const release = track(activeButton);
                xhr.addEventListener('loadend', release);
                return originalSend.apply(xhr, arguments);
            };
            return xhr;
        };
        window.XMLHttpRequest.prototype = OriginalXHR.prototype;
    }

    /* Full page navigations / bfcache restores must never leave a dead button */
    window.addEventListener('pageshow', function () {
        document.querySelectorAll('[data-loading="true"]').forEach(function (button) {
            unlock(button, true);
        });
    });

    /* Exposed so custom code can wrap non-fetch async work if needed */
    window.withButtonLoading = function (button, promise) {
        if (!button) return promise;
        lock(button);
        const release = track(button);
        return Promise.resolve(promise).then(function (value) {
            release();
            return value;
        }, function (error) {
            release();
            throw error;
        });
    };
})();

/* 
   PUBLIC SITE - contact form
   (originally an inline <script> block in pages/contact.php)
    */
function submitContactForm(e) {
    e.preventDefault();
    const payload = {
        full_name: document.getElementById('contactName').value,
        email: document.getElementById('contactEmail').value,
        phone: document.getElementById('contactPhone').value,
        subject: document.getElementById('contactSubject').value,
        message: document.getElementById('contactMessage').value
    };

    fetch('../../backend/api/contactmessages.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(res => res.json())
    .then(result => {
        if (result.success) {
            showToast('Message sent successfully! We will contact you soon.', 'success');
            document.getElementById('contactForm').reset();
        } else {
            showToast(result.message || 'Could not send message. Please try again.', 'danger');
        }
    })
    .catch(() => showToast('Network error. Please try again.', 'danger'));
}

/* 
   PUBLIC SITE - login / OTP / forgot-password / contact-admin
   (originally an inline <script> block in pages/login.php)
   Gated on the presence of #loginForm so this only runs on the
   login page itself.
    */
(function () {
    if (!document.getElementById('loginForm')) return;

let selectedRole = 'admin';

document.querySelectorAll('.role-chip').forEach(function (chip) {
    chip.addEventListener('click', function () {
        document.querySelectorAll('.role-chip').forEach(function (c) { c.classList.remove('active'); });
        this.classList.add('active');
        selectedRole = this.getAttribute('data-role');
        hideAlert();
    });
});

function togglePassword() {
    const input = document.getElementById('loginPassword');
    const icon = document.getElementById('passwordToggle');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.replace('bi-eye', 'bi-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.replace('bi-eye-slash', 'bi-eye');
    }
}

const alertEl = document.getElementById('loginAlert');
const alertMsg = document.getElementById('alertMessage');

function showAlert(message, type) {
    type = type || 'danger';
    alertEl.className = 'login-alert show ' + type;
    alertMsg.textContent = message;
}
function hideAlert() {
    alertEl.className = 'login-alert';
    alertMsg.textContent = '';
}

function handleLogin(e) {
    e.preventDefault();
    const username = document.getElementById('loginUsername').value.trim();
    const password = document.getElementById('loginPassword').value.trim();

    if (!username || !password) {
        showAlert('Please enter both username and password.', 'danger');
        return;
    }
    hideAlert();

    // Real authentication: verify against the `users` table in the
    // database via api/login.php (role is NOT taken from the role
    // chips anymore - the server decides the role from the DB).
    fetch('../../backend/api/auth.php?resource=login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username, password })
    })
        .then(function (res) { return res.json(); })
        .then(function (result) {
            if (result.success && result.otp_required) {
                showAlert(result.message || 'Verification code sent.', 'success');
                openOtpModal(result.message);
            } else if (result.success) {
                // Fallback path (shouldn't normally happen once OTP is enabled)
                showAlert('Login successful! Redirecting to your dashboard…', 'success');
                setTimeout(function () {
                    window.location.href = result.redirect;
                    // Fallback: reload if redirect doesn't work
                    setTimeout(function() {
                        window.location.reload();
                    }, 2000);
                }, 500);
            } else {
                showAlert(result.message || 'Invalid credentials. Please try again.', 'danger');
            }
        })
        .catch(function () {
            showAlert('Could not reach the server. Please try again.', 'danger');
        });
}

/* ---------------- OTP verification flow ---------------- */

let otpModalInstance = null;

function openOtpModal(infoMessage) {
    document.getElementById('otpCode').value = '';
    hideOtpAlert();
    if (infoMessage) {
        document.getElementById('otpInfoText').textContent = infoMessage;
    }
    otpModalInstance = bootstrap.Modal.getOrCreateInstance(document.getElementById('otpModal'));
    otpModalInstance.show();
    setTimeout(function () {
        document.getElementById('otpCode').focus();
    }, 300);
}

function showOtpAlert(message, type) {
    type = type || 'danger';
    const el = document.getElementById('otpAlert');
    el.className = 'login-alert show ' + type;
    document.getElementById('otpAlertMessage').textContent = message;
}
function hideOtpAlert() {
    const el = document.getElementById('otpAlert');
    el.className = 'login-alert';
    document.getElementById('otpAlertMessage').textContent = '';
}

function handleOtpVerify(e) {
    e.preventDefault();
    const otp = document.getElementById('otpCode').value.trim();

    if (!otp) {
        showOtpAlert('Please enter the verification code.');
        return;
    }

    fetch('../../backend/api/auth.php?resource=verify-otp', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ otp })
    })
        .then(function (res) { return res.json(); })
        .then(function (result) {
            if (result.success) {
                showOtpAlert('Verified! Redirecting to your dashboard…', 'success');
                setTimeout(function () {
                    // Force redirect to dashboard
                    window.location.href = result.redirect;
                    // Fallback: reload if redirect doesn't work
                    setTimeout(function() {
                        window.location.reload();
                    }, 2000);
                }, 400);
            } else {
                showOtpAlert(result.message || 'Invalid code. Please try again.', 'danger');
            }
        })
        .catch(function () {
            showOtpAlert('Could not reach the server. Please try again.', 'danger');
        });
}

function handleResendOtp(e) {
    e.preventDefault();
    fetch('../../backend/api/auth.php?resource=resend-otp', { method: 'POST' })
        .then(function (res) { return res.json(); })
        .then(function (result) {
            showOtpAlert(result.message || '', result.success ? 'success' : 'danger');
        })
        .catch(function () {
            showOtpAlert('Could not reach the server. Please try again.', 'danger');
        });
}

function cancelOtp(e) {
    e.preventDefault();
    fetch('../../backend/api/auth.php?resource=cancel-otp', { method: 'POST' }).catch(function () {});
    if (otpModalInstance) {
        otpModalInstance.hide();
    }
    hideAlert();
}

document.querySelector('.forgot-link')?.addEventListener('click', function (e) {
    e.preventDefault();
});

// Session timeout check and auto-redirect handling
function checkSessionStatus() {
    // Check if session expired via URL parameter
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('session') === 'expired') {
        // Remove the session expired alert if it exists and show via JS
        const expiredAlert = document.getElementById('sessionExpiredAlert');
        if (expiredAlert) {
            expiredAlert.remove();
        }
        showAlert('Your session has expired. Please log in again.', 'danger');
        // Clean URL
        window.history.replaceState({}, document.title, window.location.pathname);
    }
}

// Ensure user stays on dashboard on page refresh
function handlePageRefresh() {
    // Only run this on dashboard pages
    if (window.location.pathname.includes('staff/')) {
        // Check if user is authenticated by looking for dashboard elements
        const dashboardElement = document.querySelector('.dashboard-main');
        if (!dashboardElement) {
            // If no dashboard element found, redirect to login
            window.location.href = '../pages/login.php';
        }
    }
}

// Prevent back button from accessing cached pages after logout
window.addEventListener('pageshow', function(event) {
    if (event.persisted) {
        // Page was loaded from back/forward cache
        window.location.reload();
    }
});

// Run session checks on page load
document.addEventListener('DOMContentLoaded', function() {
    checkSessionStatus();
    handlePageRefresh();

    // Ensure dashboard tab is reset on page refresh (for staff dashboards) --
    // with one exception: Manage Reports. Previously this ran unconditionally,
    // so a refresh, or the browser restoring the page from cache after the
    // user hit Back, would silently bounce them off the report they were
    // looking at and back to Dashboard -- an automatic redirect they never
    // asked for. Now, if the user was on Reports (recorded by switchTab()/
    // switchReport() above), we put them back on Reports - on the same
    // report sub-view they had open - instead. They only leave Reports when
    // they themselves click another tab; that click clears the flag below
    // and normal reset-on-refresh behavior applies again for every other tab.
    if (window.location.pathname.includes('staff/')) {
        var stayOnReports = false;
        try {
            stayOnReports = sessionStorage.getItem('stayOnReportsTab') === '1' && !!document.getElementById('tab-reports');
        } catch (err) {
            stayOnReports = false;
        }

        setTimeout(function() {
            if (stayOnReports && typeof switchTab === 'function') {
                switchTab('reports', null);
                var lastReport = null;
                try { lastReport = sessionStorage.getItem('lastActiveReport'); } catch (err) { /* ignore */ }
                if (typeof switchReport === 'function') switchReport(lastReport || 'repairs', null);
                return;
            }

            const dashboardTab = document.getElementById('tab-dashboard');
            if (dashboardTab) {
                // Hide all tabs
                document.querySelectorAll('.tab-content').forEach(tab => {
                    tab.style.display = 'none';
                });
                // Show dashboard tab
                dashboardTab.style.display = 'block';
                
                // Update navigation
                document.querySelectorAll('.sidebar-nav a').forEach(link => {
                    link.classList.remove('active');
                });
                const dashboardNav = document.getElementById('nav-dashboard');
                if (dashboardNav) {
                    dashboardNav.classList.add('active');
                }
                
                // Update page title
                const pageTitle = document.getElementById('pageTitle');
                if (pageTitle) {
                    pageTitle.textContent = 'Dashboard';
                }
            }
        }, 100);
    }
});

// Auto-renew session activity periodically (every 30 minutes)
setInterval(function() {
    if (window.location.pathname.includes('staff/')) {
        // Send a heartbeat request to keep session alive
        fetch('../../backend/api/auth.php?resource=session-renew', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' }
        }).catch(function() {
            // If fails, user might be logged out
            console.log('Session renewal failed');
        });
    }
}, 30 * 60 * 1000); // 30 minutes

function handleForgotPassword(e) {
    e.preventDefault();
    const username = document.getElementById('resetUsername').value.trim();
    const note = document.getElementById('resetNote').value.trim();

    if (!username) {
        showAlert('Please enter your username.', 'danger');
        return;
    }

    fetch('../../backend/api/auth.php?resource=password-reset', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username, note })
    })
    .then(res => res.json())
    .then(result => {
        if (result.success) {
            showAlert('Password reset request submitted. An admin will contact you shortly.', 'success');
            bootstrap.Modal.getInstance(document.getElementById('forgotPasswordModal')).hide();
            document.getElementById('forgotPasswordForm').reset();
        } else {
            showAlert(result.message || 'Failed to submit request.', 'danger');
        }
    })
    .catch(() => showAlert('Network error. Please try again.', 'danger'));
}

// Forgot password step functions for the new modal
function openForgotPasswordModal(e) {
    e.preventDefault();
    // Reset modal state
    document.getElementById('forgotStep1').style.display = 'block';
    document.getElementById('forgotStep2').style.display = 'none';
    document.getElementById('forgotStep3').style.display = 'none';
    document.getElementById('forgotStartForm').reset();
    document.getElementById('forgotAlert').style.display = 'none';
    
    // Open modal
    const modal = new bootstrap.Modal(document.getElementById('forgotModal'));
    modal.show();
}

function handleForgotStart(e) {
    e.preventDefault();
    const username = document.getElementById('forgotUsername').value.trim();
    const email = document.getElementById('forgotEmail').value.trim();
    const alertBox = document.getElementById('forgotAlert');
    const alertMessage = document.getElementById('forgotAlertMessage');

    if (!username || !email) {
        alertBox.style.display = 'flex';
        alertMessage.textContent = 'Please enter both username and email.';
        return;
    }

    const btn = e.target.querySelector('button[type="submit"]');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Sending...';

    fetch('../../backend/api/auth.php?resource=forgot-password', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username, email })
    })
    .then(res => res.json())
    .then(result => {
        if (result.success) {
            document.getElementById('forgotStep1').style.display = 'none';
            document.getElementById('forgotStep2').style.display = 'block';
            alertBox.style.display = 'none';
            showToast(result.message || 'Verification code sent successfully.', 'success');
        } else {
            alertBox.style.display = 'flex';
            alertMessage.textContent = result.message || 'Failed to send code.';
        }
    })
    .catch(() => {
        alertBox.style.display = 'flex';
        alertMessage.textContent = 'Network error. Please try again.';
    })
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-send"></i> Send Code';
    });
}

function handleForgotVerify(e) {
    e.preventDefault();
    const otp = document.getElementById('forgotOtp').value.trim();
    const alertBox = document.getElementById('forgotAlert');
    const alertMessage = document.getElementById('forgotAlertMessage');

    if (!otp || otp.length !== 6) {
        alertBox.style.display = 'flex';
        alertMessage.textContent = 'Please enter the 6-digit verification code.';
        return;
    }

    const btn = e.target.querySelector('button[type="submit"]');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Verifying...';

    fetch('../../backend/api/auth.php?resource=forgot-verify-otp', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ otp })
    })
    .then(res => res.json())
    .then(result => {
        if (result.success) {
            document.getElementById('forgotStep2').style.display = 'none';
            document.getElementById('forgotStep3').style.display = 'block';
            alertBox.style.display = 'none';
            showToast('Code verified successfully. Please set your new password.', 'success');
        } else {
            alertBox.style.display = 'flex';
            alertMessage.textContent = result.message || 'Invalid code.';
        }
    })
    .catch(() => {
        alertBox.style.display = 'flex';
        alertMessage.textContent = 'Network error. Please try again.';
    })
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check-circle"></i> Verify Code';
    });
}

function handleForgotReset(e) {
    e.preventDefault();
    const newPassword = document.getElementById('forgotNewPassword').value;
    const confirmPassword = document.getElementById('forgotConfirmPassword').value;
    const alertBox = document.getElementById('forgotAlert');
    const alertMessage = document.getElementById('forgotAlertMessage');

    if (!newPassword || newPassword.length < 8) {
        alertBox.style.display = 'flex';
        alertMessage.textContent = 'Password must be at least 8 characters.';
        return;
    }

    if (newPassword !== confirmPassword) {
        alertBox.style.display = 'flex';
        alertMessage.textContent = 'Passwords do not match.';
        return;
    }

    const btn = e.target.querySelector('button[type="submit"]');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Resetting...';

    fetch('../../backend/api/auth.php?resource=forgot-reset-password', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ password: newPassword, confirm: confirmPassword })
    })
    .then(res => res.json())
    .then(result => {
        if (result.success) {
            alertBox.style.display = 'flex';
            alertBox.className = 'login-alert alert-success';
            alertMessage.textContent = 'Password reset successfully. You can now login with your new password.';
            setTimeout(() => {
                bootstrap.Modal.getInstance(document.getElementById('forgotModal')).hide();
                // Reset form
                document.getElementById('forgotStep1').style.display = 'block';
                document.getElementById('forgotStep2').style.display = 'none';
                document.getElementById('forgotStep3').style.display = 'none';
                document.getElementById('forgotStartForm').reset();
                alertBox.className = 'login-alert';
                alertBox.style.display = 'none';
            }, 2000);
        } else {
            alertBox.style.display = 'flex';
            alertMessage.textContent = result.message || 'Failed to reset password.';
        }
    })
    .catch(() => {
        alertBox.style.display = 'flex';
        alertMessage.textContent = 'Network error. Please try again.';
    })
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-shield-check"></i> Reset Password';
    });
}

function handleForgotResend(e) {
    e.preventDefault();
    const link = e.target;
    link.disabled = true;
    link.textContent = 'Sending...';

    fetch('../../backend/api/auth.php?resource=forgot-password', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ 
            username: document.getElementById('forgotUsername').value.trim(),
            email: document.getElementById('forgotEmail').value.trim()
        })
    })
    .then(res => res.json())
    .then(result => {
        if (result.success) {
            showToast('New code sent successfully.', 'success');
        } else {
            showToast(result.message || 'Failed to resend code.', 'danger');
        }
    })
    .catch(() => {
        showToast('Network error. Please try again.', 'danger');
    })
    .finally(() => {
        link.disabled = false;
        link.textContent = 'Resend code';
    });
}

function handleContactAdmin(e) {
    e.preventDefault();
    const fullName = document.getElementById('contactName').value.trim();
    const email = document.getElementById('contactEmail').value.trim();
    const phone = document.getElementById('contactPhone').value.trim();
    const subject = document.getElementById('contactSubject').value.trim();
    const message = document.getElementById('contactMessage').value.trim();

    if (!fullName || !email || !subject || !message) {
        showAlert('Please fill in all required fields.', 'danger');
        return;
    }

    fetch('../../backend/api/contactmessages.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ full_name: fullName, email, phone, subject, message })
    })
    .then(res => res.json())
    .then(result => {
        if (result.success) {
            showAlert('Message sent successfully. An admin will contact you soon.', 'success');
            bootstrap.Modal.getInstance(document.getElementById('contactAdminModal')).hide();
            document.getElementById('contactAdminForm').reset();
        } else {
            showAlert(result.message || 'Failed to send message.', 'danger');
        }
    })
    .catch(() => showAlert('Network error. Please try again.', 'danger'));
}

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') hideAlert();
});

    window.togglePassword = togglePassword;
    window.handleLogin = handleLogin;
    window.handleOtpVerify = handleOtpVerify;
    window.handleResendOtp = handleResendOtp;
    window.cancelOtp = cancelOtp;
    window.handleForgotPassword = handleForgotPassword;
    window.handleContactAdmin = handleContactAdmin;
    window.handleForgotStart = handleForgotStart;
    window.handleForgotVerify = handleForgotVerify;
    window.handleForgotReset = handleForgotReset;
    window.handleForgotResend = handleForgotResend;
    window.openForgotPasswordModal = openForgotPasswordModal;
})();

/* 
   DASHBOARD-WIDE (shared by Admin, Mechanic, Receptionist, Stock Manager)
    */
// 
// staff.js — GarageManager Admin Dashboard JavaScript
// 

// 
// SIDEBAR TOGGLE
// 
function toggleSidebar() {
    document.getElementById('sidebar')?.classList.toggle('open');
    document.getElementById('sidebarOverlay')?.classList.toggle('active');
}

function closeSidebar() {
    document.getElementById('sidebar')?.classList.remove('open');
    document.getElementById('sidebarOverlay')?.classList.remove('active');
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeSidebar();
});

// 
// TAB SWITCHING
// 
function switchTab(tab, e) {
    if (e) e.preventDefault();

    document.querySelectorAll('.tab-content').forEach(el => el.style.display = 'none');
    const target = document.getElementById('tab-' + tab);
    if (target) target.style.display = 'block';

    document.querySelectorAll('.sidebar-nav a').forEach(a => a.classList.remove('active'));
    const navLink = document.getElementById('nav-' + tab);
    if (navLink) navLink.classList.add('active');

    const titles = {
        dashboard: 'Dashboard',
        customers: 'Customer',
        vehicles: 'Vehicle',
        repairjobs: 'Repair Job',
        invoices: 'Invoice',
        payments: 'Payment',
        notifications: 'Notifications',
        users: 'Manage Users',
        mechanics: 'Manage Mechanics',
        suppliers: 'Manage Suppliers',
        'spare-parts': 'Manage Spare Parts',
        reports: 'Manage Reports',
        settings: 'Configure System',
        assigned: 'My Jobs',
        parts: 'Request Parts',
        history: 'Job History'
    };
    const icons = {
        dashboard: 'bi-grid-1x2-fill',
        customers: 'bi-people-fill',
        vehicles: 'bi-car-front-fill',
        repairjobs: 'bi-clipboard-check-fill',
        invoices: 'bi-receipt-cutoff',
        payments: 'bi-cash-coin',
        notifications: 'bi-bell-fill',
        users: 'bi-people-fill',
        mechanics: 'bi-tools',
        suppliers: 'bi-truck',
        'spare-parts': 'bi-box-seam',
        reports: 'bi-bar-chart-line-fill',
        settings: 'bi-gear-fill',
        assigned: 'bi-clipboard-check-fill',
        parts: 'bi-box-seam',
        history: 'bi-clock-history'
    };
    const pageTitleEl = document.getElementById('pageTitle');
    if (pageTitleEl) pageTitleEl.textContent = titles[tab] || 'Dashboard';

    if (window.innerWidth <= 992) closeSidebar();

    const searchInput = document.getElementById('globalSearch');
    if (searchInput) searchInput.value = '';

    // Manage Reports is the one tab that should stay put across a refresh
    // or a back/forward navigation instead of being silently reset to
    // Dashboard (see the DOMContentLoaded handler below). Record that the
    // user is on it here; switching to any other tab clears the flag, so
    // the moment they manually navigate away, normal reset-on-refresh
    // behavior resumes for the rest of the app.
    try {
        if (tab === 'reports') {
            sessionStorage.setItem('stayOnReportsTab', '1');
        } else {
            sessionStorage.removeItem('stayOnReportsTab');
        }
    } catch (err) { /* sessionStorage unavailable (e.g. private mode) - no persistence, no crash */ }

    // Don't save tab state - we want to reset to dashboard on refresh
    // sessionStorage.setItem('currentTab', tab);
}

// 
// REPORT SWITCHING
// 
function switchReport(report, e) {
    if (e) e.preventDefault();

    document.querySelectorAll('.report-section').forEach(el => el.style.display = 'none');
    const target = document.getElementById('report-' + report);
    if (target) target.style.display = '';

    document.querySelectorAll('#reportPills .report-pill').forEach(el => el.classList.remove('active'));
    if (e) {
        const btn = e.target.closest('.report-pill');
        if (btn) btn.classList.add('active');
    }
    window.currentReport = report;
    // Remember which report sub-view was open so a refresh/back-navigation
    // that restores the Reports tab (see switchTab() / DOMContentLoaded
    // above) puts the user back exactly where they left off, not just on
    // the Reports tab in general.
    try { sessionStorage.setItem('lastActiveReport', report); } catch (err) { /* sessionStorage unavailable - no persistence, no crash */ }
}

// 
// EXPORT FUNCTION (PDF / Excel)
// 
function exportReport(reportName, format) {
    const tableId = reportName + 'Table';
    const tableEl = document.getElementById(tableId);
    if (!tableEl) {
        showToast('Table not found for ' + reportName, 'danger');
        return;
    }

    const headers = [];
    const rows = [];
    const thead = tableEl.querySelector('thead');
    if (thead) {
        const ths = thead.querySelectorAll('tr th');
        ths.forEach(th => headers.push(th.textContent.trim()));
    }
    const tbody = tableEl.querySelector('tbody');
    if (tbody) {
        const trs = tbody.querySelectorAll('tr');
        trs.forEach(tr => {
            const row = [];
            const tds = tr.querySelectorAll('td');
            tds.forEach(td => row.push(td.textContent.trim()));
            rows.push(row);
        });
    }

    if (rows.length === 0 || (rows.length === 1 && rows[0].length === 1 && rows[0][0].includes('No '))) {
        showToast('No data to export.', 'warning');
        return;
    }

    if (format === 'pdf') {
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF('landscape', 'mm', 'a4');
        doc.text(reportName.charAt(0).toUpperCase() + reportName.slice(1) + ' Report', 14, 20);
        doc.autoTable({
            head: [headers],
            body: rows,
            startY: 30,
            theme: 'striped',
            styles: { fontSize: 8 },
            headStyles: { fillColor: [37, 99, 235] }
        });
        doc.save(reportName + '_report.pdf');
        showToast('PDF exported successfully!', 'success');
    } else if (format === 'excel') {
        const wb = XLSX.utils.book_new();
        const wsData = [headers, ...rows];
        const ws = XLSX.utils.aoa_to_sheet(wsData);
        XLSX.utils.book_append_sheet(wb, ws, reportName);
        XLSX.writeFile(wb, reportName + '_report.xlsx');
        showToast('Excel exported successfully!', 'success');
    }
}

// NOTE: showToast() is defined once, near the top of this file
// (unified version that supports both the dashboard and public-site
// toast styles) - see the top of main.js for its definition.

// 
// CONFIRMATION MODAL
// 
function showConfirmModal(title, message, onConfirm) {
    // Remove existing modal if any
    const existingModal = document.getElementById('customConfirmModal');
    if (existingModal) existingModal.remove();

    // Create modal HTML
    const modalHtml = `
        <div class="modal fade" id="customConfirmModal" tabindex="-1" data-bs-backdrop="static">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">${title}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p>${message}</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-danger" id="confirmBtn">Continue</button>
                    </div>
                </div>
            </div>
        </div>
    `;

    document.body.insertAdjacentHTML('beforeend', modalHtml);
    const modal = new bootstrap.Modal(document.getElementById('customConfirmModal'));
    modal.show();

    document.getElementById('confirmBtn').addEventListener('click', function() {
        modal.hide();
        onConfirm();
        setTimeout(() => document.getElementById('customConfirmModal')?.remove(), 300);
    });

    document.getElementById('customConfirmModal').addEventListener('hidden.bs.modal', function() {
        setTimeout(() => document.getElementById('customConfirmModal')?.remove(), 300);
    });
}

// 
// FORM VALIDATION
// 

// Validate email format
function validateEmail(email) {
    const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return re.test(email);
}

// Validate phone number (basic format)
function validatePhone(phone) {
    const re = /^[\d\s\-\+\(\)]{10,20}$/;
    return re.test(phone);
}

// Validate numeric input
function validateNumeric(value, min = null, max = null) {
    const num = parseFloat(value);
    if (isNaN(num)) return false;
    if (min !== null && num < min) return false;
    if (max !== null && num > max) return false;
    return true;
}

// Validate text input (letters, spaces, and basic punctuation only)
function validateText(value) {
    // Allow letters, spaces, hyphens, apostrophes, and basic punctuation
    const re = /^[a-zA-Z\s\-'\.,!?]+$/;
    return re.test(value);
}

// Validate alphanumeric text (letters, numbers, spaces, and basic punctuation)
function validateAlphanumeric(value) {
    const re = /^[a-zA-Z0-9\s\-'\.,!?]+$/;
    return re.test(value);
}

// Validate year field (exactly 4 digits)
function validateYear(value) {
    const re = /^\d{4}$/;
    if (!re.test(value)) return false;
    
    const year = parseInt(value, 10);
    const currentYear = new Date().getFullYear();
    return year >= 1900 && year <= currentYear + 1;
}

// Show inline validation error message
function showInlineError(field, message) {
    // Remove existing error message if any
    const existingError = field.parentNode.querySelector('.invalid-feedback');
    if (existingError) {
        existingError.remove();
    }
    
    // Add error message
    const errorDiv = document.createElement('div');
    errorDiv.className = 'invalid-feedback';
    errorDiv.style.display = 'block';
    errorDiv.style.color = '#dc3545';
    errorDiv.style.fontSize = '0.8rem';
    errorDiv.style.marginTop = '0.25rem';
    errorDiv.textContent = message;
    field.parentNode.appendChild(errorDiv);
    
    // Add invalid class to field
    field.classList.add('is-invalid');
}

// Clear inline validation error
function clearInlineError(field) {
    const existingError = field.parentNode.querySelector('.invalid-feedback');
    if (existingError) {
        existingError.remove();
    }
    field.classList.remove('is-invalid');
}

function getFieldLabel(field) {
    if (field.labels && field.labels.length > 0) {
        return field.labels[0].textContent.trim();
    }
    return field.getAttribute('aria-label') || field.dataset.label || field.placeholder || field.name || 'This field';
}

// Validate required fields
function validateRequired(form, errors) {
    const requiredFields = form.querySelectorAll('[required]');
    let isValid = true;
    
    requiredFields.forEach(field => {
        const value = field.type === 'checkbox' || field.type === 'radio'
            ? field.checked
            : String(field.value).trim();
        if (!value) {
            const label = getFieldLabel(field);
            field.classList.add('is-invalid');
            errors.push(`${label} is required.`);
            isValid = false;
        } else {
            field.classList.remove('is-invalid');
        }
    });
    
    return isValid;
}

// Validate form with comprehensive checks
function validateDate(date) {
    return /^\d{4}-\d{2}-\d{2}$/.test(date) && !Number.isNaN(new Date(date).getTime());
}

function validateForm(form) {
    let isValid = true;
    const errors = [];

    // Clear all existing inline errors first
    const allInputs = form.querySelectorAll('input, select, textarea');
    allInputs.forEach(input => clearInlineError(input));

    validateRequired(form, errors);

    const emailFields = form.querySelectorAll('input[type="email"]');
    emailFields.forEach(field => {
        if (field.value && !validateEmail(field.value)) {
            errors.push('Please enter a valid email address.');
            field.classList.add('is-invalid');
            showInlineError(field, 'Please enter a valid email address.');
            isValid = false;
        }
    });

    const phoneFields = form.querySelectorAll('input[type="tel"], input[name*="phone"], input[name*="Phone"]');
    phoneFields.forEach(field => {
        if (field.value && !validatePhone(field.value)) {
            errors.push('Please enter a valid phone number.');
            field.classList.add('is-invalid');
            showInlineError(field, 'Please enter a valid phone number.');
            isValid = false;
        }
    });

    const dateFields = form.querySelectorAll('input[type="date"]');
    dateFields.forEach(field => {
        if (field.value && !validateDate(field.value)) {
            errors.push('Please enter a valid date.');
            field.classList.add('is-invalid');
            showInlineError(field, 'Please enter a valid date.');
            isValid = false;
        }
        
        // Validate date is within min/max range
        if (field.value) {
            const min = field.getAttribute('min');
            const max = field.getAttribute('max');
            const fieldValue = new Date(field.value);
            
            if (min && fieldValue < new Date(min)) {
                errors.push(`${getFieldLabel(field)} must be on or after ${min}.`);
                field.classList.add('is-invalid');
                showInlineError(field, `Date must be on or after ${min}.`);
                isValid = false;
            }
            
            if (max && fieldValue > new Date(max)) {
                errors.push(`${getFieldLabel(field)} must be on or before ${max}.`);
                field.classList.add('is-invalid');
                showInlineError(field, `Date must be on or before ${max}.`);
                isValid = false;
            }
        }
    });

    const numericFields = form.querySelectorAll('input[type="number"]');
    numericFields.forEach(field => {
        if (!field.value) return;
        const min = field.getAttribute('min');
        const max = field.getAttribute('max');
        if (!validateNumeric(field.value, min ? parseFloat(min) : null, max ? parseFloat(max) : null)) {
            const errorMsg = `Please enter a valid number${min ? ` (min: ${min})` : ''}${max ? ` (max: ${max})` : ''}.`;
            errors.push(errorMsg);
            field.classList.add('is-invalid');
            showInlineError(field, errorMsg);
            isValid = false;
        }
    });

    // Strict text field validation - prevent numbers in text-only fields
    const textFields = form.querySelectorAll('input[type="text"]:not([data-allow-numeric]):not([data-year-field="true"]):not([data-verification-code="true"]), textarea:not([data-allow-numeric])');
    textFields.forEach(field => {
        if (!field.value) return;
        const label = getFieldLabel(field);
        // Check if field contains only valid text characters
        if (!validateAlphanumeric(field.value)) {
            const errorMsg = `${label} contains invalid characters. Only letters, numbers, and basic punctuation are allowed.`;
            errors.push(errorMsg);
            field.classList.add('is-invalid');
            showInlineError(field, errorMsg);
            isValid = false;
        }
    });

    // Verification code validation - exactly 6 digits
    const verificationCodeFields = form.querySelectorAll('input[data-verification-code="true"]');
    verificationCodeFields.forEach(field => {
        if (!field.value) return;
        
        // Must be exactly 6 digits
        if (!/^\d{6}$/.test(field.value)) {
            const errorMsg = 'Verification code must be exactly 6 digits.';
            errors.push(errorMsg);
            field.classList.add('is-invalid');
            showInlineError(field, errorMsg);
            isValid = false;
        }
    });

    // Year field validation - exactly 4 digits
    const yearFields = form.querySelectorAll('input[data-year-field="true"]');
    yearFields.forEach(field => {
        if (!field.value) return;
        const label = getFieldLabel(field);
        
        if (!validateYear(field.value)) {
            const errorMsg = `${label} must be exactly 4 digits (e.g., 2026) and between 1900 and ${new Date().getFullYear() + 1}.`;
            errors.push(errorMsg);
            field.classList.add('is-invalid');
            showInlineError(field, errorMsg);
            isValid = false;
        }
    });

    const passwordFields = form.querySelectorAll('input[type="password"]');
    if (passwordFields.length >= 2) {
        const pass1 = passwordFields[0].value;
        const pass2 = passwordFields[1].value;
        if (pass1 && pass2 && pass1 !== pass2) {
            errors.push('Passwords do not match.');
            passwordFields.forEach(f => {
                f.classList.add('is-invalid');
                showInlineError(f, 'Passwords do not match.');
            });
            isValid = false;
        }
    }

    // Date range validation: End date cannot be before Start date
    const jobStartDate = form.querySelector('#jobStartDate');
    const jobEndDate = form.querySelector('#jobEndDate');
    if (jobStartDate && jobEndDate && jobStartDate.value && jobEndDate.value) {
        const startValue = new Date(jobStartDate.value);
        const endValue = new Date(jobEndDate.value);
        if (endValue < startValue) {
            errors.push('End date must be on or after the start date.');
            jobEndDate.classList.add('is-invalid');
            showInlineError(jobEndDate, 'End date must be on or after the start date.');
            isValid = false;
        }
    }

    if (!isValid) {
        errors.forEach(err => showToast(err, 'danger'));
    }

    return isValid;
}

// Add validation listeners to all forms on page load
document.addEventListener('DOMContentLoaded', function() {
    // Update all date fields to have today as max date
    updateDateFieldsMaxToToday();
    
    // Set up interval to update date fields max every minute
    // This ensures future dates become selectable when that date is reached
    setInterval(updateDateFieldsMaxToToday, 60000);
    
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            if (!validateForm(form)) {
                e.preventDefault();
                e.stopPropagation();
            }
        });
        
        // Remove invalid class on input
        const inputs = form.querySelectorAll('input, select, textarea');
        inputs.forEach(input => {
            input.addEventListener('input', function() {
                this.classList.remove('is-invalid');
            });
        });

        // Real-time validation for numeric fields
        const numericInputs = form.querySelectorAll('input[type="number"]');
        numericInputs.forEach(input => {
            input.addEventListener('input', function(e) {
                // Skip year fields as they have their own validation
                if (input.dataset.yearField === 'true') return;
                
                // Remove any non-numeric characters except decimal point and minus sign
                const value = this.value;
                const cleaned = value.replace(/[^0-9.\-]/g, '');
                if (value !== cleaned) {
                    this.value = cleaned;
                    // Show inline validation message
                    const label = getFieldLabel(this);
                    showInlineError(this, `${label} must contain only numbers.`);
                } else {
                    clearInlineError(this);
                }
            });
        });

        // Real-time validation for text fields
        const textInputs = form.querySelectorAll('input[type="text"]:not([data-allow-numeric]):not([data-verification-code="true"]):not([data-year-field="true"]), textarea:not([data-allow-numeric])');
        textInputs.forEach(input => {
            input.addEventListener('input', function(e) {
                const value = this.value;
                // Check for numeric characters in text fields
                if (/\d/.test(value) && !input.dataset.allowNumeric) {
                    const label = getFieldLabel(this);
                    showInlineError(this, `${label} should not contain numbers.`);
                } else {
                    clearInlineError(this);
                }
            });
        });

        // Real-time validation for verification code fields
        const verificationCodeInputs = form.querySelectorAll('input[data-verification-code="true"]');
        verificationCodeInputs.forEach(input => {
            input.addEventListener('input', function(e) {
                // Remove any non-digit characters silently
                const value = this.value;
                const cleaned = value.replace(/\D/g, '');
                
                // Truncate to 6 digits if longer
                let finalValue = cleaned;
                if (cleaned.length > 6) {
                    finalValue = cleaned.substring(0, 6);
                }
                
                if (value !== finalValue) {
                    this.value = finalValue;
                }
                
                // Clear any existing errors for verification codes
                clearInlineError(this);
            });
            
            input.addEventListener('blur', function(e) {
                // Validate on blur for complete validation
                const value = this.value;
                if (value && !/^\d{6}$/.test(value)) {
                    showInlineError(this, 'Verification code must be exactly 6 digits.');
                } else if (value && /^\d{6}$/.test(value)) {
                    clearInlineError(this);
                }
            });
        });

        // Real-time validation for date fields
        const dateInputs = form.querySelectorAll('input[type="date"]');
        dateInputs.forEach(input => {
            input.addEventListener('change', function(e) {
                const value = this.value;
                const min = this.getAttribute('min');
                const max = this.getAttribute('max');
                
                // Clear existing error first
                clearInlineError(this);
                
                if (value) {
                    const fieldValue = new Date(value);
                    let hasError = false;
                    
                    if (min && fieldValue < new Date(min)) {
                        showInlineError(this, `Date must be on or after ${min}.`);
                        hasError = true;
                    } else if (max && fieldValue > new Date(max)) {
                        showInlineError(this, `Date must be on or before ${max}.`);
                        hasError = true;
                    }
                    
                    // Validate date range for job start/end dates
                    if (this.id === 'jobStartDate') {
                        const endDateField = form.querySelector('#jobEndDate');
                        if (endDateField && endDateField.value) {
                            const startDate = new Date(value);
                            const endDate = new Date(endDateField.value);
                            if (endDate < startDate) {
                                showInlineError(endDateField, 'End date must be on or after the start date.');
                            } else {
                                clearInlineError(endDateField);
                            }
                        }
                    }
                    
                    if (this.id === 'jobEndDate') {
                        const startDateField = form.querySelector('#jobStartDate');
                        if (startDateField && startDateField.value) {
                            const startDate = new Date(startDateField.value);
                            const endDate = new Date(value);
                            if (endDate < startDate) {
                                showInlineError(this, 'End date must be on or after the start date.');
                                hasError = true;
                            }
                        }
                    }
                }
            });
        });

        // Real-time validation for year fields
        const yearInputs = form.querySelectorAll('input[data-year-field="true"]');
        yearInputs.forEach(input => {
            input.addEventListener('input', function(e) {
                // Remove any non-digit characters
                const value = this.value;
                const cleaned = value.replace(/\D/g, '');
                
                // Truncate to 4 digits if longer
                let finalValue = cleaned;
                if (cleaned.length > 4) {
                    finalValue = cleaned.substring(0, 4);
                }
                
                if (value !== finalValue) {
                    this.value = finalValue;
                    if (value !== cleaned) {
                        showInlineError(this, 'Year must contain only digits.');
                    } else {
                        showInlineError(this, 'Year must be exactly 4 digits.');
                    }
                } else {
                    clearInlineError(this);
                }
                
                // Validate the 4-digit year
                if (finalValue.length === 4) {
                    const year = parseInt(finalValue, 10);
                    const currentYear = new Date().getFullYear();
                    
                    if (year < 1900 || year > currentYear + 1) {
                        showInlineError(this, `Year must be between 1900 and ${currentYear + 1}.`);
                    } else {
                        clearInlineError(this);
                    }
                }
            });
            
            input.addEventListener('blur', function(e) {
                // Validate on blur for complete validation
                const value = this.value;
                if (value && !validateYear(value)) {
                    showInlineError(this, 'Year must be exactly 4 digits (e.g., 2026) and between 1900 and ' + (new Date().getFullYear() + 1) + '.');
                } else if (value && validateYear(value)) {
                    clearInlineError(this);
                }
            });
        });
    });
});

// Function to update all date fields max attribute to today's date
function updateDateFieldsMaxToToday() {
    const today = new Date().toISOString().split('T')[0];
    const dateFields = document.querySelectorAll('input[type="date"]');
    
    dateFields.forEach(field => {
        // Update max attribute to today for all date fields (including readonly)
        // This ensures future dates become selectable when that date is reached
        field.setAttribute('max', today);
        
        // Ensure min attribute is set to 2000-01-01 if not already set
        if (!field.getAttribute('min')) {
            field.setAttribute('min', '2000-01-01');
        }
    });
}

// 
// USER CRUD
// 
function editUser(user) {
    document.getElementById('userModalTitle').innerHTML =
        '<i class="bi bi-pencil-square" style="color:var(--primary-blue);"></i> Edit User';
    document.getElementById('editingUserId').value = user.UserID;
    document.getElementById('userFullName').value = user.FullName || '';
    document.getElementById('userUsername').value = user.Username || '';
    document.getElementById('userEmail').value = user.Email || '';
    document.getElementById('userPhone').value = user.Phone || '';
    document.getElementById('roleSelect').value = user.Role || '';
    document.getElementById('userStatus').value = user.Status || 'Active';
    document.getElementById('userPassword').required = false;
    document.getElementById('userPassword').placeholder = 'Leave blank to keep current';
    document.getElementById('userConfirmPassword').required = false;
    
    // Trigger role change to show/hide mechanic fields
    document.getElementById('roleSelect').dispatchEvent(new Event('change'));
    
    // If user is a mechanic, fetch mechanic details to populate fields
    if (user.Role === 'Mechanic' && user.MechanicID) {
        fetch('../../backend/api/jobs.php?resource=mechanics&id=' + user.MechanicID)
            .then(res => res.json())
            .then(result => {
                if (result.success && result.data) {
                    const mech = result.data;
                    document.getElementById('mechanicSpecialization').value = mech.Specialization || '';
                    document.getElementById('mechanicSalary').value = mech.Salary || '';
                }
            })
            .catch(() => console.log('Could not fetch mechanic details'));
    }
    
    new bootstrap.Modal(document.getElementById('userModal')).show();
}

document.getElementById('userModal')?.addEventListener('hidden.bs.modal', function() {
    document.getElementById('userModalTitle').innerHTML =
        '<i class="bi bi-person-plus" style="color:var(--primary-blue);"></i> Add User';
    document.getElementById('userForm').reset();
    document.getElementById('editingUserId').value = '';
    document.getElementById('userPassword').required = true;
    document.getElementById('userPassword').placeholder = '';
    document.getElementById('userConfirmPassword').required = true;
});

// Toggle mechanic fields based on role selection
document.getElementById('roleSelect')?.addEventListener('change', function() {
    const mechanicFields = document.getElementById('mechanicFields');
    if (this.value === 'Mechanic') {
        mechanicFields.style.display = 'block';
        document.getElementById('mechanicSpecialization').required = true;
    } else {
        mechanicFields.style.display = 'none';
        document.getElementById('mechanicSpecialization').required = false;
    }
});

function saveUser(e) {
    e.preventDefault();
    const id = document.getElementById('editingUserId').value;
    const password = document.getElementById('userPassword').value;
    const confirm = document.getElementById('userConfirmPassword').value;

    if (password && password !== confirm) {
        showToast('Passwords do not match.', 'danger');
        return;
    }

    const role = document.getElementById('roleSelect').value;
    const payload = {
        full_name: document.getElementById('userFullName').value,
        username: document.getElementById('userUsername').value,
        email: document.getElementById('userEmail').value,
        phone: document.getElementById('userPhone').value,
        role: role,
        status: document.getElementById('userStatus').value,
        password: password
    };

    // Add mechanic-specific fields if role is Mechanic
    if (role === 'Mechanic') {
        payload.specialization = document.getElementById('mechanicSpecialization').value;
        payload.salary = parseFloat(document.getElementById('mechanicSalary').value) || 0;
    }

    const url = id ? ('../../backend/api/users.php?id=' + id) : '../../backend/api/users.php';
    const method = id ? 'PUT' : 'POST';

    fetch(url, {
        method: method,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(res => res.json())
    .then(result => {
        if (result.success) {
            showToast(result.message || 'User saved!', 'success');
            bootstrap.Modal.getInstance(document.getElementById('userModal')).hide();
            setTimeout(() => softReload(), 600);
        } else {
            showToast(result.message || 'Could not save user.', 'danger');
        }
    })
    .catch(() => showToast('Network error.', 'danger'));
}

function deleteUser(id, name) {
    showConfirmModal('Delete User', `Are you sure you want to delete "${name}"? This action cannot be undone.`, () => {
        fetch('../../backend/api/users.php?id=' + id, { method: 'DELETE' })
            .then(res => {
                if (!res.ok) throw new Error('Network response was not ok: ' + res.status);
                return res.json();
            })
            .then(result => {
                if (result.success) {
                    showToast('User "' + name + '" removed.', 'danger');
                    setTimeout(() => softReload(), 600);
                } else {
                    showToast(result.message || 'Could not delete.', 'danger');
                }
            })
            .catch(err => {
                console.error('Delete user error:', err);
                showToast('Network error. Please try again.', 'danger');
            });
    });
}

// 
// NOTIFICATION CRUD
// 
function editNotification(n) {
    document.getElementById('notificationModalTitle').innerHTML =
        '<i class="bi bi-pencil-square" style="color:var(--primary-blue);"></i> Edit Notification';
    document.getElementById('editingNotificationId').value = n.NotificationID || 0;
    document.getElementById('notifUserId').value = n.UserID || '';
    document.getElementById('notifType').value = n.Type || 'system';
    document.getElementById('notifMessage').value = n.Message || '';
    document.getElementById('notifLink').value = n.Link || '';
    new bootstrap.Modal(document.getElementById('notificationModal')).show();
}

document.getElementById('notificationModal')?.addEventListener('hidden.bs.modal', function() {
    document.getElementById('notificationModalTitle').innerHTML =
        '<i class="bi bi-bell-plus" style="color:var(--primary-blue);"></i> Add Notification';
    document.getElementById('notificationForm').reset();
    document.getElementById('editingNotificationId').value = '';
});

function saveNotification(e) {
    e.preventDefault();
    const form = e.target;
    const submitBtn = form.querySelector('button[type="submit"]');
    showLoading(submitBtn);
    
    const id = document.getElementById('editingNotificationId').value;
    const userIdValue = document.getElementById('notifUserId').value;
    const payload = {
        user_id: userIdValue === '' ? null : parseInt(userIdValue) || null,
        type: document.getElementById('notifType').value,
        message: document.getElementById('notifMessage').value,
        link: document.getElementById('notifLink').value || '#'
    };

    const url = id ? ('../../backend/api/notifications.php?id=' + id) : '../../backend/api/notifications.php';
    const method = id ? 'PUT' : 'POST';

    fetch(url, {
        method: method,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(res => res.json())
    .then(result => {
        if (result.success) {
            showToast(result.message || 'Notification saved!', 'success');
            bootstrap.Modal.getInstance(document.getElementById('notificationModal')).hide();
            // Direct DOM update for better performance
            updateNotificationsTable();
        } else {
            showToast(result.message || 'Could not save.', 'danger');
        }
    })
    .catch(() => showToast('Network error.', 'danger'))
    .finally(() => {
        hideLoading(submitBtn);
    });
}

function updateNotificationsTable() {
    fetch('../../backend/api/notifications.php?scope=all')
        .then(res => res.json())
        .then(result => {
            if (result.success && Array.isArray(result.data)) {
                const notifList = document.getElementById('notificationList');
                if (!notifList) return;
                
                if (result.data.length === 0) {
                    notifList.innerHTML = '<div class="text-center py-4 text-muted">No notifications yet.</div>';
                } else {
                    notifList.innerHTML = result.data.map(n => {
                        const isRead = n.IsRead ?? 0;
                        const notifId = n.NotificationID ?? 0;
                        const type = n.Type ?? 'system';
                        const message = n.Message ?? '';
                        const link = n.Link ?? '#';
                        const time = n.CreatedAt || new Date().toISOString();
                        const icon = type === 'job' ? 'bi-plus-circle-fill' : 
                                      type === 'stock' ? 'bi-exclamation-triangle-fill' : 
                                      type === 'payment' ? 'bi-cash-coin' : 'bi-info-circle-fill';
                        const color = type === 'job' ? '#2563eb' : 
                                      type === 'stock' ? '#dc2626' : 
                                      type === 'payment' ? '#16a34a' : '#6b7280';
                        const userName = n.UserFullName || 'All Users';
                        
                        return `<div class="notif-item d-flex align-items-center gap-3 ${isRead ? '' : 'unread'}"
                             data-id="${notifId}" data-read="${isRead}" style="cursor:pointer;"
                             onclick='viewNotificationDetails(${JSON.stringify(n).replace(/"/g, '&quot;')})'>
                            <div class="notif-icon" style="color:${color};">
                                <i class="bi ${icon}"></i>
                            </div>
                            <div class="notif-message">
                                ${safe(message)}
                                <small class="text-muted d-block">To: ${safe(userName)}</small>
                            </div>
                            <div class="notif-time">${new Date(time).toLocaleString('en-US', {month:'short', day:'numeric', hour:'2-digit', minute:'2-digit'})}</div>
                            <div class="d-flex gap-1" onclick="event.stopPropagation();">
                                ${!isRead ? `<button class="btn-action" onclick="markNotificationRead(${notifId})" title="Mark as read"><i class="bi bi-check-circle"></i></button>` : ''}
                                <button class="btn-action edit" onclick='editNotification(${JSON.stringify(n).replace(/"/g, '&quot;')})'><i class="bi bi-pencil"></i></button>
                                <button class="btn-action delete" onclick="deleteNotification(${notifId})"><i class="bi bi-trash"></i></button>
                            </div>
                        </div>`;
                    }).join('');
                }
                if (typeof updateNotificationBadge === 'function') updateNotificationBadge();
                if (typeof refreshCounters === 'function') refreshCounters();
            }
        })
        .catch(err => {
            console.error('Update notifications table error:', err);
            if (typeof softReload === 'function') softReload();
        });
}

// 
// NOTIFICATION DETAILS (view full message + related info on click)
// 
function viewNotificationDetails(n) {
    const modalEl = document.getElementById('notificationDetailsModal');
    if (!modalEl) return;

    const notifId = n.NotificationID ?? 0;
    const isRead = n.IsRead ?? 0;
    const type = n.Type || 'system';
    const message = n.Message || 'Notification';
    const link = (n.Link && n.Link !== '#') ? n.Link : '';
    const status = n.Status || '';
    const created = n.CreatedAt
        ? new Date(n.CreatedAt.replace(' ', 'T')).toLocaleString('en-US', { dateStyle: 'medium', timeStyle: 'short' })
        : '';
    const resolved = n.ResolvedAt
        ? new Date(n.ResolvedAt.replace(' ', 'T')).toLocaleString('en-US', { dateStyle: 'medium', timeStyle: 'short' })
        : '';
    const recipient = n.UserFullName || (n.UserID ? ('User ' + n.UserID) : 'All Users (broadcast)');

    const typeBadgeClass = type === 'success' ? 'bg-success' :
                            type === 'warning' ? 'bg-warning text-dark' :
                            type === 'danger'  ? 'bg-danger' : 'bg-primary';

    const html = `
        <div class="notification-details">
            <div class="mb-3 d-flex gap-2 flex-wrap">
                <span class="badge ${typeBadgeClass} text-uppercase">${safe(type)}</span>
                ${status ? `<span class="badge bg-secondary text-uppercase">${safe(status)}</span>` : ''}
                ${!isRead ? '<span class="badge bg-info text-dark">Unread</span>' : ''}
            </div>
            <div class="mb-3" style="white-space:pre-wrap;font-size:1rem;line-height:1.5;">${safe(message)}</div>
            <div class="view-details-grid">
                <div class="vd-item vd-full"><strong>Sent To:</strong> ${safe(recipient)}</div>
                <div class="vd-item vd-full"><strong>Received:</strong> ${safe(created)}</div>
                ${resolved ? `<div class="vd-item vd-full"><strong>Resolved:</strong> ${safe(resolved)}</div>` : ''}
                ${link ? `<div class="vd-item vd-full"><strong>Related Link:</strong> <a href="${link}">${safe(link)}</a></div>` : ''}
            </div>
        </div>
    `;

    document.getElementById('notificationDetailsBody').innerHTML = html;
    new bootstrap.Modal(modalEl).show();

    // Auto mark-as-read the first time it's opened, quietly (no toast/reload
    // interruption while the modal is open).
    if (!isRead && notifId) {
        fetch('../../backend/api/notifications.php?id=' + notifId + '&action=mark_read', { method: 'PUT' })
            .then(res => res.json())
            .then(result => {
                if (result.success) {
                    const el = document.querySelector(`[data-id="${notifId}"]`);
                    if (el) el.classList.remove('unread');
                    if (typeof updateNotificationBadge === 'function') updateNotificationBadge();
                }
            })
            .catch(err => console.error('Auto mark-as-read error:', err));
    }
}
window.viewNotificationDetails = viewNotificationDetails;

function deleteNotification(id) {
    showConfirmModal('Delete Notification', 'Are you sure you want to delete this notification?', () => {
        fetch('../../backend/api/notifications.php?id=' + id, { method: 'DELETE' })
            .then(res => {
                if (!res.ok) {
                    return res.json().then(err => {
                        throw new Error(err.message || 'HTTP error ' + res.status);
                    });
                }
                return res.json();
            })
            .then(result => {
                if (result.success) {
                    showToast('Notification removed.', 'danger');
                    // Direct DOM update
                    const notifItem = document.querySelector(`.notif-item[data-id="${id}"]`);
                    if (notifItem) {
                        notifItem.remove();
                        // Update unread count if it was unread
                        if (notifItem.classList.contains('unread')) {
                            updateNotificationBadge();
                        }
                        // Check if list is empty
                        const notifList = document.getElementById('notificationList');
                        if (notifList && notifList.children.length === 0) {
                            notifList.innerHTML = '<div class="text-center py-4 text-muted">No notifications yet.</div>';
                        }
                    }
                } else {
                    showToast(result.message || 'Could not delete.', 'danger');
                }
            })
            .catch(err => {
                console.error('Delete notification error:', err);
                showToast(err.message || 'Network error. Please try again.', 'danger');
            });
    });
}

// 
// MECHANIC CRUD
// 
function editMechanic(m) {
    document.getElementById('mechanicModalTitle').innerHTML =
        '<i class="bi bi-pencil-square" style="color:var(--primary-blue);"></i> Edit Mechanic';
    document.getElementById('editingMechanicId').value = m.MechanicID;
    document.getElementById('mechFullName').value = m.FullName || '';
    document.getElementById('mechPhone').value = m.Phone || '';
    document.getElementById('mechSpecialization').value = m.Specialization || '';
    document.getElementById('mechSalary').value = m.Salary || '';
    new bootstrap.Modal(document.getElementById('mechanicModal')).show();
}

document.getElementById('mechanicModal')?.addEventListener('hidden.bs.modal', function() {
    document.getElementById('mechanicModalTitle').innerHTML =
        '<i class="bi bi-person-plus" style="color:var(--primary-blue);"></i> Add Mechanic';
    document.getElementById('mechanicForm').reset();
    document.getElementById('editingMechanicId').value = '';
});

function saveMechanic(e) {
    e.preventDefault();
    const id = document.getElementById('editingMechanicId').value;
    const method = id ? 'PUT' : 'POST';
    const url = id ? '../../backend/api/jobs.php?resource=mechanics&id=' + id : '../../backend/api/jobs.php?resource=mechanics';

    const payload = {
        full_name: document.getElementById('mechFullName').value,
        phone: document.getElementById('mechPhone').value,
        specialization: document.getElementById('mechSpecialization').value,
        salary: document.getElementById('mechSalary').value
    };

    fetch(url, {
        method: method,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(res => res.json())
    .then(result => {
        if (result.success) {
            showToast(result.message || 'Mechanic saved!', 'success');
            bootstrap.Modal.getInstance(document.getElementById('mechanicModal')).hide();
            setTimeout(() => softReload(), 600);
        } else {
            showToast(result.message || 'Could not save.', 'danger');
        }
    })
    .catch(() => showToast('Network error.', 'danger'));
}

function deleteMechanic(id, name) {
    showConfirmModal('Delete Mechanic', `Are you sure you want to delete "${name}"?`, () => {
        fetch('../../backend/api/jobs.php?resource=mechanics&id=' + id, { method: 'DELETE' })
            .then(res => {
                if (!res.ok) throw new Error('Network response was not ok: ' + res.status);
                return res.json();
            })
            .then(result => {
                if (result.success) {
                    showToast('Mechanic removed.', 'danger');
                    setTimeout(() => softReload(), 600);
                } else {
                    showToast(result.message || 'Could not delete.', 'danger');
                }
            })
            .catch(err => {
                console.error('Delete mechanic error:', err);
                showToast('Network error. Please try again.', 'danger');
            });
    });
}

// 
// SUPPLIER CRUD
// 
function editSupplier(s) {
    document.getElementById('supplierModalTitle').innerHTML =
        '<i class="bi bi-pencil-square" style="color:var(--primary-blue);"></i> Edit Supplier';
    document.getElementById('editingSupplierId').value = s.SupplierID;
    document.getElementById('supCompanyName').value = s.CompanyName || '';
    document.getElementById('supPhone').value = s.Phone || '';
    document.getElementById('supEmail').value = s.Email || '';
    document.getElementById('supAddress').value = s.Address || '';
    new bootstrap.Modal(document.getElementById('supplierModal')).show();
}

document.getElementById('supplierModal')?.addEventListener('hidden.bs.modal', function() {
    document.getElementById('supplierModalTitle').innerHTML =
        '<i class="bi bi-truck-plus" style="color:var(--primary-blue);"></i> Add Supplier';
    document.getElementById('supplierForm').reset();
    document.getElementById('editingSupplierId').value = '';
});

function saveSupplier(e) {
    e.preventDefault();
    const id = document.getElementById('editingSupplierId').value;
    const method = id ? 'PUT' : 'POST';
    const url = id ? '../../backend/api/inventory.php?resource=suppliers&id=' + id : '../../backend/api/inventory.php?resource=suppliers';

    const payload = {
        company_name: document.getElementById('supCompanyName').value,
        phone: document.getElementById('supPhone').value,
        email: document.getElementById('supEmail').value,
        address: document.getElementById('supAddress').value
    };

    fetch(url, {
        method: method,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(res => res.json())
    .then(result => {
        if (result.success) {
            showToast(result.message || 'Supplier saved!', 'success');
            bootstrap.Modal.getInstance(document.getElementById('supplierModal')).hide();
            setTimeout(() => softReload(), 600);
        } else {
            showToast(result.message || 'Could not save.', 'danger');
        }
    })
    .catch(() => showToast('Network error.', 'danger'));
}

function deleteSupplier(id, name) {
    showConfirmModal('Delete Supplier', `Are you sure you want to delete "${name}"?`, () => {
        fetch('../../backend/api/inventory.php?resource=suppliers&id=' + id, { method: 'DELETE' })
            .then(res => {
                if (!res.ok) throw new Error('Network response was not ok: ' + res.status);
                return res.json();
            })
            .then(result => {
                if (result.success) {
                    showToast('Supplier removed.', 'danger');
                    setTimeout(() => softReload(), 600);
                } else {
                    showToast(result.message || 'Could not delete.', 'danger');
                }
            })
            .catch(err => {
                console.error('Delete supplier error:', err);
                showToast('Network error. Please try again.', 'danger');
            });
    });
}

// 
// CATEGORY CRUD
// 
function editCategory(c) {
    document.getElementById('categoryModalTitle').innerHTML =
        '<i class="bi bi-pencil-square" style="color:var(--primary-blue);"></i> Edit Category';
    document.getElementById('editingCategoryId').value = c.CategoryID;
    document.getElementById('catName').value = c.CategoryName || '';
    document.getElementById('catDescription').value = c.Description || '';
    new bootstrap.Modal(document.getElementById('categoryModal')).show();
}

document.getElementById('categoryModal')?.addEventListener('hidden.bs.modal', function() {
    document.getElementById('categoryModalTitle').innerHTML =
        '<i class="bi bi-tags" style="color:var(--primary-blue);"></i> Add Category';
    document.getElementById('categoryForm').reset();
    document.getElementById('editingCategoryId').value = '';
});

function saveCategory(e) {
    e.preventDefault();
    const id = document.getElementById('editingCategoryId').value;
    const method = id ? 'PUT' : 'POST';
    const url = id ? '../../backend/api/inventory.php?resource=categories&id=' + id : '../../backend/api/inventory.php?resource=categories';

    const payload = {
        category_name: document.getElementById('catName').value,
        description: document.getElementById('catDescription').value
    };

    fetch(url, {
        method: method,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(res => res.json())
    .then(result => {
        if (result.success) {
            showToast(result.message || 'Category saved!', 'success');
            bootstrap.Modal.getInstance(document.getElementById('categoryModal')).hide();
            setTimeout(() => softReload(), 600);
        } else {
            showToast(result.message || 'Could not save.', 'danger');
        }
    })
    .catch(() => showToast('Network error.', 'danger'));
}

function deleteCategory(id, name) {
    showConfirmModal('Delete Category', `Are you sure you want to delete "${name}"?`, () => {
        fetch('../../backend/api/inventory.php?resource=categories&id=' + id, { method: 'DELETE' })
            .then(res => {
                if (!res.ok) throw new Error('Network response was not ok: ' + res.status);
                return res.json();
            })
            .then(result => {
                if (result.success) {
                    showToast('Category removed.', 'danger');
                    setTimeout(() => softReload(), 600);
                } else {
                    showToast(result.message || 'Could not delete.', 'danger');
                }
            })
            .catch(err => {
                console.error('Delete category error:', err);
                showToast('Network error. Please try again.', 'danger');
            });
    });
}

// 
// PURCHASE CRUD
// 
function savePurchase(e) {
    e.preventDefault();
    const payload = {
        supplier_id: document.getElementById('purchaseSupplier').value,
        total_amount: document.getElementById('purchaseTotal').value
    };

    fetch('../../backend/api/inventory.php?resource=purchases', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(res => res.json())
    .then(result => {
        if (result.success) {
            showToast(result.message || 'Purchase created!', 'success');
            bootstrap.Modal.getInstance(document.getElementById('purchaseModal')).hide();
            setTimeout(() => softReload(), 600);
        } else {
            showToast(result.message || 'Could not save.', 'danger');
        }
    })
    .catch(() => showToast('Network error.', 'danger'));
}

function deletePurchase(id) {
    showConfirmModal('Delete Purchase', 'Are you sure you want to delete this purchase?', () => {
        fetch('../../backend/api/inventory.php?resource=purchases&id=' + id, { method: 'DELETE' })
            .then(res => {
                if (!res.ok) throw new Error('Network response was not ok: ' + res.status);
                return res.json();
            })
            .then(result => {
                if (result.success) {
                    showToast('Purchase removed.', 'danger');
                    setTimeout(() => softReload(), 600);
                } else {
                    showToast(result.message || 'Could not delete.', 'danger');
                }
            })
            .catch(err => {
                console.error('Delete purchase error:', err);
                showToast('Network error. Please try again.', 'danger');
            });
    });
}

function markNotificationRead(id) {
    fetch('../../backend/api/notifications.php?id=' + id + '&action=mark_read', { method: 'PUT' })
        .then(res => res.json())
        .then(result => {
            if (result.success) {
                showToast('Marked as read.', 'info');
                // Use softReload to ensure server-side rendered lists are updated
                if (typeof softReload === 'function') {
                    setTimeout(() => softReload(), 200);
                }
            } else {
                showToast(result.message || 'Could not mark as read.', 'danger');
            }
        })
        .catch(err => {
            console.error('Mark notification read error:', err);
            showToast(result?.message || 'Network error. Please try again.', 'danger');
        });
}

function markAllRead() {
    showConfirmModal('Mark All Notifications Read', 'Mark all notifications as read?', function() {
        fetch('../../backend/api/notifications.php?action=mark_all_read', { method: 'PUT' })
            .then(res => res.json())
            .then(result => {
                if (result.success) {
                    showToast('All marked as read.', 'info');
                    // Use softReload to ensure server-side rendered lists are updated
                    if (typeof softReload === 'function') {
                        setTimeout(() => softReload(), 200);
                    }
                } else {
                    showToast(result.message || 'Could not mark as read.', 'danger');
                }
            })
            .catch(err => {
                console.error('Mark all read error:', err);
                showToast('Network error. Please try again.', 'danger');
            });
    });
}

function updateNotificationBadge() {
    const unreadItems = document.querySelectorAll('.notif-item.unread');
    const count = unreadItems.length;
    
    // Update badge in sidebar
    const sidebarBadge = document.getElementById('nav-notifications')?.querySelector('.badge');
    if (sidebarBadge) {
        if (count > 0) {
            sidebarBadge.textContent = count;
            sidebarBadge.style.display = 'inline-block';
        } else {
            sidebarBadge.style.display = 'none';
        }
    }
    
    // Update badge in header
    const headerBadge = document.querySelector('.btn-action.position-relative .badge');
    if (headerBadge) {
        if (count > 0) {
            headerBadge.textContent = count;
            headerBadge.style.display = 'inline-block';
        } else {
            headerBadge.style.display = 'none';
        }
    }
}

// 
// CONTACT MESSAGES FUNCTIONS
// 
function markAllMessagesRead() {
    showConfirmModal('Mark All Messages Read', 'Mark all messages as read?', function() {
        fetch('../../backend/api/contactmessages.php?action=mark_all_read', { method: 'PUT' })
            .then(res => res.json())
            .then(result => {
                if (result.success) {
                    showToast('All messages marked as read.', 'info');
                    setTimeout(() => softReload(), 400);
                } else {
                    showToast(result.message || 'Could not mark as read.', 'danger');
                }
            })
            .catch(() => showToast('Network error.', 'danger'));
    });
}

function deleteMessage(id) {
    showConfirmModal('Delete Message', 'Are you sure you want to delete this message?', function() {
        fetch('../../backend/api/contactmessages.php?id=' + id, { method: 'DELETE' })
            .then(res => res.json())
            .then(result => {
                if (result.success) {
                    showToast('Message deleted.', 'success');
                    setTimeout(() => softReload(), 400);
                } else {
                    showToast(result.message || 'Could not delete message.', 'danger');
                }
            })
            .catch(() => showToast('Network error.', 'danger'));
    });
}

// 
// FILTER TABLE
// 
function filterTable() {
    const searchVal = (document.getElementById('globalSearch')?.value || '').toLowerCase().trim();
    const roleFilter = document.getElementById('userRoleFilter')?.value || '';
    const statusFilter = document.getElementById('userStatusFilter')?.value || '';
    const specialtyFilter = document.getElementById('mechanicSpecialtyFilter')?.value || '';
    const categoryFilter = document.getElementById('partCategoryFilter')?.value || '';

    const visibleTab = document.querySelector('.tab-content[style*="display:block"]') ||
        document.querySelector('.tab-content:not([style*="display:none"])');
    if (!visibleTab) return;

    const tabId = visibleTab.id;

    if (tabId === 'tab-users') {
        const rows = document.querySelectorAll('#userTable tbody tr');
        const userSearchVal = (document.getElementById('userSearch')?.value || '').toLowerCase().trim();
        const effectiveSearch = userSearchVal || searchVal;
        let visibleCount = 0;
        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            const role = (row.dataset.role || '').toLowerCase();
            const status = (row.dataset.status || '').toLowerCase();
            let match = true;
            if (effectiveSearch && !text.includes(effectiveSearch)) match = false;
            if (roleFilter && role !== roleFilter.toLowerCase()) match = false;
            if (statusFilter && status !== statusFilter.toLowerCase()) match = false;
            row.style.display = match ? '' : 'none';
            if (match) visibleCount++;
        });
        const countDisplay = document.getElementById('userCountDisplay');
        if (countDisplay) countDisplay.textContent = 'Showing ' + visibleCount + ' users';
        const footer = document.getElementById('userTableFooter');
        if (footer) footer.textContent = 'Showing 1-' + visibleCount + ' of ' + visibleCount + ' users';
    }

    if (tabId === 'tab-mechanics') {
        const search = (document.getElementById('mechanicSearch')?.value || '').toLowerCase().trim();
        const rows = document.querySelectorAll('#mechanicTable tbody tr');
        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            const specialty = row.dataset.specialty || '';
            let match = true;
            if (search && !text.includes(search)) match = false;
            if (specialtyFilter && specialty !== specialtyFilter) match = false;
            row.style.display = match ? '' : 'none';
        });
    }

    if (tabId === 'tab-suppliers') {
        const search = (document.getElementById('supplierSearch')?.value || '').toLowerCase().trim();
        const rows = document.querySelectorAll('#supplierTable tbody tr');
        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            let match = true;
            if (search && !text.includes(search)) match = false;
            row.style.display = match ? '' : 'none';
        });
    }

    if (tabId === 'tab-spare-parts') {
        const search = (document.getElementById('partSearch')?.value || '').toLowerCase().trim();
        const rows = document.querySelectorAll('#sparePartTable tbody tr');
        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            const cat = row.dataset.category || '';
            let match = true;
            if (search && !text.includes(search)) match = false;
            if (categoryFilter && cat !== categoryFilter) match = false;
            row.style.display = match ? '' : 'none';
        });
    }
}

// 
// PAGINATION
// (real prevPage()/nextPage() implementation lives in the UX
// enhancement layer near the bottom of this file - it paginates
// whichever table is currently visible / was clicked from).

// 
// INIT
// 
document.addEventListener('DOMContentLoaded', function() {
    // Event listeners for filter inputs
    const searchInput = document.getElementById('globalSearch');
    if (searchInput) searchInput.addEventListener('keyup', filterTable);

    ['userRoleFilter', 'userStatusFilter', 'mechanicSpecialtyFilter', 'partCategoryFilter'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('change', filterTable);
    });

    ['mechanicSearch', 'partSearch', 'supplierSearch'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('keyup', filterTable);
    });

    // Check URL hash for initial tab
    const hash = window.location.hash.replace('#', '');
    const validTabs = ['dashboard', 'notifications', 'users', 'mechanics', 'suppliers', 'spare-parts', 'reports', 'settings'];
    if (hash && validTabs.includes(hash)) {
        switchTab(hash, null);
    } else {
        switchTab('dashboard', null);
    }

    // Close sidebar on outside click (mobile)
    document.addEventListener('click', function(e) {
        const sidebar = document.getElementById('sidebar');
        const toggle = document.getElementById('sidebarToggle');
        if (!sidebar || !toggle) return;
        if (window.innerWidth <= 992) {
            if (!sidebar.contains(e.target) && !toggle.contains(e.target)) {
                closeSidebar();
            }
        }
    });
});
// NOTE: the 'keep submitted buttons busy' helper is defined once, at the
// end of main.js, and applies here too since this is all one file now.

/* 
   ADMIN DASHBOARD - page-specific behaviour
   (originally an inline <script> block in staff/Admin.php;
   staff/Admin.php still declares `currentUsername`/`unreadCount`
   inline from PHP before this file loads)
    */
(function () {
    if (document.body.dataset.page !== 'admin') return;

    function toggleMechanicFields() {
        const role = document.getElementById('roleSelect').value;
        const mechFields = document.getElementById('mechanicFields');
        if (role === 'Mechanic') {
            mechFields.style.display = 'block';
        } else {
            mechFields.style.display = 'none';
        }
    }
    window.toggleMechanicFields = toggleMechanicFields;

    function updateAdminProfile(e) {
        e.preventDefault();
        const fullName = document.getElementById('adminFullName').value.trim();
        const username = document.getElementById('adminUsername').value.trim();
        const email = document.getElementById('adminEmail').value.trim();
        const current = document.getElementById('adminCurrentPassword').value;

        if (!fullName || !username || !email) {
            showToast('Full name, username, and email are required.', 'danger');
            return;
        }
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            showToast('Please enter a valid email address.', 'danger');
            return;
        }
        if (username !== currentUsername && !current) {
            showToast('Current password is required to change username.', 'danger');
            return;
        }

        const formData = new FormData();
        formData.append('action', 'update_profile');
        formData.append('full_name', fullName);
        formData.append('username', username);
        formData.append('email', email);
        formData.append('current_password', current);
        formData.append('new_password', '');
        formData.append('confirm_password', '');

        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(result => {
            if (result.success) {
                showToast(result.message, 'success');
                document.querySelector('.user-name').textContent = fullName;
            } else {
                showToast(result.message, 'danger');
            }
        })
        .catch(() => showToast('Network error. Please try again.', 'danger'));
    }
    window.updateAdminProfile = updateAdminProfile;

    function updateAdminPassword(e) {
        e.preventDefault();
        const current = document.getElementById('adminCurrentPassword2').value;
        const newPass = document.getElementById('adminNewPassword').value;
        const confirm = document.getElementById('adminConfirmPassword').value;

        if (!current || !newPass || !confirm) {
            showToast('All password fields are required.', 'danger');
            return;
        }
        if (newPass.length < 6) {
            showToast('New password must be at least 6 characters.', 'danger');
            return;
        }
        if (newPass !== confirm) {
            showToast('New passwords do not match.', 'danger');
            return;
        }

        const formData = new FormData();
        formData.append('action', 'update_profile');
        formData.append('full_name', document.getElementById('adminFullName').value);
        formData.append('username', document.getElementById('adminUsername').value);
        formData.append('email', document.getElementById('adminEmail').value);
        formData.append('current_password', current);
        formData.append('new_password', newPass);
        formData.append('confirm_password', confirm);

        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(result => {
            if (result.success) {
                showToast(result.message, 'success');
                document.getElementById('adminPasswordForm').reset();
            } else {
                showToast(result.message, 'danger');
            }
        })
        .catch(() => showToast('Network error. Please try again.', 'danger'));
    }
    window.updateAdminPassword = updateAdminPassword;
})();

/* 
   MECHANIC DASHBOARD - page-specific behaviour
   (originally an inline <script> block in staff/Mechanic.php;
   staff/Mechanic.php still declares `assignedJobs`/`mechanicToday`
   inline from PHP before this file loads). Overrides the shared
   toggleSidebar/closeSidebar/switchTab/filterTable from the
   dashboard-wide section above, but only while on this page.
    */
(function () {
    if (document.body.dataset.page !== 'mechanic') return;

        // 
        // SIDEBAR TOGGLE
        // 
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
            document.getElementById('sidebarOverlay').classList.toggle('active');
        }
        function closeSidebar() {
            document.getElementById('sidebar').classList.remove('open');
            document.getElementById('sidebarOverlay').classList.remove('active');
        }
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeSidebar();
        });

        // 
        // TAB SWITCHING
        // 
        function switchTab(tab, e) {
            if (e) e.preventDefault();

            document.querySelectorAll('.tab-content').forEach(function(el) {
                el.style.display = 'none';
            });
            var target = document.getElementById('tab-' + tab);
            if (target) target.style.display = 'block';

            document.querySelectorAll('.sidebar-nav a').forEach(function(a) {
                a.classList.remove('active');
            });
            var navLink = document.getElementById('nav-' + tab);
            if (navLink) navLink.classList.add('active');

            var titles = {
                dashboard: 'Dashboard',
                assigned: 'My Jobs',
                parts: 'Request Parts',
                history: 'Job History',
                notifications: 'Notifications',
                settings: 'Settings'
            };
            var titleEl = document.getElementById('pageTitle');
            if (titleEl) titleEl.textContent = titles[tab] || tab.charAt(0).toUpperCase() + tab.slice(1);

            if (window.innerWidth <= 768) closeSidebar();

            var searchInput = document.getElementById('globalSearch');
            if (searchInput) searchInput.value = '';
        }

        // 
        // FILTER TABLE (global)
        // 
        function filterTable(input, tableId) {
            if (!tableId) {
                // If called from global search, determine active tab and its table
                var visibleTab = document.querySelector('.tab-content[style*="display:block"]') ||
                    document.querySelector('.tab-content:not([style*="display:none"])');
                if (!visibleTab) return;
                var tabId = visibleTab.id;
                var tableMap = {
                    'tab-assigned': 'assignedTable',
                    'tab-history': 'historyTable'
                };
                tableId = tableMap[tabId];
                if (!tableId) return;
                var inputEl = document.getElementById('globalSearch');
                if (!inputEl) return;
                var filter = inputEl.value.toLowerCase();
            } else {
                var filter = input.value.toLowerCase();
            }
            var table = document.getElementById(tableId);
            if (!table) return;
            var rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
            for (var i = 0; i < rows.length; i++) {
                var text = rows[i].textContent.toLowerCase();
                rows[i].style.display = text.indexOf(filter) > -1 ? '' : 'none';
            }
        }

        // 
        // UPDATE STATUS
        // 
        function updateStatus(select, jobId) {
            var newStatus = select.value;
            var numericId = String(jobId).replace('#JOB-', '').replace('#', '');
            var row = select.closest('tr');
            var badge = row ? row.querySelector('.badge-status') : null;
            var statusClasses = {
                'Pending': 'badge-pending',
                'Diagnosed': 'badge-diagnosed',
                'In Progress': 'badge-inprogress',
                'Awaiting Parts': 'badge-awaiting',
                'Ready': 'badge-ready',
                'Delivered': 'badge-delivered',
                'Cancelled': 'badge-pending'
            };

            // Persist the change through the API. The backend only allows a
            // mechanic to update jobs assigned to their own account, so this
            // will fail safely (403) if the job somehow isn't theirs.
            fetch('../../backend/api/jobs.php?resource=repairjobs&id=' + encodeURIComponent(numericId), {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ status: newStatus })
            })
            .then(res => res.json())
            .then(result => {
                if (!result.success) {
                    showToast(result.message || 'Could not update status.', 'danger');
                    return;
                }
                showToast('Job ' + jobId + ' updated to: ' + newStatus, 'success');
                if (badge) {
                    badge.className = 'badge-status ' + (statusClasses[newStatus] || 'badge-pending');
                    badge.textContent = newStatus;
                }
                if (typeof refreshCounters === 'function') refreshCounters();
            })
            .catch(() => showToast('Network error. Please try again.', 'danger'));
        }

        // 
        // RECORD DIAGNOSTICS
        // 
        function recordDiagnostics(jobId) {
            document.getElementById('diagJobId').value = jobId;
            new bootstrap.Modal(document.getElementById('diagnosticsModal')).show();
        }

        function saveDiagnostics(e) {
            e.preventDefault();
            const payload = {
                job_id: document.getElementById('diagJobId').value,
                notes: document.getElementById('diagNotes').value.trim()
            };

            fetch('../../backend/api/jobs.php?resource=diagnostics', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(res => res.json())
            .then(result => {
                if (!result.success) {
                    showToast(result.message || 'Could not save notes.', 'danger');
                    return;
                }
                showToast(result.message, 'success');
                bootstrap.Modal.getInstance(document.getElementById('diagnosticsModal')).hide();
                document.getElementById('diagnosticsForm').reset();
                // Bug fix: this legacy copy of saveDiagnostics() (mechanic.php
                // also defines one inline that already does this) never
                // refreshed anything after a successful save, and because
                // main.js loads after that inline script, this version's
                // `window.saveDiagnostics` assignment further down was the
                // one actually left in place -- so notes appeared saved but
                // the job history/table never showed the update until a
                // manual page reload. Soft-refresh in place instead.
                if (typeof softReload === 'function') softReload();
            })
            .catch(() => showToast('Network error. Please try again.', 'danger'));
        }

        document.addEventListener('DOMContentLoaded', function() {
            const body = document.querySelector('#assignedTable tbody');
            if (!body || assignedJobs.length === 0) return;

            const statuses = ['Pending', 'Diagnosed', 'In Progress', 'Awaiting Parts', 'Ready', 'Delivered', 'Cancelled'];
            const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[char]));
            body.innerHTML = assignedJobs.map(job => `
                <tr>
                    <td>${job.JobID}</td>
                    <td>${escapeHtml(job.PlateNumber || '-')}</td>
                    <td>${escapeHtml(job.CustomerName || '-')}</td>
                    <td><span class="badge-status badge-pending">${escapeHtml(job.Status || 'Pending')}</span></td>
                    <td><select class="form-select form-control-custom" style="width:auto;font-size:0.82rem;" onchange="updateStatus(this, ${job.JobID})">${statuses.map(status => `<option value="${status}" ${job.Status === status ? 'selected' : ''}>${status}</option>`).join('')}</select></td>
                    <td><button class="btn-action edit" onclick="recordDiagnostics(${job.JobID})" title="Record diagnostics"><i class="bi bi-clipboard2-plus"></i></button></td>
                </tr>`).join('');
        });

        // 
        // SPARE PART REQUESTS
        // 
        function submitPartRequest(e) {
            e.preventDefault();
            const payload = {
                spare_part_id: document.getElementById('requestSparePartId').value,
                quantity_requested: document.getElementById('requestQuantity').value,
                job_id: document.getElementById('requestJobId').value || null,
                reason: document.getElementById('requestReason').value
            };

            fetch('../../backend/api/inventory.php?resource=sparepartrequests', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(res => res.json())
            .then(result => {
                if (result.success) {
                    showToast(result.message || 'Request submitted!', 'success');
                    document.getElementById('requestSparePartId').closest('form').reset();
                    loadPartRequests();
                } else {
                    showToast(result.message || 'Could not submit request.', 'danger');
                }
            })
            .catch(() => showToast('Network error.', 'danger'));
        }

        function loadPartRequests() {
            fetch('../../backend/api/inventory.php?resource=sparepartrequests')
                .then(res => res.json())
                .then(result => {
                    if (result.success) {
                        const tbody = document.getElementById('requestsTableBody');
                        if (result.data.length === 0) {
                            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">No requests found.</td></tr>';
                            return;
                        }
                        tbody.innerHTML = result.data.map(req => {
                            const statusClass = {
                                'Pending': 'badge-low',
                                'Approved': 'badge-info',
                                'Rejected': 'badge-danger',
                                'Fulfilled': 'badge-ok'
                            }[req.Status] || 'badge-pending';
                            const canCancel = req.Status === 'Pending';
                            return `<tr>
                                <td>${req.RequestID}</td>
                                <td>${req.SparePartName || 'N/A'}</td>
                                <td>${req.QuantityRequested}</td>
                                <td>${req.Reason || '-'}</td>
                                <td><span class="badge-status ${statusClass}">${req.Status}</span></td>
                                <td>${new Date(req.RequestedAt).toLocaleDateString()}</td>
                                <td>
                                    ${canCancel ? `<button class="btn-action delete" onclick="cancelPartRequest(${req.RequestID})"><i class="bi bi-trash"></i></button>` : '-'}
                                </td>
                            </tr>`;
                        }).join('');
                    }
                })
                .catch(() => {
                    document.getElementById('requestsTableBody').innerHTML = '<tr><td colspan="7" class="text-center text-danger">Failed to load requests.</td></tr>';
                });
        }

        function cancelPartRequest(id) {
            showConfirmModal('Cancel Request', 'Are you sure you want to cancel this request?', function() {
                fetch('../../backend/api/inventory.php?resource=sparepartrequests&id=' + id, { method: 'DELETE' })
                    .then(res => res.json())
                    .then(result => {
                        if (result.success) {
                            showToast(result.message || 'Request cancelled.', 'success');
                            loadPartRequests();
                        } else {
                            showToast(result.message || 'Could not cancel request.', 'danger');
                        }
                    })
                    .catch(() => showToast('Network error.', 'danger'));
            });
        }

        // 
        // INIT - default tab
        // 
        document.addEventListener('DOMContentLoaded', function() {
            switchTab('dashboard', null);
            loadPartRequests();
        });

        // Click outside sidebar to close on mobile
        document.addEventListener('click', function(e) {
            var sidebar = document.getElementById('sidebar');
            var toggle = document.getElementById('sidebarToggle');
            if (window.innerWidth <= 768) {
                if (!sidebar.contains(e.target) && !toggle.contains(e.target)) {
                    closeSidebar();
                }
            }
        });

    window.toggleSidebar = toggleSidebar;
    window.closeSidebar = closeSidebar;
    window.switchTab = switchTab;
    window.filterTable = filterTable;
    window.updateStatus = updateStatus;
    window.recordDiagnostics = recordDiagnostics;
    window.saveDiagnostics = saveDiagnostics;
    window.submitPartRequest = submitPartRequest;
    window.loadPartRequests = loadPartRequests;
    window.cancelPartRequest = cancelPartRequest;
})();

/* 
   RECEPTIONIST DASHBOARD - page-specific behaviour
   (originally an inline <script> block in staff/Receptionist.php;
   staff/Receptionist.php still declares `receptionistUsername`
   inline from PHP before this file loads). Does not override the
   shared toggleSidebar/closeSidebar/switchTab/filterTable/
   notification functions - those come from the dashboard-wide
   section above, unchanged.
    */
(function () {
    if (document.body.dataset.page !== 'receptionist') return;

        // 
        // OVERRIDE / EXTEND staff.js FUNCTIONS FOR RECEPTIONIST
        // 

        // ---- CUSTOMER CRUD ----
        function editCustomer(customer) {
            document.getElementById('customerModalTitle').innerHTML = '<i class="bi bi-pencil-square" style="color:var(--primary-blue);"></i> Edit Customer';
            document.getElementById('editingCustomerId').value = customer.CustomerID;
            document.getElementById('customerFullName').value = customer.FullName || '';
            document.getElementById('customerPhone').value = customer.Phone || '';
            document.getElementById('customerEmail').value = customer.Email || '';
            document.getElementById('customerAddress').value = customer.Address || '';
            new bootstrap.Modal(document.getElementById('customerModal')).show();
        }

        document.getElementById('customerModal').addEventListener('hidden.bs.modal', function() {
            document.getElementById('customerModalTitle').innerHTML = '<i class="bi bi-person-plus" style="color:var(--primary-blue);"></i> Add Customer';
            document.getElementById('customerForm').reset();
            document.getElementById('editingCustomerId').value = '';
        });

        function saveCustomer(e) {
            e.preventDefault();
            const form = e.target;
            const submitBtn = form.querySelector('button[type="submit"]');
            showLoading(submitBtn);
            
            const id = document.getElementById('editingCustomerId').value;
            const payload = {
                full_name: document.getElementById('customerFullName').value,
                phone: document.getElementById('customerPhone').value,
                email: document.getElementById('customerEmail').value,
                address: document.getElementById('customerAddress').value
            };

            const url = id ? ('../../backend/api/customers.php?resource=customers&id=' + id) : '../../backend/api/customers.php?resource=customers';
            const method = id ? 'PUT' : 'POST';

            fetch(url, {
                method: method,
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(res => res.json())
            .then(result => {
                if (result.success) {
                    showToast(result.message || 'Customer saved!', 'success');
                    bootstrap.Modal.getInstance(document.getElementById('customerModal')).hide();
                    // Direct DOM update for better performance
                    updateCustomersTable();
                } else {
                    showToast(result.message || 'Could not save customer.', 'danger');
                }
            })
            .catch(() => showToast('Network error.', 'danger'))
            .finally(() => {
                hideLoading(submitBtn);
            });
        }

        function updateCustomersTable() {
            fetch('../../backend/api/customers.php?resource=customers')
                .then(res => res.json())
                .then(result => {
                    if (result.success && Array.isArray(result.data)) {
                        const tbody = document.querySelector('#customersTable tbody');
                        if (!tbody) return;
                        
                        tbody.innerHTML = result.data.map(c => {
                            return `<tr>
                                <td>${c.CustomerID}</td>
                                <td>${safe(c.FullName)}</td>
                                <td>${safe(c.Phone)}</td>
                                <td>${safe(c.Email)}</td>
                                <td>
                                    <button class="btn-action view" onclick="viewCustomer(${c.CustomerID})"><i class="bi bi-eye"></i></button>
                                    <button class="btn-action edit" onclick="editCustomer(${JSON.stringify(c).replace(/"/g, '&quot;')})"><i class="bi bi-pencil"></i></button>
                                    <button class="btn-action delete" onclick="deleteCustomer(${c.CustomerID}, '${safe(c.FullName).replace(/'/g, "\\'")}')"><i class="bi bi-trash"></i></button>
                                </td>
                            </tr>`;
                        }).join('');
                        
                        bindSearchInputs();
                        document.querySelectorAll('table').forEach(initPaging);
                        if (typeof refreshCounters === 'function') refreshCounters();
                    }
                })
                .catch(err => {
                    console.error('Update customers table error:', err);
                    if (typeof softReload === 'function') softReload();
                });
        }

        function deleteCustomer(id, name) {
            showConfirmModal('Delete Customer', `Are you sure you want to delete "${name}"?`, () => {
                fetch('../../backend/api/customers.php?resource=customers&id=' + id, { method: 'DELETE' })
                    .then(res => {
                        if (!res.ok) throw new Error('Network response was not ok: ' + res.status);
                        return res.json();
                    })
                    .then(result => {
                        if (result.success) {
                            showToast('Customer removed.', 'danger');
                            updateCustomersTable();
                        } else {
                            showToast(result.message || 'Could not delete.', 'danger');
                        }
                    })
                    .catch(err => {
                        console.error('Delete customer error:', err);
                        showToast('Network error. Please try again.', 'danger');
                    });
            });
        }

        function viewCustomer(id) {
            fetch('../../backend/api/customers.php?resource=customers&id=' + id)
                .then(res => res.json())
                .then(result => {
                    if (result.success && result.data) {
                        const c = result.data;
                        const modalHtml = `
                            <div class="modal fade modal-custom" id="viewCustomerModal" tabindex="-1">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title"><i class="bi bi-person" style="color:var(--primary-blue);"></i> Customer Details</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <div class="view-details-grid">
                                                <div class="vd-item"><strong>Full Name:</strong> ${c.FullName || '-'}</div>
                                                <div class="vd-item"><strong>Phone:</strong> ${c.Phone || '-'}</div>
                                                <div class="vd-item"><strong>Email:</strong> ${c.Email || '-'}</div>
                                                <div class="vd-item"><strong>Address:</strong> ${c.Address || '-'}</div>
                                                <div class="vd-item vd-full"><strong>Customer ID:</strong> ${c.CustomerID}</div>
                                            </div>
                                        </div>
                                        <div class="modal-footer view-footer">
                                            <button type="button" class="btn-outline-blue btn-sm" data-bs-dismiss="modal">Close</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        `;
                        // Remove existing modal if present
                        const existingModal = document.getElementById('viewCustomerModal');
                        if (existingModal) existingModal.remove();
                        document.body.insertAdjacentHTML('beforeend', modalHtml);
                        new bootstrap.Modal(document.getElementById('viewCustomerModal')).show();
                    } else {
                        showToast('Could not load customer details.', 'danger');
                    }
                })
                .catch(() => showToast('Network error.', 'danger'));
        }

        // ---- VEHICLE CRUD ----
        function editVehicle(vehicle) {
            document.getElementById('vehicleModalTitle').innerHTML = '<i class="bi bi-pencil-square" style="color:var(--primary-blue);"></i> Edit Vehicle';
            document.getElementById('editingVehicleId').value = vehicle.VehicleID;
            document.getElementById('vehicleOwner').value = vehicle.CustomerID || '';
            document.getElementById('vehiclePlate').value = vehicle.PlateNumber || '';
            document.getElementById('vehicleMake').value = vehicle.Manufacturer || '';
            document.getElementById('vehicleModel').value = vehicle.Model || '';
            document.getElementById('vehicleYear').value = vehicle.Year || '';
            document.getElementById('vehicleChassis').value = vehicle.ChassisNumber || '';
            document.getElementById('vehicleEngine').value = vehicle.EngineNumber || '';
            document.getElementById('vehicleFuel').value = vehicle.FuelType || 'Petrol';
            document.getElementById('vehicleTransmission').value = vehicle.Transmission || 'Manual';
            document.getElementById('vehicleMileage').value = vehicle.Mileage || '';
            new bootstrap.Modal(document.getElementById('vehicleModal')).show();
        }

        document.getElementById('vehicleModal').addEventListener('hidden.bs.modal', function() {
            document.getElementById('vehicleModalTitle').innerHTML = '<i class="bi bi-car-front" style="color:var(--primary-blue);"></i> Add Vehicle';
            document.getElementById('vehicleForm').reset();
            document.getElementById('editingVehicleId').value = '';
        });

        function saveVehicle(e) {
            e.preventDefault();
            const form = e.target;
            const submitBtn = form.querySelector('button[type="submit"]');
            showLoading(submitBtn);
            
            const id = document.getElementById('editingVehicleId').value;
            const payload = {
                customer_id: document.getElementById('vehicleOwner').value,
                plate_number: document.getElementById('vehiclePlate').value,
                manufacturer: document.getElementById('vehicleMake').value,
                model: document.getElementById('vehicleModel').value,
                year: document.getElementById('vehicleYear').value,
                chassis_number: document.getElementById('vehicleChassis').value,
                engine_number: document.getElementById('vehicleEngine').value,
                fuel_type: document.getElementById('vehicleFuel').value,
                transmission: document.getElementById('vehicleTransmission').value,
                mileage: document.getElementById('vehicleMileage').value
            };

            const url = id ? ('../../backend/api/customers.php?resource=vehicles&id=' + id) : '../../backend/api/customers.php?resource=vehicles';
            const method = id ? 'PUT' : 'POST';

            fetch(url, {
                method: method,
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(res => res.json())
            .then(result => {
                if (result.success) {
                    showToast(result.message || 'Vehicle saved!', 'success');
                    bootstrap.Modal.getInstance(document.getElementById('vehicleModal')).hide();
                    // Direct DOM update for better performance
                    updateVehiclesTable();
                } else {
                    showToast(result.message || 'Could not save vehicle.', 'danger');
                }
            })
            .catch(() => showToast('Network error.', 'danger'))
            .finally(() => {
                hideLoading(submitBtn);
            });
        }

        function updateVehiclesTable() {
            fetch('../../backend/api/customers.php?resource=vehicles')
                .then(res => res.json())
                .then(result => {
                    if (result.success && Array.isArray(result.data)) {
                        const tbody = document.querySelector('#vehiclesTable tbody');
                        if (!tbody) return;
                        
                        tbody.innerHTML = result.data.map(v => {
                            return `<tr>
                                <td>${v.VehicleID}</td>
                                <td>${safe(v.PlateNumber)}</td>
                                <td>${safe(v.Manufacturer)} ${safe(v.Model)}</td>
                                <td>${v.Year}</td>
                                <td>
                                    <button class="btn-action view" onclick="viewVehicle(${v.VehicleID})"><i class="bi bi-eye"></i></button>
                                    <button class="btn-action edit" onclick="editVehicle(${JSON.stringify(v).replace(/"/g, '&quot;')})"><i class="bi bi-pencil"></i></button>
                                    <button class="btn-action delete" onclick="deleteVehicle(${v.VehicleID}, '${safe(v.PlateNumber).replace(/'/g, "\\'")}')"><i class="bi bi-trash"></i></button>
                                </td>
                            </tr>`;
                        }).join('');
                        
                        bindSearchInputs();
                        document.querySelectorAll('table').forEach(initPaging);
                        if (typeof refreshCounters === 'function') refreshCounters();
                    }
                })
                .catch(err => {
                    console.error('Update vehicles table error:', err);
                    if (typeof softReload === 'function') softReload();
                });
        }

        function deleteVehicle(id, plate) {
            showConfirmModal('Delete Vehicle', `Are you sure you want to delete "${plate}"?`, () => {
                fetch('../../backend/api/customers.php?resource=vehicles&id=' + id, { method: 'DELETE' })
                    .then(res => res.json())
                    .then(result => {
                        if (result.success) {
                            showToast('Vehicle removed.', 'danger');
                            updateVehiclesTable();
                        } else {
                            showToast(result.message || 'Could not delete.', 'danger');
                        }
                    })
                    .catch(err => {
                        console.error('Delete vehicle error:', err);
                        showToast('Network error. Please try again.', 'danger');
                    });
            });
        }

        function viewVehicle(id) {
            fetch('../../backend/api/customers.php?resource=vehicles&id=' + id)
                .then(res => res.json())
                .then(result => {
                    if (result.success && result.data) {
                        const v = result.data;
                        const modalHtml = `
                            <div class="modal fade modal-custom" id="viewVehicleModal" tabindex="-1">
                                <div class="modal-dialog modal-dialog-centered modal-lg">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title"><i class="bi bi-car-front" style="color:var(--primary-blue);"></i> Vehicle Details</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <div class="view-details-grid">
                                                <div class="vd-item"><strong>Plate Number:</strong> ${v.PlateNumber || '-'}</div>
                                                <div class="vd-item"><strong>Manufacturer:</strong> ${v.Manufacturer || '-'}</div>
                                                <div class="vd-item"><strong>Model:</strong> ${v.Model || '-'}</div>
                                                <div class="vd-item"><strong>Year:</strong> ${v.Year || '-'}</div>
                                                <div class="vd-item"><strong>Fuel Type:</strong> ${v.FuelType || '-'}</div>
                                                <div class="vd-item"><strong>Transmission:</strong> ${v.Transmission || '-'}</div>
                                                <div class="vd-item"><strong>Mileage:</strong> ${v.Mileage || '-'}</div>
                                                <div class="vd-item"><strong>Chassis Number:</strong> ${v.ChassisNumber || '-'}</div>
                                                <div class="vd-item"><strong>Engine Number:</strong> ${v.EngineNumber || '-'}</div>
                                                <div class="vd-item"><strong>Owner:</strong> ${v.OwnerName || '-'}</div>
                                                <div class="vd-item vd-full"><strong>Vehicle ID:</strong> ${v.VehicleID}</div>
                                            </div>
                                        </div>
                                        <div class="modal-footer view-footer">
                                            <button type="button" class="btn-outline-blue btn-sm" data-bs-dismiss="modal">Close</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        `;
                        const existingModal = document.getElementById('viewVehicleModal');
                        if (existingModal) existingModal.remove();
                        document.body.insertAdjacentHTML('beforeend', modalHtml);
                        new bootstrap.Modal(document.getElementById('viewVehicleModal')).show();
                    } else {
                        showToast('Could not load vehicle details.', 'danger');
                    }
                })
                .catch(() => showToast('Network error.', 'danger'));
        }

        // ---- REPAIR JOB CRUD ----
        function editJob(job) {
            document.getElementById('jobModalTitle').innerHTML = '<i class="bi bi-pencil-square" style="color:var(--primary-blue);"></i> Edit Repair Job';
            document.getElementById('editingJobId').value = job.JobID;
            document.getElementById('jobVehicle').value = job.VehicleID || '';
            document.getElementById('jobMechanic').value = job.MechanicID || '';
            document.getElementById('jobStartDate').value = job.StartDate || '';
            document.getElementById('jobEndDate').value = job.EndDate || '';
            document.getElementById('jobStatus').value = job.Status || 'Pending';
            new bootstrap.Modal(document.getElementById('jobModal')).show();
        }

        document.getElementById('jobModal').addEventListener('hidden.bs.modal', function() {
            document.getElementById('jobModalTitle').innerHTML = '<i class="bi bi-tools" style="color:var(--primary-blue);"></i> Add Repair Job';
            document.getElementById('jobForm').reset();
            document.getElementById('editingJobId').value = '';
        });

        function saveJob(e) {
            e.preventDefault();
            const form = e.target;
            const submitBtn = form.querySelector('button[type="submit"]');
            showLoading(submitBtn);
            
            const id = document.getElementById('editingJobId').value;
            const payload = {
                vehicle_id: document.getElementById('jobVehicle').value,
                mechanic_id: document.getElementById('jobMechanic').value,
                start_date: document.getElementById('jobStartDate').value,
                end_date: document.getElementById('jobEndDate').value,
                status: document.getElementById('jobStatus').value
            };

            const url = id ? ('../../backend/api/jobs.php?resource=repairjobs&id=' + id) : '../../backend/api/jobs.php?resource=repairjobs';
            const method = id ? 'PUT' : 'POST';

            fetch(url, {
                method: method,
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(res => res.json())
            .then(result => {
                if (result.success) {
                    showToast(result.message || 'Repair job saved!', 'success');
                    bootstrap.Modal.getInstance(document.getElementById('jobModal')).hide();
                    // Direct DOM update for better performance
                    updateJobsTable();
                } else {
                    showToast(result.message || 'Could not save job.', 'danger');
                }
            })
            .catch(() => showToast('Network error.', 'danger'))
            .finally(() => {
                hideLoading(submitBtn);
            });
        }

        function updateJobsTable() {
            fetch('../../backend/api/jobs.php?resource=repairjobs')
                .then(res => res.json())
                .then(result => {
                    if (result.success && Array.isArray(result.data)) {
                        const tbody = document.querySelector('#jobsTable tbody');
                        if (!tbody) return;
                        
                        tbody.innerHTML = result.data.map(j => {
                            const statusBadge = j.Status === 'Pending' ? 'badge-pending' : 
                                              j.Status === 'In Progress' ? 'badge-info' :
                                              j.Status === 'Completed' ? 'badge-ok' : 'badge-low';
                            return `<tr>
                                <td>${j.JobID}</td>
                                <td>${safe(j.PlateNumber)}</td>
                                <td>${safe(j.CustomerName)}</td>
                                <td><span class="badge-status ${statusBadge}">${safe(j.Status)}</span></td>
                                <td>
                                    <button class="btn-action view" onclick="viewJob(${j.JobID})"><i class="bi bi-eye"></i></button>
                                    <button class="btn-action edit" onclick="editJob(${JSON.stringify(j).replace(/"/g, '&quot;')})"><i class="bi bi-pencil"></i></button>
                                    <button class="btn-action delete" onclick="deleteJob(${j.JobID}, 'Job ${j.JobID}')"><i class="bi bi-trash"></i></button>
                                </td>
                            </tr>`;
                        }).join('');
                        
                        bindSearchInputs();
                        document.querySelectorAll('table').forEach(initPaging);
                        if (typeof refreshCounters === 'function') refreshCounters();
                    }
                })
                .catch(err => {
                    console.error('Update jobs table error:', err);
                    if (typeof softReload === 'function') softReload();
                });
        }

        function deleteJob(id, label) {
            showConfirmModal('Delete Repair Job', `Are you sure you want to delete "${label}"?`, () => {
                fetch('../../backend/api/jobs.php?resource=repairjobs&id=' + id, { method: 'DELETE' })
                    .then(res => {
                        if (!res.ok) throw new Error('Network response was not ok: ' + res.status);
                        return res.json();
                    })
                    .then(result => {
                        if (result.success) {
                            showToast('Repair job removed.', 'danger');
                            updateJobsTable();
                        } else {
                            showToast(result.message || 'Could not delete.', 'danger');
                        }
                    })
                    .catch(err => {
                        console.error('Delete job error:', err);
                        showToast('Network error. Please try again.', 'danger');
                    });
            });
        }

        function viewJob(id) {
            fetch('../../backend/api/jobs.php?resource=repairjobs&id=' + id)
                .then(res => res.json())
                .then(result => {
                    if (result.success && result.data) {
                        const j = result.data;
                        const modalHtml = `
                            <div class="modal fade modal-custom" id="viewJobModal" tabindex="-1">
                                <div class="modal-dialog modal-dialog-centered modal-lg">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title"><i class="bi bi-clipboard-check" style="color:var(--primary-blue);"></i> Repair Job Details</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <div class="view-details-grid">
                                                <div class="vd-item"><strong>Job ID:</strong> ${j.JobID}</div>
                                                <div class="vd-item"><strong>Status:</strong> ${j.Status || '-'}</div>
                                                <div class="vd-item"><strong>Start Date:</strong> ${j.StartDate ? new Date(j.StartDate).toLocaleDateString() : '-'}</div>
                                                <div class="vd-item"><strong>End Date:</strong> ${j.EndDate ? new Date(j.EndDate).toLocaleDateString() : '-'}</div>
                                                <div class="vd-item"><strong>Customer:</strong> ${j.CustomerName || '-'}</div>
                                                <div class="vd-item"><strong>Vehicle:</strong> ${[j.PlateNumber, j.VehicleModel].filter(Boolean).join(' — ') || '-'}</div>
                                                <div class="vd-item"><strong>Mechanic:</strong> ${j.MechanicName || '-'}</div>
                                                <div class="vd-item"><strong>Vehicle ID:</strong> ${j.VehicleID || '-'}</div>
                                                <div class="vd-item"><strong>Mechanic ID:</strong> ${j.MechanicID || '-'}</div>
                                                <div class="vd-item"><strong>User ID:</strong> ${j.UserID || '-'}</div>
                                            </div>
                                        </div>
                                        <div class="modal-footer view-footer">
                                            <button type="button" class="btn-outline-blue btn-sm" data-bs-dismiss="modal">Close</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        `;
                        const existingModal = document.getElementById('viewJobModal');
                        if (existingModal) existingModal.remove();
                        document.body.insertAdjacentHTML('beforeend', modalHtml);
                        new bootstrap.Modal(document.getElementById('viewJobModal')).show();
                    } else {
                        showToast('Could not load job details.', 'danger');
                    }
                })
                .catch(() => showToast('Network error.', 'danger'));
        }

        // ---- INVOICE CRUD (updated) ----
        function editInvoice(invoice) {
            document.getElementById('invoiceModalTitle').innerHTML = '<i class="bi bi-pencil-square" style="color:var(--primary-blue);"></i> Edit Invoice';
            document.getElementById('editingInvoiceId').value = invoice.InvoiceID;
            document.getElementById('invoiceCustomer').value = invoice.CustomerID || '';
            document.getElementById('invoiceJob').value = invoice.JobID || '';
            document.getElementById('invoiceVehicle').value = invoice.VehicleID || '';
            document.getElementById('invoiceDate').value = invoice.InvoiceDate || '';
            document.getElementById('invoiceLabour').value = invoice.LabourCharges || 0;
            document.getElementById('invoiceParts').value = invoice.SparePartsCost || 0;
            document.getElementById('invoiceTaxRate').value = invoice.TaxRate || 18;
            document.getElementById('invoiceTaxes').value = invoice.Taxes || 0;
            document.getElementById('invoiceDiscountRate').value = invoice.DiscountRate || 0;
            document.getElementById('invoiceDiscounts').value = invoice.Discounts || 0;
            calculateTotal();
            new bootstrap.Modal(document.getElementById('invoiceModal')).show();
        }

        document.getElementById('invoiceModal').addEventListener('hidden.bs.modal', function() {
            document.getElementById('invoiceModalTitle').innerHTML = '<i class="bi bi-receipt" style="color:var(--primary-blue);"></i> Add Invoice';
            document.getElementById('invoiceForm').reset();
            document.getElementById('editingInvoiceId').value = '';
            document.getElementById('invoiceTotal').value = '';
        });

        function calculateTotal() {
            const labour = parseFloat(document.getElementById('invoiceLabour').value) || 0;
            const parts = parseFloat(document.getElementById('invoiceParts').value) || 0;
            const taxRate = parseFloat(document.getElementById('invoiceTaxRate').value) || 0;
            const taxAmountField = parseFloat(document.getElementById('invoiceTaxes').value) || 0;
            const discountRate = parseFloat(document.getElementById('invoiceDiscountRate').value) || 0;
            const discountAmountField = parseFloat(document.getElementById('invoiceDiscounts').value) || 0;
            
            // Calculate tax: if tax amount is manually entered, use it; otherwise calculate from rate
            const subtotal = labour + parts;
            const taxAmount = taxAmountField > 0 ? taxAmountField : subtotal * (taxRate / 100);
            document.getElementById('invoiceTaxes').value = taxAmount.toFixed(2);
            
            // Calculate discount: if discount amount is manually entered, use it; otherwise calculate from rate
            const afterTax = subtotal + taxAmount;
            const discountAmount = discountAmountField > 0 ? discountAmountField : afterTax * (discountRate / 100);
            document.getElementById('invoiceDiscounts').value = discountAmount.toFixed(2);
            
            const total = subtotal + taxAmount - discountAmount;
            document.getElementById('invoiceTotal').value = total.toFixed(2);
        }

        function saveInvoice(e) {
            e.preventDefault();
            const form = e.target;
            const submitBtn = form.querySelector('button[type="submit"]');
            showLoading(submitBtn);
            
            const id = document.getElementById('editingInvoiceId').value;
            const payload = {
                customer_id: document.getElementById('invoiceCustomer').value,
                job_id: document.getElementById('invoiceJob').value || null,
                vehicle_id: document.getElementById('invoiceVehicle').value || null,
                invoice_date: document.getElementById('invoiceDate').value,
                labour_charges: document.getElementById('invoiceLabour').value || 0,
                spare_parts_cost: document.getElementById('invoiceParts').value || 0,
                tax_rate: document.getElementById('invoiceTaxRate').value || 0,
                tax_amount: document.getElementById('invoiceTaxes').value || 0,
                discount_rate: document.getElementById('invoiceDiscountRate').value || 0,
                discount_amount: document.getElementById('invoiceDiscounts').value || 0
            };

            const url = id ? ('../../backend/api/billing.php?resource=invoices&id=' + id) : '../../backend/api/billing.php?resource=invoices';
            const method = id ? 'PUT' : 'POST';

            fetch(url, {
                method: method,
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(res => res.json())
            .then(result => {
                if (result.success) {
                    showToast(result.message || 'Invoice saved!', 'success');
                    bootstrap.Modal.getInstance(document.getElementById('invoiceModal')).hide();
                    // Direct DOM update for better performance
                    updateInvoicesTable();
                } else {
                    showToast(result.message || 'Could not save invoice.', 'danger');
                }
            })
            .catch(() => showToast('Network error.', 'danger'))
            .finally(() => {
                hideLoading(submitBtn);
            });
        }

        function updateInvoicesTable() {
            fetch('../../backend/api/billing.php?resource=invoices')
                .then(res => res.json())
                .then(result => {
                    if (result.success && Array.isArray(result.data)) {
                        const tbody = document.querySelector('#invoicesTable tbody');
                        if (!tbody) return;
                        
                        tbody.innerHTML = result.data.map(inv => {
                            const statusBadge = inv.Status === 'Paid' ? 'badge-ok' : 
                                              inv.Status === 'Pending' ? 'badge-pending' : 'badge-low';
                            return `<tr>
                                <td>${inv.InvoiceID}</td>
                                <td>${inv.InvoiceDate || '-'}</td>
                                <td>${safe(inv.CustomerName || '-')}</td>
                                <td>${safe(inv.PlateNumber || '-')}</td>
                                <td>${number_format(inv.TotalAmount)} RWF</td>
                                <td><span class="badge-status ${statusBadge}">${safe(inv.Status)}</span></td>
                                <td>
                                    <button class="btn-action view" onclick="printInvoiceDirectly(${inv.InvoiceID})"><i class="bi bi-printer"></i></button>
                                    <button class="btn-action edit" onclick="editInvoice(${JSON.stringify(inv).replace(/"/g, '&quot;')})"><i class="bi bi-pencil"></i></button>
                                    <button class="btn-action delete" onclick="deleteInvoice(${inv.InvoiceID}, 'Invoice ${inv.InvoiceID}')"><i class="bi bi-trash"></i></button>
                                </td>
                            </tr>`;
                        }).join('');
                        
                        bindSearchInputs();
                        document.querySelectorAll('table').forEach(initPaging);
                        if (typeof refreshCounters === 'function') refreshCounters();
                    }
                })
                .catch(err => {
                    console.error('Update invoices table error:', err);
                    if (typeof softReload === 'function') softReload();
                });
        }

        function deleteInvoice(id, label) {
            showConfirmModal('Delete Invoice', `Are you sure you want to delete "${label}"?`, () => {
                fetch('../../backend/api/billing.php?resource=invoices&id=' + id, { method: 'DELETE' })
                    .then(res => {
                        if (!res.ok) throw new Error('Network response was not ok: ' + res.status);
                        return res.json();
                    })
                    .then(result => {
                        if (result.success) {
                            showToast('Invoice removed.', 'danger');
                            updateInvoicesTable();
                        } else {
                            showToast(result.message || 'Could not delete.', 'danger');
                        }
                    })
                    .catch(err => {
                        console.error('Delete invoice error:', err);
                        showToast('Network error. Please try again.', 'danger');
                    });
            });
        }

        // ---- View Invoice (detailed) ----
        function viewInvoice(id) {
            fetch('../../backend/api/billing.php?resource=invoices&id=' + id)
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.data) {
                        const inv = data.data;
                        const items = inv.items || [];
                        
                        // Build items HTML
                        let itemsHtml = '';
                        if (items.length > 0) {
                            itemsHtml = items.map(item => {
                                const name = item.SparePartName || 'Unknown';
                                const qty = item.Quantity || 1;
                                const price = item.Price || 0;
                                const total = qty * price;
                                return `<tr>
                                    <td>${name}</td>
                                    <td>${qty}</td>
                                    <td>${price.toLocaleString()}</td>
                                    <td>${total.toLocaleString()}</td>
                                </tr>`;
                            }).join('');
                        } else {
                            itemsHtml = '<tr><td colspan="4" class="text-center">No items</td></tr>';
                        }

                        const html = `
                        <div class="invoice-print" id="invoicePrintArea">
                            <div class="header">
                                <h2>ELITE GARAGE SERVICE CENTER</h2>
                                <p>KG 123 St, Nyarugenge, Kigali</p>
                                <p>Tel: +250 788 123 456 | Email: info@elitegarage.rw</p>
                                <hr />
                                <h4>INVOICE</h4>
                                <p><strong>Invoice No:</strong> INV-${String(inv.InvoiceID).padStart(6,'0')}</p>
                                <p><strong>Date:</strong> ${new Date(inv.InvoiceDate).toLocaleDateString('en-GB', { day:'2-digit', month:'long', year:'numeric' })}</p>
                            </div>

                            <div class="row-details">
                                <div class="box">
                                    <h6>Customer Information</h6>
                                    <p><strong>Name:</strong> ${inv.CustomerName || 'N/A'}</p>
                                    <p><strong>Phone:</strong> ${inv.CustomerPhone || 'N/A'}</p>
                                    <p><strong>Email:</strong> ${inv.CustomerEmail || 'N/A'}</p>
                                    <p><strong>Address:</strong> ${inv.CustomerAddress || 'N/A'}</p>
                                </div>
                                <div class="box">
                                    <h6>Vehicle Information</h6>
                                    <p><strong>Vehicle:</strong> ${inv.VehicleManufacturer || ''} ${inv.VehicleModel || 'N/A'}</p>
                                    <p><strong>Plate Number:</strong> ${inv.PlateNumber || 'N/A'}</p>
                                    <p><strong>Year:</strong> ${inv.VehicleYear || 'N/A'}</p>
                                </div>
                            </div>

                            <h6>DESCRIPTION</h6>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Description</th>
                                        <th>QTY</th>
                                        <th>Unit Price (RWF)</th>
                                        <th>Amount (RWF)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${itemsHtml}
                                </tbody>
                            </table>

                            <div class="totals">
                                <p><strong>Labour Charges:</strong> ${(inv.LabourCharges || 0).toLocaleString()}</p>
                                <p><strong>Spare Parts Cost:</strong> ${(inv.SparePartsCost || 0).toLocaleString()}</p>
                                <p><strong>Taxes:</strong> ${(inv.Taxes || 0).toLocaleString()}</p>
                                <p><strong>Discounts:</strong> -${(inv.Discounts || 0).toLocaleString()}</p>
                                <p class="total"><strong>TOTAL:</strong> ${(inv.TotalAmount || 0).toLocaleString()} RWF</p>
                            </div>

                            <div class="footer">
                                <p><strong>Payment Status:</strong> ${inv.PaymentStatus || 'Pending'}</p>
                                <p><strong>Payment Method:</strong> ${inv.PaymentMethod || 'N/A'}</p>
                                <p>Thank you for your business!</p>
                            </div>
                        </div>
                        <style>
                            .invoice-print { font-family: Arial, sans-serif; padding: 20px; }
                            .invoice-print .header { text-align: center; margin-bottom: 20px; }
                            .invoice-print .header h2 { margin: 0; color: #2563eb; }
                            .invoice-print .header h4 { margin: 10px 0; }
                            .invoice-print .row-details { display: flex; gap: 20px; margin: 20px 0; }
                            .invoice-print .box { flex: 1; border: 1px solid #ddd; padding: 15px; }
                            .invoice-print .box h6 { margin: 0 0 10px 0; color: #2563eb; }
                            .invoice-print table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                            .invoice-print th, .invoice-print td { border: 1px solid #ddd; padding: 10px; text-align: left; }
                            .invoice-print th { background: #f8f9fa; }
                            .invoice-print .totals { text-align: right; margin: 20px 0; }
                            .invoice-print .total { font-size: 1.2em; color: #2563eb; }
                            .invoice-print .footer { margin-top: 30px; text-align: center; }
                        </style>
                        `;
                        document.getElementById('invoicePrintArea').innerHTML = html;
                        new bootstrap.Modal(document.getElementById('viewInvoiceModal')).show();
                    } else {
                        showToast('Could not load invoice details.', 'danger');
                    }
                })
                .catch(() => showToast('Network error.', 'danger'));
        }

        // ---- Print Invoice Directly (without showing modal) ----
        function printInvoiceDirectly(id) {
            fetch('../../backend/api/billing.php?resource=invoices&id=' + id)
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.data) {
                        const inv = data.data;
                        const items = inv.items || [];
                        
                        // Build items HTML
                        let itemsHtml = '';
                        if (items.length > 0) {
                            itemsHtml = items.map(item => {
                                const name = item.SparePartName || 'Unknown';
                                const qty = item.Quantity || 1;
                                const price = item.Price || 0;
                                const total = qty * price;
                                return `<tr>
                                    <td>${name}</td>
                                    <td>${qty}</td>
                                    <td>${price.toLocaleString()}</td>
                                    <td>${total.toLocaleString()}</td>
                                </tr>`;
                            }).join('');
                        } else {
                            itemsHtml = '<tr><td colspan="4" class="text-center">No items</td></tr>';
                        }

                        const html = `
                        <div class="invoice-print">
                            <div class="header">
                                <h2>ELITE GARAGE SERVICE CENTER</h2>
                                <p>KG 123 St, Nyarugenge, Kigali</p>
                                <p>Tel: +250 788 123 456 | Email: info@elitegarage.rw</p>
                                <hr />
                                <h4>INVOICE</h4>
                                <p><strong>Invoice No:</strong> INV-${String(inv.InvoiceID).padStart(6,'0')}</p>
                                <p><strong>Date:</strong> ${new Date(inv.InvoiceDate).toLocaleDateString('en-GB', { day:'2-digit', month:'long', year:'numeric' })}</p>
                            </div>

                            <div class="row-details">
                                <div class="box">
                                    <h6>Customer Information</h6>
                                    <p><strong>Name:</strong> ${inv.CustomerName || 'N/A'}</p>
                                    <p><strong>Phone:</strong> ${inv.CustomerPhone || 'N/A'}</p>
                                    <p><strong>Email:</strong> ${inv.CustomerEmail || 'N/A'}</p>
                                    <p><strong>Address:</strong> ${inv.CustomerAddress || 'N/A'}</p>
                                </div>
                                <div class="box">
                                    <h6>Vehicle Information</h6>
                                    <p><strong>Vehicle:</strong> ${inv.VehicleManufacturer || ''} ${inv.VehicleModel || 'N/A'}</p>
                                    <p><strong>Plate Number:</strong> ${inv.PlateNumber || 'N/A'}</p>
                                    <p><strong>Year:</strong> ${inv.VehicleYear || 'N/A'}</p>
                                </div>
                            </div>

                            <h6>DESCRIPTION</h6>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Description</th>
                                        <th>QTY</th>
                                        <th>Unit Price (RWF)</th>
                                        <th>Amount (RWF)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${itemsHtml}
                                </tbody>
                            </table>

                            <div class="totals">
                                <p><strong>Labour Charges:</strong> ${(inv.LabourCharges || 0).toLocaleString()}</p>
                                <p><strong>Spare Parts Cost:</strong> ${(inv.SparePartsCost || 0).toLocaleString()}</p>
                                <p><strong>Taxes:</strong> ${(inv.Taxes || 0).toLocaleString()}</p>
                                <p><strong>Discounts:</strong> -${(inv.Discounts || 0).toLocaleString()}</p>
                                <p class="total"><strong>TOTAL:</strong> ${(inv.TotalAmount || 0).toLocaleString()} RWF</p>
                            </div>

                            <div class="footer">
                                <p><strong>Payment Status:</strong> ${inv.PaymentStatus || 'Pending'}</p>
                                <p><strong>Payment Method:</strong> ${inv.PaymentMethod || 'N/A'}</p>
                                <p>Thank you for your business!</p>
                            </div>
                        </div>
                        <style>
                            .invoice-print { font-family: Arial, sans-serif; padding: 20px; }
                            .invoice-print .header { text-align: center; margin-bottom: 20px; }
                            .invoice-print .header h2 { margin: 0; color: #2563eb; }
                            .invoice-print .header h4 { margin: 10px 0; }
                            .invoice-print .row-details { display: flex; gap: 20px; margin: 20px 0; }
                            .invoice-print .box { flex: 1; border: 1px solid #ddd; padding: 15px; }
                            .invoice-print .box h6 { margin: 0 0 10px 0; color: #2563eb; }
                            .invoice-print table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                            .invoice-print th, .invoice-print td { border: 1px solid #ddd; padding: 10px; text-align: left; }
                            .invoice-print th { background: #f8f9fa; }
                            .invoice-print .totals { text-align: right; margin: 20px 0; }
                            .invoice-print .total { font-size: 1.2em; color: #2563eb; }
                            .invoice-print .footer { margin-top: 30px; text-align: center; }
                        </style>
                        `;
                        
                        // Create a hidden container for printing
                        const printContainer = document.createElement('div');
                        printContainer.id = 'tempPrintContainer';
                        printContainer.style.display = 'none';
                        printContainer.innerHTML = html;
                        document.body.appendChild(printContainer);
                        
                        // Print directly
                        printModalContent('tempPrintContainer');
                        
                        // Clean up after printing
                        setTimeout(() => {
                            document.body.removeChild(printContainer);
                        }, 1000);
                    } else {
                        showToast('Could not load invoice details.', 'danger');
                    }
                })
                .catch(() => showToast('Network error.', 'danger'));
        }

        function printInvoice() {
            // Print only the invoice markup via a hidden iframe - the page
            // (and the tab the user is on) is never touched or reloaded.
            printModalContent('invoicePrintArea');
        }

        // ---- PAYMENT CRUD ----
        function editPayment(payment) {
            document.getElementById('paymentModalTitle').innerHTML = '<i class="bi bi-pencil-square" style="color:var(--primary-blue);"></i> Edit Payment';
            document.getElementById('editingPaymentId').value = payment.PaymentID;
            document.getElementById('paymentInvoice').value = payment.InvoiceID || '';
            document.getElementById('paymentAmount').value = payment.Amount || '';
            document.getElementById('paymentMethod').value = payment.PaymentMethod || 'Cash';
            document.getElementById('paymentStatus').value = payment.PaymentStatus || 'Paid';
            document.getElementById('paymentDate').value = payment.PaymentDate || '';
            new bootstrap.Modal(document.getElementById('paymentModal')).show();
        }

        document.getElementById('paymentModal').addEventListener('hidden.bs.modal', function() {
            document.getElementById('paymentModalTitle').innerHTML = '<i class="bi bi-cash" style="color:var(--primary-blue);"></i> Add Payment';
            document.getElementById('paymentForm').reset();
            document.getElementById('editingPaymentId').value = '';
        });

        function savePayment(e) {
            e.preventDefault();
            const form = e.target;
            const submitBtn = form.querySelector('button[type="submit"]');
            showLoading(submitBtn);
            
            const id = document.getElementById('editingPaymentId').value;
            const payload = {
                invoice_id: document.getElementById('paymentInvoice').value,
                amount: document.getElementById('paymentAmount').value,
                payment_method: document.getElementById('paymentMethod').value,
                payment_status: document.getElementById('paymentStatus').value,
                payment_date: document.getElementById('paymentDate').value
            };

            const url = id ? ('../../backend/api/billing.php?resource=payments&id=' + id) : '../../backend/api/billing.php?resource=payments';
            const method = id ? 'PUT' : 'POST';

            fetch(url, {
                method: method,
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(res => res.json())
            .then(result => {
                if (result.success) {
                    showToast(result.message || 'Payment saved!', 'success');
                    bootstrap.Modal.getInstance(document.getElementById('paymentModal')).hide();
                    // Direct DOM update for better performance
                    updatePaymentsTable();
                } else {
                    showToast(result.message || 'Could not save payment.', 'danger');
                }
            })
            .catch(() => showToast('Network error.', 'danger'))
            .finally(() => {
                hideLoading(submitBtn);
            });
        }

        function updatePaymentsTable() {
            fetch('../../backend/api/billing.php?resource=payments')
                .then(res => res.json())
                .then(result => {
                    if (result.success && Array.isArray(result.data)) {
                        const tbody = document.querySelector('#paymentsTable tbody');
                        if (!tbody) return;
                        
                        tbody.innerHTML = result.data.map(p => {
                            const statusBadge = p.PaymentStatus === 'Completed' ? 'badge-ok' : 
                                              p.PaymentStatus === 'Pending' ? 'badge-pending' : 'badge-low';
                            return `<tr>
                                <td>${p.PaymentID}</td>
                                <td>${p.PaymentDate || '-'}</td>
                                <td>${safe(p.CustomerName || '-')}</td>
                                <td>${number_format(p.Amount)} RWF</td>
                                <td>${safe(p.PaymentMethod || '-')}</td>
                                <td><span class="badge-status ${statusBadge}">${safe(p.PaymentStatus)}</span></td>
                                <td>
                                    <button class="btn-action view" onclick="viewPayment(${p.PaymentID})"><i class="bi bi-eye"></i></button>
                                    <button class="btn-action edit" onclick="editPayment(${JSON.stringify(p).replace(/"/g, '&quot;')})"><i class="bi bi-pencil"></i></button>
                                    <button class="btn-action delete" onclick="deletePayment(${p.PaymentID}, 'Payment ${p.PaymentID}')"><i class="bi bi-trash"></i></button>
                                </td>
                            </tr>`;
                        }).join('');
                        
                        bindSearchInputs();
                        document.querySelectorAll('table').forEach(initPaging);
                        if (typeof refreshCounters === 'function') refreshCounters();
                    }
                })
                .catch(err => {
                    console.error('Update payments table error:', err);
                    if (typeof softReload === 'function') softReload();
                });
        }

        function deletePayment(id, label) {
            showConfirmModal('Delete Payment', `Are you sure you want to delete "${label}"?`, () => {
                fetch('../../backend/api/billing.php?resource=payments&id=' + id, { method: 'DELETE' })
                    .then(res => {
                        if (!res.ok) throw new Error('Network response was not ok: ' + res.status);
                        return res.json();
                    })
                    .then(result => {
                        if (result.success) {
                            showToast('Payment removed.', 'danger');
                            updatePaymentsTable();
                        } else {
                            showToast(result.message || 'Could not delete.', 'danger');
                        }
                    })
                    .catch(err => {
                        console.error('Delete payment error:', err);
                        showToast('Network error. Please try again.', 'danger');
                    });
            });
        }

        function viewPayment(id) {
            fetch('../../backend/api/billing.php?resource=payments&id=' + id)
                .then(res => res.json())
                .then(result => {
                    if (result.success && result.data) {
                        const p = result.data;
                        const modalHtml = `
                            <div class="modal fade modal-custom" id="viewPaymentModal" tabindex="-1">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title"><i class="bi bi-cash-coin" style="color:var(--primary-blue);"></i> Payment Details</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <div class="view-details-grid">
                                                <div class="vd-item"><strong>Payment ID:</strong> ${p.PaymentID}</div>
                                                <div class="vd-item"><strong>Amount:</strong> ${p.Amount ? parseFloat(p.Amount).toLocaleString() : '-'}</div>
                                                <div class="vd-item"><strong>Payment Method:</strong> ${p.PaymentMethod || '-'}</div>
                                                <div class="vd-item"><strong>Payment Status:</strong> ${p.PaymentStatus || '-'}</div>
                                                <div class="vd-item"><strong>Payment Date:</strong> ${p.PaymentDate ? new Date(p.PaymentDate).toLocaleDateString() : '-'}</div>
                                                <div class="vd-item"><strong>Customer:</strong> ${p.CustomerName || '-'}</div>
                                                <div class="vd-item vd-full"><strong>Invoice ID:</strong> ${p.InvoiceID || '-'}</div>
                                            </div>
                                        </div>
                                        <div class="modal-footer view-footer">
                                            <button type="button" class="btn-outline-blue btn-sm" data-bs-dismiss="modal">Close</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        `;
                        const existingModal = document.getElementById('viewPaymentModal');
                        if (existingModal) existingModal.remove();
                        document.body.insertAdjacentHTML('beforeend', modalHtml);
                        new bootstrap.Modal(document.getElementById('viewPaymentModal')).show();
                    } else {
                        showToast('Could not load payment details.', 'danger');
                    }
                })
                .catch(() => showToast('Network error.', 'danger'));
        }

        // ---- NOTIFICATION FUNCTIONS (already defined in staff.js) ----
        // We keep them as is

        // 
        // PROFILE UPDATE (NEW)
        // 
        function updateProfile(e) {
            e.preventDefault();

            const fullName = document.getElementById('profileFullName').value.trim();
            const username = document.getElementById('profileUsername').value.trim();
            const email = document.getElementById('profileEmail').value.trim();
            const current = document.getElementById('profileCurrentPassword').value;
            const newPass = document.getElementById('profileNewPassword').value;
            const confirm = document.getElementById('profileConfirmPassword').value;

            // Basic validation
            if (!fullName || !username || !email) {
                showToast('Full name, username, and email are required.', 'danger');
                return;
            }
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                showToast('Please enter a valid email address.', 'danger');
                return;
            }
            // If password fields are filled or username changed, validate
            if ((current || newPass || confirm) || username !== receptionistUsername) {
                if (!current) {
                    showToast('Current password is required to change password or username.', 'danger');
                    return;
                }
                if (newPass.length > 0 && newPass.length < 6) {
                    showToast('New password must be at least 6 characters.', 'danger');
                    return;
                }
                if (newPass !== confirm) {
                    showToast('New passwords do not match.', 'danger');
                    return;
                }
            }

            // Build FormData
            const formData = new FormData();
            formData.append('action', 'update_profile');
            formData.append('full_name', fullName);
            formData.append('username', username);
            formData.append('email', email);
            formData.append('current_password', current);
            formData.append('new_password', newPass);
            formData.append('confirm_password', confirm);

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(result => {
                if (result.success) {
                    showToast(result.message, 'success');
                    // Update sidebar name if changed
                    if (fullName) {
                        document.querySelector('.user-name').textContent = fullName;
                        const avatar = document.querySelector('.user-avatar');
                        if (avatar) avatar.textContent = fullName.substring(0, 2).toUpperCase();
                    }
                    bootstrap.Modal.getInstance(document.getElementById('profileModal')).hide();
                    // Optionally reload page to reflect changes (e.g., email)
                    setTimeout(() => softReload(), 800);
                } else {
                    showToast(result.message || 'Profile update failed.', 'danger');
                }
            })
            .catch(err => {
                showToast('Network error. Please try again.', 'danger');
                console.error(err);
            });
        }

        // Reset profile modal fields on close (optional)
        document.getElementById('profileModal').addEventListener('hidden.bs.modal', function() {
            // We don't reset password fields to keep user's current input? Actually we might reset.
            // But we'll let the user manually clear if needed.
        });

        // 
        // INIT (override default tab)
        // 
        document.addEventListener('DOMContentLoaded', function() {
            switchTab('dashboard', null);
        });

    window.editCustomer = editCustomer;
    window.saveCustomer = saveCustomer;
    window.deleteCustomer = deleteCustomer;
    window.viewCustomer = viewCustomer;
    window.editVehicle = editVehicle;
    window.saveVehicle = saveVehicle;
    window.deleteVehicle = deleteVehicle;
    window.viewVehicle = viewVehicle;
    window.editJob = editJob;
    window.saveJob = saveJob;
    window.deleteJob = deleteJob;
    window.viewJob = viewJob;
    window.editInvoice = editInvoice;
    window.calculateTotal = calculateTotal;
    window.saveInvoice = saveInvoice;
    window.deleteInvoice = deleteInvoice;
    window.viewInvoice = viewInvoice;
    window.printInvoice = printInvoice;
    window.printInvoiceDirectly = printInvoiceDirectly;
    window.editPayment = editPayment;
    window.savePayment = savePayment;
    window.deletePayment = deletePayment;
    window.viewPayment = viewPayment;
    window.updateProfile = updateProfile;
})();

/* 
   STOCK MANAGER DASHBOARD - page-specific behaviour
   (originally an inline <script> block in staff/Stock_Manager.php)
   Overrides the shared toggleSidebar/closeSidebar/switchTab/filterTable
   from the dashboard-wide section above, but only while on this page.
    */
(function () {
    if (document.body.dataset.page !== 'stock-manager') return;

        // 
        // SPARE PART CRUD
        // 
        function showPartModal() {
            document.getElementById('partModalTitle').innerHTML = '<i class="bi bi-boxes" style="color:var(--primary-blue);"></i> Add Spare Part';
            document.getElementById('editingPartId').value = '';
            document.getElementById('partForm').reset();
            new bootstrap.Modal(document.getElementById('partModal')).show();
        }

        function editPart(part) {
            document.getElementById('partModalTitle').innerHTML = '<i class="bi bi-pencil" style="color:var(--primary-blue);"></i> Edit Spare Part';
            document.getElementById('editingPartId').value = part.SparePartID;
            document.getElementById('partName').value = part.PartName;
            document.getElementById('partCategory').value = part.CategoryID || '';
            document.getElementById('partSupplier').value = part.SupplierID || '';
            document.getElementById('partPrice').value = part.UnitPrice;
            document.getElementById('partQuantity').value = part.Quantity;
            document.getElementById('partReorderLevel').value = part.ReorderLevel || 10;
            new bootstrap.Modal(document.getElementById('partModal')).show();
        }

        function savePart(e) {
            e.preventDefault();
            const form = e.target;
            const submitBtn = form.querySelector('button[type="submit"]');
            showLoading(submitBtn);
            
            const id = document.getElementById('editingPartId').value;
            const payload = {
                part_name: document.getElementById('partName').value,
                category_id: parseInt(document.getElementById('partCategory').value) || null,
                supplier_id: parseInt(document.getElementById('partSupplier').value) || null,
                unit_price: parseFloat(document.getElementById('partPrice').value),
                quantity: parseInt(document.getElementById('partQuantity').value),
                reorder_level: parseInt(document.getElementById('partReorderLevel').value)
            };

            const url = id ? ('../../backend/api/inventory.php?resource=spareparts&id=' + id) : '../../backend/api/inventory.php?resource=spareparts';
            const method = id ? 'PUT' : 'POST';

            fetch(url, {
                method: method,
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(res => res.json())
            .then(result => {
                if (result.success) {
                    showToast(result.message || 'Spare part saved!', 'success');
                    bootstrap.Modal.getInstance(document.getElementById('partModal')).hide();
                    // Direct DOM update for better performance
                    updatePartsTable();
                } else {
                    showToast(result.message || 'Could not save.', 'danger');
                }
            })
            .catch(err => {
                console.error('Save part error:', err);
                showToast('Network error. Please try again.', 'danger');
            })
            .finally(() => {
                hideLoading(submitBtn);
            });
        }

        function updatePartsTable() {
            fetch('../../backend/api/inventory.php?resource=spareparts')
                .then(res => res.json())
                .then(result => {
                    if (result.success && Array.isArray(result.data)) {
                        const tbody = document.querySelector('#partsTable tbody');
                        if (!tbody) return;
                        
                        tbody.innerHTML = result.data.map(sp => {
                            const stockStatus = sp.Quantity == 0 ? 'Out' : (sp.Quantity <= sp.ReorderLevel ? 'Low' : 'In Stock');
                            const badgeClass = stockStatus === 'Out' ? 'badge-low' : (stockStatus === 'Low' ? 'badge-low' : 'badge-ok');
                            return `<tr>
                                <td>${sp.SparePartID}</td>
                                <td>${safe(sp.PartName)}</td>
                                <td>${safe(sp.CategoryName || 'N/A')}</td>
                                <td>${sp.Quantity}</td>
                                <td>${sp.ReorderLevel}</td>
                                <td>${number_format(sp.UnitPrice, 0)} RWF</td>
                                <td>${safe(sp.SupplierName || 'N/A')}</td>
                                <td><span class="badge-status ${badgeClass}">${stockStatus}</span></td>
                                <td>
                                    <button class="btn-action view" onclick="viewPart(${sp.SparePartID})"><i class="bi bi-eye"></i></button>
                                    <button class="btn-action edit" onclick="editPart(${JSON.stringify(sp).replace(/"/g, '&quot;')})"><i class="bi bi-pencil"></i></button>
                                    <button class="btn-action delete" onclick="deletePart(${sp.SparePartID}, '${safe(sp.PartName).replace(/'/g, "\\'")}')"><i class="bi bi-trash"></i></button>
                                </td>
                            </tr>`;
                        }).join('');
                        
                        // Rebind search and pagination
                        bindSearchInputs();
                        document.querySelectorAll('table').forEach(initPaging);

                        // Keep the stat cards (Total/In Stock/Low Stock/Out of Stock)
                        // in sync with the freshly fetched stock levels.
                        const total = result.data.length;
                        const lowCount = result.data.filter(sp => sp.Quantity <= sp.ReorderLevel).length;
                        const outCount = result.data.filter(sp => sp.Quantity == 0).length;
                        const setText = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
                        setText('statTotalParts', total);
                        setText('statInStock', total - lowCount);
                        setText('statLowStock', lowCount);
                        setText('statOutOfStock', outCount);
                        setText('statInvLowStock', lowCount);
                        setText('statInvTotalParts', total);
                    }
                })
                .catch(err => {
                    console.error('Update parts table error:', err);
                    // Fallback to soft reload if direct update fails
                    if (typeof softReload === 'function') {
                        softReload();
                    }
                });
        }

        function viewPart(id) {
            fetch('../../backend/api/inventory.php?resource=spareparts')
                .then(res => res.json())
                .then(result => {
                    if (result.success) {
                        const part = result.data.find(p => p.SparePartID === id);
                        if (part) {
                            const bodyHtml = `
                                <div class="view-details-grid">
                                    <div class="vd-item"><strong>Part Name:</strong> ${safe(part.PartName)}</div>
                                    <div class="vd-item"><strong>Category:</strong> ${safe(part.CategoryName)}</div>
                                    <div class="vd-item"><strong>Supplier:</strong> ${safe(part.SupplierName)}</div>
                                    <div class="vd-item"><strong>Unit Price:</strong> ${part.UnitPrice != null ? part.UnitPrice + ' RWF' : '—'}</div>
                                    <div class="vd-item"><strong>Stock:</strong> ${safe(part.Quantity)}</div>
                                    <div class="vd-item"><strong>Reorder Level:</strong> ${safe(part.ReorderLevel)}</div>
                                </div>`;
                            openDetailsModal({ title: 'Spare Part Details', bodyHtml: bodyHtml, printable: false });
                        } else {
                            showToast('Could not load part details.', 'danger');
                        }
                    }
                })
                .catch(() => showToast('Network error.', 'danger'));
        }

        function deletePart(id, name) {
            showConfirmModal('Delete Spare Part', `Are you sure you want to delete "${name}"? This action cannot be undone.`, () => {
                fetch('../../backend/api/inventory.php?resource=spareparts&id=' + id, { method: 'DELETE' })
                    .then(res => {
                        if (!res.ok) throw new Error('Network response was not ok: ' + res.status);
                        return res.json();
                    })
                    .then(result => {
                        if (result.success) {
                            showToast('Spare part "' + name + '" deleted.', 'success');
                            // Direct DOM update for better performance
                            updatePartsTable();
                        } else {
                            showToast(result.message || 'Could not delete.', 'danger');
                        }
                    })
                    .catch(err => {
                        console.error('Delete part error:', err);
                        showToast('Network error. Please try again.', 'danger');
                    });
            });
        }

        // 
        // STOCK IN / STOCK OUT / MANUAL ADJUSTMENT
        // Bug fix: these three modals used to preventDefault(), show a fake
        // "Recorded!"/"Adjusted!" toast, and reset the form -- no request was
        // ever sent, so stock quantities never actually changed. They now call
        // the existing inventory.php?resource=spareparts&action=adjust endpoint.
        // 
        let _stockPartsCache = null;
        function _loadStockPartsInto(selectId) {
            const select = document.getElementById(selectId);
            if (!select) return;
            const fill = (parts) => {
                select.innerHTML = parts.map(p =>
                    `<option value="${p.SparePartID}">${safe(p.PartName)} (in stock: ${p.Quantity})</option>`
                ).join('');
            };
            if (_stockPartsCache) {
                fill(_stockPartsCache);
                return;
            }
            fetch('../../backend/api/inventory.php?resource=spareparts')
                .then(res => res.json())
                .then(result => {
                    if (result.success && Array.isArray(result.data)) {
                        _stockPartsCache = result.data;
                        fill(result.data);
                    }
                })
                .catch(err => console.error('Load parts for stock modal failed:', err));
        }
        ['stockInModal', 'stockOutModal', 'adjustModal'].forEach(function (modalId) {
            const el = document.getElementById(modalId);
            if (!el) return;
            el.addEventListener('show.bs.modal', function () {
                _stockPartsCache = null; // pick up latest quantities each time it's opened
                const selectId = modalId === 'stockInModal' ? 'stockInPart' : (modalId === 'stockOutModal' ? 'stockOutPart' : 'adjustPart');
                _loadStockPartsInto(selectId);
            });
        });

        function submitStockAdjust(event, formId, modalId, type) {
            event.preventDefault();
            const form = document.getElementById(formId);
            const submitBtn = form.querySelector('button[type="submit"]');
            showLoading(submitBtn);

            const partId = parseInt(document.getElementById(formId === 'stockInForm' ? 'stockInPart' : 'stockOutPart').value) || 0;
            const qty = parseInt(document.getElementById(formId === 'stockInForm' ? 'stockInQty' : 'stockOutQty').value) || 0;

            fetch('../../backend/api/inventory.php?resource=spareparts&action=adjust', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ spare_part_id: partId, quantity: qty, type: type, direction: 'add' })
            })
                .then(res => res.json())
                .then(result => {
                    if (result.success) {
                        showToast(type === 'Purchase' ? 'Stock in recorded!' : 'Stock out recorded!', 'success');
                        bootstrap.Modal.getInstance(document.getElementById(modalId)).hide();
                        form.reset();
                        updatePartsTable();
                    } else {
                        showToast(result.message || 'Could not record stock movement.', 'danger');
                    }
                })
                .catch(err => {
                    console.error('Stock adjust error:', err);
                    showToast('Network error. Please try again.', 'danger');
                })
                .finally(() => hideLoading(submitBtn));
        }

        function submitStockAdjustment(event) {
            event.preventDefault();
            const form = document.getElementById('adjustForm');
            const submitBtn = form.querySelector('button[type="submit"]');
            showLoading(submitBtn);

            const partId = parseInt(document.getElementById('adjustPart').value) || 0;
            const qty = parseInt(document.getElementById('adjustQty').value) || 0;
            const direction = document.getElementById('adjustType').value === 'subtract' ? 'subtract' : 'add';

            fetch('../../backend/api/inventory.php?resource=spareparts&action=adjust', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ spare_part_id: partId, quantity: qty, type: 'Adjustment', direction: direction })
            })
                .then(res => res.json())
                .then(result => {
                    if (result.success) {
                        showToast('Stock adjusted!', 'success');
                        bootstrap.Modal.getInstance(document.getElementById('adjustModal')).hide();
                        form.reset();
                        updatePartsTable();
                    } else {
                        showToast(result.message || 'Could not adjust stock.', 'danger');
                    }
                })
                .catch(err => {
                    console.error('Stock adjustment error:', err);
                    showToast('Network error. Please try again.', 'danger');
                })
                .finally(() => hideLoading(submitBtn));
        }

        // 
        // SIDEBAR TOGGLE
        // 
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
            document.getElementById('sidebarOverlay').classList.toggle('active');
        }
        function closeSidebar() {
            document.getElementById('sidebar').classList.remove('open');
            document.getElementById('sidebarOverlay').classList.remove('active');
        }
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeSidebar();
        });

        // 
        // TAB SWITCHING
        // 
        function switchTab(tab, e) {
            if (e) e.preventDefault();

            document.querySelectorAll('.tab-content').forEach(function(el) {
                el.style.display = 'none';
            });
            var target = document.getElementById('tab-' + tab);
            if (target) target.style.display = 'block';

            document.querySelectorAll('.sidebar-nav a').forEach(function(a) {
                a.classList.remove('active');
            });
            var navLink = document.getElementById('nav-' + tab);
            if (navLink) navLink.classList.add('active');

            var titles = {
                dashboard: 'Dashboard',
                spareparts: 'Spare Parts',
                categories: 'Categories',
                suppliers: 'Suppliers',
                inventory: 'Inventory',
                purchases: 'Purchases',
                requests: 'Part Requests',
                notifications: 'Notifications',
                settings: 'Settings'
            };
            var titleEl = document.getElementById('pageTitle');
            if (titleEl) titleEl.textContent = titles[tab] || tab.charAt(0).toUpperCase() + tab.slice(1);

            if (window.innerWidth <= 768) closeSidebar();

            var searchInput = document.getElementById('globalSearch');
            if (searchInput) searchInput.value = '';
        }

        // 
        // FILTER TABLE
        // 
        function filterTable(input, tableId) {
            if (!tableId) {
                // If called from global search, determine active tab and its table
                var visibleTab = document.querySelector('.tab-content[style*="display:block"]') ||
                    document.querySelector('.tab-content:not([style*="display:none"])');
                if (!visibleTab) return;
                var tabId = visibleTab.id;
                var tableMap = {
                    'tab-spareparts': 'partsTable',
                    'tab-categories': 'catTable',
                    'tab-suppliers': 'supTable',
                    'tab-purchases': 'poTable'
                };
                tableId = tableMap[tabId];
                if (!tableId) return;
                var inputEl = document.getElementById('globalSearch');
                if (!inputEl) return;
                var filter = inputEl.value.toLowerCase();
            } else {
                var filter = input.value.toLowerCase();
            }
            var table = document.getElementById(tableId);
            if (!table) return;
            var rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
            for (var i = 0; i < rows.length; i++) {
                var text = rows[i].textContent.toLowerCase();
                rows[i].style.display = text.indexOf(filter) > -1 ? '' : 'none';
            }
        }

        // 
        // PART REQUESTS
        // 
        function loadPartRequests() {
            fetch('../../backend/api/inventory.php?resource=sparepartrequests')
                .then(res => res.json())
                .then(result => {
                    if (result.success) {
                        const tbody = document.getElementById('partRequestsTableBody');
                        if (result.data.length === 0) {
                            tbody.innerHTML = '<tr><td colspan="10" class="text-center text-muted">No requests found.</td></tr>';
                            return;
                        }
                        tbody.innerHTML = result.data.map((req, idx) => {
                            const statusClass = {
                                'Pending': 'badge-low',
                                'Approved': 'badge-info',
                                'Rejected': 'badge-danger',
                                'Fulfilled': 'badge-ok'
                            }[req.Status] || 'badge-pending';
                            const isPending = req.Status === 'Pending';
                            const stockWarning = req.CurrentStock < req.QuantityRequested ? '<span class="text-danger">(Low)</span>' : '';
                            // Display numbering is sequential by row position (Request ID / Job
                            // both show idx + 1, so row 1 always reads "1" regardless of the
                            // underlying database IDs). The real req.RequestID / req.JobID are
                            // still used below for the action buttons, so approve/reject/delete
                            // continue to target the correct record.
                            const displayNum = idx + 1;
                            return `<tr>
                                <td>${displayNum}</td>
                                <td>${req.MechanicName || 'N/A'}</td>
                                <td>${req.JobID ? displayNum : '-'}</td>
                                <td>${req.SparePartName || 'N/A'}</td>
                                <td>${req.QuantityRequested}</td>
                                <td>${req.CurrentStock || 0} ${stockWarning}</td>
                                <td>${req.Reason || '-'}</td>
                                <td><span class="badge-status ${statusClass}">${req.Status}</span></td>
                                <td>${new Date(req.RequestedAt).toLocaleDateString()}</td>
                                <td>
                                    ${isPending ? `
                                        <button class="btn-action view" onclick="approvePartRequest(${req.RequestID})" title="Approve"><i class="bi bi-check-lg"></i></button>
                                        <button class="btn-action delete" onclick="rejectPartRequest(${req.RequestID})" title="Reject"><i class="bi bi-x-lg"></i></button>
                                    ` : ''}
                                    <button class="btn-action delete" onclick="deletePartRequest(${req.RequestID})" title="Delete"><i class="bi bi-trash"></i></button>
                                </td>
                            </tr>`;
                        }).join('');
                    }
                })
                .catch(() => {
                    document.getElementById('partRequestsTableBody').innerHTML = '<tr><td colspan="10" class="text-center text-danger">Failed to load requests.</td></tr>';
                });
        }

        function approvePartRequest(id) {
            showConfirmModal('Approve Request', 'This will deduct the requested quantity from stock. Continue?', function() {
                fetch('../../backend/api/inventory.php?resource=sparepartrequests&id=' + id + '&action=approve', { method: 'PUT' })
                    .then(res => res.json())
                    .then(result => {
                        if (result.success) {
                            showToast(result.message || 'Request approved!', 'success');
                            // Refresh everything the approval affects, in place, so the
                            // new stock level and movement log entry show immediately
                            // without waiting for a manual page refresh.
                            loadPartRequests();
                            if (typeof updatePartsTable === 'function') updatePartsTable();
                            if (typeof loadStockMovements === 'function') loadStockMovements();
                        } else {
                            showToast(result.message || 'Could not approve request.', 'danger');
                        }
                    })
                    .catch(() => showToast('Network error.', 'danger'));
            });
        }

        // 
        // STOCK MOVEMENT HISTORY
        // 
        function loadStockMovements() {
            fetch('../../backend/api/inventory.php?resource=stocktransactions')
                .then(res => res.json())
                .then(result => {
                    const tbody = document.getElementById('stockMovementTableBody');
                    if (!tbody) return;
                    if (!result.success) {
                        tbody.innerHTML = '<tr><td colspan="8" class="text-center text-danger">Failed to load stock movements.</td></tr>';
                        return;
                    }
                    if (result.data.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">No stock transactions found.</td></tr>';
                        return;
                    }
                    tbody.innerHTML = result.data.map(st => {
                        const quantity = Math.abs(st.Quantity);
                        const transactionType = st.TransactionType;
                        const beforeQty = st.BeforeQty !== null ? st.BeforeQty : 0;
                        const afterQty = st.AfterQty !== null ? st.AfterQty : 0;
                        
                        // Determine movement style
                        let qtyClass, qtyPrefix;
                        if (transactionType === 'Purchase' || transactionType === 'Adjustment') {
                            qtyClass = 'text-success';
                            qtyPrefix = '+';
                        } else if (transactionType === 'Usage' || transactionType === 'Sale') {
                            qtyClass = 'text-danger';
                            qtyPrefix = '-';
                        } else {
                            qtyClass = 'text-primary';
                            qtyPrefix = '';
                        }
                        
                        // Badge class for transaction type
                        let badgeClass;
                        if (transactionType === 'Purchase') {
                            badgeClass = 'badge-ok';
                        } else if (transactionType === 'Usage' || transactionType === 'Sale') {
                            badgeClass = 'badge-low';
                        } else {
                            badgeClass = 'badge-info';
                        }
                        
                        return `<tr>
                            <td>${st.TransactionDate}</td>
                            <td>${safe(st.PartName)}</td>
                            <td><span class="badge-status ${badgeClass}">${safe(transactionType)}</span></td>
                            <td class="${qtyClass} fw-bold">${qtyPrefix}${quantity}</td>
                            <td>${beforeQty}</td>
                            <td>${afterQty}</td>
                            <td>${safe(st.UserName || 'System')}</td>
                            <td><button class="btn-action delete" onclick="deleteStockMovement(${st.TransactionID})" title="Delete"><i class="bi bi-trash"></i></button></td>
                        </tr>`;
                    }).join('');
                    if (typeof bindSearchInputs === 'function') bindSearchInputs();
                    document.querySelectorAll('table').forEach(t => { if (typeof initPaging === 'function') initPaging(t); });
                    const movementCountEl = document.getElementById('statRecentMovements');
                    if (movementCountEl) movementCountEl.textContent = result.data.length;
                })
                .catch(() => {
                    const tbody = document.getElementById('stockMovementTableBody');
                    if (tbody) tbody.innerHTML = '<tr><td colspan="8" class="text-center text-danger">Failed to load stock movements.</td></tr>';
                });
        }

        function deleteStockMovement(id) {
            showConfirmModal(
                'Delete Stock Movement',
                'This will permanently remove this entry from the Stock Movement History log. It will NOT change current stock quantities. Continue?',
                function() {
                    fetch('../../backend/api/inventory.php?resource=stocktransactions&id=' + id, { method: 'DELETE' })
                        .then(res => res.json())
                        .then(result => {
                            if (result.success) {
                                showToast(result.message || 'Stock movement deleted.', 'success');
                                loadStockMovements();
                            } else {
                                showToast(result.message || 'Could not delete stock movement.', 'danger');
                            }
                        })
                        .catch(() => showToast('Network error.', 'danger'));
                }
            );
        }

        function deletePartRequest(id) {
            showConfirmModal(
                'Delete Request',
                'Are you sure you want to permanently delete this part request record? This will not affect stock levels or movement history.',
                function() {
                    fetch('../../backend/api/inventory.php?resource=sparepartrequests&id=' + id, { method: 'DELETE' })
                        .then(res => res.json())
                        .then(result => {
                            if (result.success) {
                                showToast(result.message || 'Request deleted.', 'success');
                                loadPartRequests();
                            } else {
                                showToast(result.message || 'Could not delete request.', 'danger');
                            }
                        })
                        .catch(() => showToast('Network error.', 'danger'));
                }
            );
        }

        function rejectPartRequest(id) {
            const reason = prompt('Enter rejection reason (optional):');
            if (reason !== null) {
                fetch('../../backend/api/inventory.php?resource=sparepartrequests&id=' + id + '&action=reject', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ reason: reason })
                })
                .then(res => res.json())
                .then(result => {
                    if (result.success) {
                        showToast(result.message || 'Request rejected.', 'success');
                        loadPartRequests();
                    } else {
                        showToast(result.message || 'Could not reject request.', 'danger');
                    }
                })
                .catch(() => showToast('Network error.', 'danger'));
            }
        }

        // 
        // INIT - default tab
        // 
        document.addEventListener('DOMContentLoaded', function() {
            switchTab('dashboard', null);
            loadPartRequests();
            if (document.getElementById('stockMovementTableBody')) loadStockMovements();
        });

        // Click outside sidebar to close on mobile
        document.addEventListener('click', function(e) {
            var sidebar = document.getElementById('sidebar');
            var toggle = document.getElementById('sidebarToggle');
            if (window.innerWidth <= 768) {
                if (!sidebar.contains(e.target) && !toggle.contains(e.target)) {
                    closeSidebar();
                }
            }
        });

    window.showPartModal = showPartModal;
    window.editPart = editPart;
    window.savePart = savePart;
    window.viewPart = viewPart;
    window.deletePart = deletePart;
    window.submitStockAdjust = submitStockAdjust;
    window.submitStockAdjustment = submitStockAdjustment;
    window.toggleSidebar = toggleSidebar;
    window.closeSidebar = closeSidebar;
    window.switchTab = switchTab;
    window.filterTable = filterTable;
    window.loadPartRequests = loadPartRequests;
    window.approvePartRequest = approvePartRequest;
    window.rejectPartRequest = rejectPartRequest;
    window.deletePartRequest = deletePartRequest;
    window.loadStockMovements = loadStockMovements;
    window.deleteStockMovement = deleteStockMovement;
})();

/* =====================================================================
   UX ENHANCEMENT LAYER
   - softReload(): in-place data refresh, never navigates away
   - live search across every table (debounced, delegated)
   - real client-side pagination (Previous/Next + bootstrap nav)
   - openDetailsModal()/printModalContent(): reusable centered View modal
   - live part-request polling + stock-aware <select> dropdowns
   ===================================================================== */
(function () {
    'use strict';

    /* ---------- helpers ---------- */
    function debounce(fn, wait) {
        var t;
        return function () {
            var a = arguments, c = this;
            clearTimeout(t);
            t = setTimeout(function () { fn.apply(c, a); }, wait || 120);
        };
    }
    function visibleTab() {
        var tabs = document.querySelectorAll('.tab-content');
        for (var i = 0; i < tabs.length; i++) {
            if (tabs[i].offsetParent !== null) return tabs[i];
        }
        return document.body;
    }

    /* ---------- 0. SOFT REFRESH (replaces window.location.reload()) ----------
       Re-fetches the current page's HTML in the background and swaps in
       just the active tab (plus any notification badges), so the user
       never leaves the section/tab they were working in. */
    function softReload() {
        var activeTab = visibleTab();
        var activeId = activeTab && activeTab.id;
        var scrollY = window.scrollY;

        // Remember where the user was in each table (page number + any
        // typed search text) so a background refresh - including the
        // periodic real-time sync - never resets their place.
        var tableIdsBefore = document.querySelectorAll('table[id]');
        var pageById = {};
        tableIdsBefore.forEach(function (t) {
            var st = pageState.get(t);
            if (st) pageById[t.id] = st;
        });
        var searchValues = {};
        document.querySelectorAll('.search-box input, input[type="search"], .search-input, [data-live-search]').forEach(function (input, i) {
            if (input.value) searchValues[input.id || ('__idx' + i)] = input.value;
        });

        fetch(window.location.href, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (res) { return res.text(); })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                if (activeId) {
                    var fresh = doc.getElementById(activeId);
                    var current = document.getElementById(activeId);
                    if (fresh && current) current.innerHTML = fresh.innerHTML;
                }
                // The Reports tab's fresh HTML always starts on the "Repairs"
                // report pill (that's the server's default markup), so
                // without this, every periodic background sync would quietly
                // reset whichever report the user actually had open. Put the
                // same report sub-view back after the swap.
                if (activeId === 'tab-reports' && typeof switchReport === 'function') {
                    switchReport(window.currentReport || 'repairs', null);
                }
                ['notifBadge', 'notifBadgeCount', 'sidebarNotifCount', 'notifCount'].forEach(function (id) {
                    var f = doc.getElementById(id), c = document.getElementById(id);
                    if (f && c) c.innerHTML = f.innerHTML;
                });

                // restore search text, then paging state, on the fresh nodes
                document.querySelectorAll('.search-box input, input[type="search"], .search-input, [data-live-search]').forEach(function (input, i) {
                    var v = searchValues[input.id || ('__idx' + i)];
                    if (v) input.value = v;
                });
                // bindSearchInputs() also calls initPaging() on every table,
                // which defaults fresh (post-swap) tables to page 1 and marks
                // them as initialized. Restore the remembered page *after*
                // that pass, then re-render directly (initPaging() itself
                // would no-op now that the table is marked initialized).
                bindSearchInputs();
                applyStockAwareOptions();
                document.querySelectorAll('table[id]').forEach(function (t) {
                    if (pageById[t.id]) {
                        pageState.set(t, pageById[t.id]);
                        renderPage(t);
                    }
                });
                document.querySelectorAll('.search-box input, input[type="search"], .search-input, [data-live-search]').forEach(function (input) {
                    if (input.value) runLiveSearch(input);
                });
                window.scrollTo(0, scrollY);
            })
            .catch(function () { /* data already reflects the last action; nothing to do */ });
    }
    window.softReload = softReload;

    /* ---------- 0b. COUNTER SYNC (stat cards) ----------
       The PHP dashboards render every tab's stat cards (Total Parts, Active
       Jobs, Unpaid Invoices, etc.) up front, even for tabs that aren't
       currently visible. The updateXTable() functions below refresh a
       single table in place after a create/edit/delete without a full page
       fetch, which is fast but previously left every stat card showing
       whatever number was on the page at initial load. refreshCounters()
       does a lightweight background re-fetch of the current page and
       copies over just the ".stat-card .number" values (matched by
       position, since the markup is identical), so counters across every
       tab - not just the one being edited - stay accurate after each
       action without a manual page reload. */
    function refreshCounters() {
        fetch(window.location.href, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (res) { return res.text(); })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var fresh = doc.querySelectorAll('.stat-card .number');
                var current = document.querySelectorAll('.stat-card .number');
                fresh.forEach(function (el, i) {
                    if (current[i] && current[i].textContent !== el.textContent) {
                        current[i].textContent = el.textContent;
                    }
                });
            })
            .catch(function () { /* stat cards just keep their last known values */ });
    }
    window.refreshCounters = refreshCounters;

    /* ---------- 1. LIVE SEARCH (delegated, debounced ~120ms) ---------- */
    var pageState = new WeakMap(); // table -> { page, perPage }

    function rowsOf(table) {
        return table.tBodies[0] ? Array.prototype.slice.call(table.tBodies[0].rows) : [];
    }

    function runLiveSearch(input) {
        var targetId = input.getAttribute('data-live-search');
        var scope = targetId ? document : (input.closest('.table-card') || input.closest('.card-custom') || visibleTab());
        var tables = targetId ? [document.getElementById(targetId)].filter(Boolean) : scope.querySelectorAll('table');
        if (!targetId && !tables.length) { scope = visibleTab(); tables = scope.querySelectorAll('table'); }
        var q = (input.value || '').toLowerCase().trim();

        Array.prototype.forEach.call(tables, function (table) {
            if (!table) return;
            var body = table.tBodies[0];
            if (!body) return;
            rowsOf(table).forEach(function (row) {
                if (row.classList.contains('live-search-empty-row')) return;
                var match = !q || row.textContent.toLowerCase().indexOf(q) > -1;
                row.dataset.searchMatch = match ? '1' : '0';
            });
            var state = pageState.get(table) || { page: 1, perPage: 10 };
            state.page = 1; // any new search always resets to page 1
            pageState.set(table, state);
            renderPage(table);
        });

        // card-grid style listings (e.g. spare-part cards)
        scope.querySelectorAll('.search-target-grid .card, [data-search-card]').forEach(function (card) {
            card.style.display = (!q || card.textContent.toLowerCase().indexOf(q) > -1) ? '' : 'none';
        });
        // list-group style rows (notifications, requests feeds)
        scope.querySelectorAll('#notificationList .list-group-item').forEach(function (item) {
            item.style.display = (!q || item.textContent.toLowerCase().indexOf(q) > -1) ? '' : 'none';
        });
    }

    var liveSearch = debounce(function (e) { runLiveSearch(e.target); }, 120);

    function bindSearchInputs() {
        document.querySelectorAll('.search-box input, input[type="search"], .search-input, [data-live-search]').forEach(function (input) {
            if (input.dataset.liveSearchBound) return;
            input.dataset.liveSearchBound = '1';
            input.setAttribute('autocomplete', 'off');
            input.addEventListener('input', liveSearch);
            input.addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter') ev.preventDefault(); // never submits / never leaves the page
            });
        });
        document.querySelectorAll('table').forEach(initPaging);
    }

    // route legacy filterTable() calls through the same live-search + pagination pipeline
    var originalFilterTable = window.filterTable;
    window.filterTable = function () {
        if (typeof originalFilterTable === 'function') {
            try { originalFilterTable.apply(this, arguments); } catch (err) { /* ignore legacy errors */ }
        }
        var input = (arguments[0] && arguments[0].nodeType) ? arguments[0] : document.getElementById('globalSearch');
        if (input) runLiveSearch(input);
        document.querySelectorAll('table').forEach(function (t) {
            var st = pageState.get(t) || { page: 1, perPage: 10 };
            st.page = 1;
            pageState.set(t, st);
            renderPage(t);
        });
    };

    /* ---------- 2. REAL CLIENT-SIDE PAGINATION ---------- */
    function initPaging(table) {
        if (!table) return;
        // Bug fix: this used to hard no-op ("if already initialized, do
        // nothing") which meant that after any CRUD action swapped in fresh
        // rows via innerHTML (updateXTable() functions below), the table's
        // pagination/footer counts were never recalculated -- extra rows
        // beyond one page all showed at once and "Showing X-Y of Z" froze
        // at its very first value. The one-time setup (dataset flag +
        // default page state) still only runs once, but renderPage() -
        // which recomputes visible rows, the empty-state row, and the
        // footer/pager text - now runs every time this is called, so a
        // table's paging/counters stay correct after every refresh.
        if (!table.dataset.pagingInit) {
            table.dataset.pagingInit = '1';
            if (!pageState.has(table)) pageState.set(table, { page: 1, perPage: 10 });
        }
        renderPage(table);
    }

    function totalPagesFor(table) {
        var matching = rowsOf(table).filter(function (r) {
            return !r.classList.contains('live-search-empty-row') && r.dataset.searchMatch !== '0';
        });
        var state = pageState.get(table) || { page: 1, perPage: 10 };
        return Math.max(1, Math.ceil(matching.length / state.perPage));
    }

    function renderPage(table) {
        if (!table || !table.tBodies[0]) return;
        var state = pageState.get(table) || { page: 1, perPage: 10 };
        var all = rowsOf(table).filter(function (r) { return !r.classList.contains('live-search-empty-row'); });
        var matching = all.filter(function (r) { return r.dataset.searchMatch !== '0'; });
        var totalPages = Math.max(1, Math.ceil(matching.length / state.perPage));
        if (state.page > totalPages) state.page = totalPages;
        if (state.page < 1) state.page = 1;
        pageState.set(table, state);

        var start = (state.page - 1) * state.perPage;
        var end = start + state.perPage;
        var shown = 0;
        all.forEach(function (row) {
            var isMatch = row.dataset.searchMatch !== '0';
            if (!isMatch) { row.style.display = 'none'; return; }
            var idx = matching.indexOf(row);
            var onPage = idx >= start && idx < end;
            row.style.display = onPage ? '' : 'none';
            if (onPage) shown++;
        });

        // "no matching records" empty row
        var body = table.tBodies[0];
        var colCount = (table.tHead && table.tHead.rows[0]) ? table.tHead.rows[0].cells.length : 1;
        var emptyRow = body.querySelector('.live-search-empty-row');
        if (matching.length === 0) {
            if (!emptyRow) {
                emptyRow = document.createElement('tr');
                emptyRow.className = 'live-search-empty-row';
                emptyRow.innerHTML = '<td colspan="' + colCount + '" class="text-center text-muted py-3">No matching records found.</td>';
                body.appendChild(emptyRow);
            }
            emptyRow.style.display = '';
        } else if (emptyRow) {
            emptyRow.style.display = 'none';
        }

        updatePagerUI(table, state.page, totalPages, matching.length);
    }

    function updatePagerUI(table, page, totalPages, totalRows) {
        var card = table.closest('.table-card') || table.closest('.card-custom') || table.parentElement;
        if (!card) return;

        // Previous/Next button pairs (.pagination-wrapper btn-outline-blue / btn-blue)
        var wrapper = card.querySelector('.pagination-wrapper');
        if (wrapper) {
            var buttons = wrapper.querySelectorAll('button');
            var prevBtn = buttons[0], nextBtn = buttons[buttons.length - 1];
            var onlyOnePage = totalPages <= 1;
            [prevBtn, nextBtn].forEach(function (b) { if (b) b.dataset.pagedTable = ''; });
            if (prevBtn) {
                prevBtn.disabled = onlyOnePage || page <= 1;
                prevBtn.classList.toggle('disabled', prevBtn.disabled);
            }
            if (nextBtn) {
                nextBtn.disabled = onlyOnePage || page >= totalPages;
                nextBtn.classList.toggle('disabled', nextBtn.disabled);
            }
        }

        // Bootstrap numbered <nav class="pagination">
        var nav = card.querySelector('.pagination');
        if (nav) {
            var items = Array.prototype.slice.call(nav.querySelectorAll('.page-item'));
            if (items.length >= 2) {
                var prevLi = items[0], nextLi = items[items.length - 1];
                var onlyOne = totalPages <= 1;
                prevLi.classList.toggle('disabled', onlyOne || page <= 1);
                nextLi.classList.toggle('disabled', onlyOne || page >= totalPages);
            }
        }

        // footer text, e.g. "Showing 1-10 of 42 users"
        var footer = card.querySelector('.table-footer p, [id$="TableFooter"]');
        if (footer) {
            var start = totalRows === 0 ? 0 : (page - 1) * (pageState.get(table).perPage) + 1;
            var end = Math.min(page * pageState.get(table).perPage, totalRows);
            var label = footer.textContent.replace(/^Showing[^a-zA-Z]*\d*-?\d*\s*of\s*\d*/i, '').trim();
            footer.textContent = 'Showing ' + start + '-' + end + ' of ' + totalRows + (label ? ' ' + label : '');
        }
        var countDisplay = card.querySelector('[id$="CountDisplay"]');
        if (countDisplay) {
            var suffix = countDisplay.textContent.replace(/^Showing\s*\d*/i, '').trim();
            countDisplay.textContent = 'Showing ' + totalRows + (suffix ? ' ' + suffix : '');
        }
    }

    function tableForPagerClick(evt) {
        var target = (evt && evt.target) || null;
        var btn = target && target.closest ? target.closest('button, .page-link') : null;
        var card = btn ? (btn.closest('.table-card') || btn.closest('.card-custom')) : null;
        var table = card ? card.querySelector('table') : (visibleTab().querySelector('table'));
        return table;
    }

    /* Real prevPage()/nextPage() - replace the old toast-only stubs.
       Called via onclick="prevPage()" / onclick="nextPage()" with no
       arguments, so we resolve the target table from window.event
       (the button that was actually clicked). */
    function prevPage() {
        var table = tableForPagerClick(window.event);
        if (!table) return;
        var state = pageState.get(table) || { page: 1, perPage: 10 };
        if (state.page <= 1) return;
        state.page -= 1;
        pageState.set(table, state);
        renderPage(table);
    }
    function nextPage() {
        var table = tableForPagerClick(window.event);
        if (!table) return;
        var state = pageState.get(table) || { page: 1, perPage: 10 };
        var totalPages = totalPagesFor(table);
        if (state.page >= totalPages) return;
        state.page += 1;
        pageState.set(table, state);
        renderPage(table);
    }
    window.prevPage = prevPage;
    window.nextPage = nextPage;

    // clicks on bootstrap numbered pagination (e.g. Mechanic.php static markup)
    document.addEventListener('click', function (e) {
        var link = e.target.closest('.pagination .page-link');
        if (!link) return;
        e.preventDefault();
        var li = link.parentElement;
        if (li.classList.contains('disabled')) return;
        var nav = link.closest('.pagination');
        var items = Array.prototype.slice.call(nav.querySelectorAll('.page-item'));
        var numbers = items.slice(1, -1);
        var current = numbers.findIndex(function (n) { return n.classList.contains('active'); });
        var target = current;
        if (li === items[0]) target = current - 1;
        else if (li === items[items.length - 1]) target = current + 1;
        else target = numbers.indexOf(li);
        if (target < 0 || target > numbers.length - 1) return;
        numbers.forEach(function (n, i) { n.classList.toggle('active', i === target); });
    });

    /* ---------- 3. REUSABLE CENTERED "VIEW" MODAL + PRINT ---------- */
    function ensureDetailsModal() {
        var el = document.getElementById('globalDetailsModal');
        if (el) return el;
        var wrap = document.createElement('div');
        wrap.innerHTML =
            '<div class="modal fade modal-custom" id="globalDetailsModal" tabindex="-1">' +
              '<div class="modal-dialog modal-dialog-centered">' +
                '<div class="modal-content">' +
                  '<div class="modal-header">' +
                    '<h5 class="modal-title" id="globalDetailsTitle"><i class="bi bi-eye"></i> Details</h5>' +
                    '<button type="button" class="btn-close" data-bs-dismiss="modal"></button>' +
                  '</div>' +
                  '<div class="modal-body"><div id="globalDetailsBody"></div></div>' +
                  '<div class="modal-footer view-footer">' +
                    '<button type="button" class="btn-blue btn-sm" id="globalDetailsPrint" style="display:none;"><i class="bi bi-printer"></i> Print</button>' +
                    '<button type="button" class="btn-outline-blue btn-sm" data-bs-dismiss="modal">Close</button>' +
                  '</div>' +
                '</div>' +
              '</div>' +
            '</div>';
        document.body.appendChild(wrap.firstChild);
        document.getElementById('globalDetailsPrint').addEventListener('click', function () {
            printModalContent('globalDetailsBody');
        });
        return document.getElementById('globalDetailsModal');
    }

    /* Reusable helper: opens a centered Bootstrap modal with arbitrary
       bodyHtml. Pass printable:true to show a Print button that prints
       only the modal's own content. */
    function openDetailsModal(opts) {
        opts = opts || {};
        ensureDetailsModal();
        document.getElementById('globalDetailsTitle').innerHTML = '<i class="bi bi-eye"></i> ' + safe(opts.title, 'Details');
        document.getElementById('globalDetailsBody').innerHTML = opts.bodyHtml || '';
        var printBtn = document.getElementById('globalDetailsPrint');
        printBtn.style.display = opts.printable ? '' : 'none';
        new bootstrap.Modal(document.getElementById('globalDetailsModal')).show();
    }
    window.openDetailsModal = openDetailsModal;

    /* Prints only the given container's current HTML via a hidden
       iframe - the real page/DOM/tab is never touched or reloaded. */
    function printModalContent(containerId) {
        var el = document.getElementById(containerId);
        if (!el) return;
        var iframe = document.createElement('iframe');
        iframe.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;';
        document.body.appendChild(iframe);
        var doc = iframe.contentWindow.document;
        doc.open();
        doc.write('<html><head><title>Print</title><style>body{font-family:Inter,Arial,sans-serif;padding:16px;color:#0f172a;}' +
            'table{width:100%;border-collapse:collapse;} th,td{border:1px solid #e2e8f0;padding:6px 8px;font-size:13px;}' +
            '</style></head><body>' + el.innerHTML + '</body></html>');
        doc.close();
        setTimeout(function () {
            iframe.contentWindow.focus();
            iframe.contentWindow.print();
            setTimeout(function () { iframe.remove(); }, 500);
        }, 200);
    }
    window.printModalContent = printModalContent;

    /* Fallback generic "View" for any row/button that doesn't already
       have its own onclick view handler wired up. */
    function viewFromRow(row) {
        var table = row.closest('table');
        var heads = table ? Array.prototype.map.call(table.tHead ? table.tHead.rows[0].cells : [], function (th) {
            return th.textContent.trim();
        }) : [];
        var rowsHtml = '';
        Array.prototype.forEach.call(row.cells, function (cell, i) {
            var label = heads[i] || ('Field ' + (i + 1));
            if (/action/i.test(label) || cell.querySelector('button')) return;
            var val = safe(cell.textContent.trim());
            rowsHtml += '<div class="vd-item"><strong>' + label + ':</strong> ' + (val || '<span class="vd-empty">—</span>') + '</div>';
        });
        rowsHtml = '<div class="view-details-grid">' + rowsHtml + '</div>';
        var card = row.closest('.table-card');
        var titleEl = card ? card.querySelector('.table-header h6') : null;
        openDetailsModal({ title: titleEl ? titleEl.textContent.trim() : 'Record details', bodyHtml: rowsHtml, printable: true });
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.btn-action.view');
        if (!btn || btn.getAttribute('onclick')) return; // real view*() handlers take priority
        var row = btn.closest('tr');
        if (!row) return;
        e.preventDefault();
        viewFromRow(row);
    });

    /* ---------- 4. STOCK KEEPER: live requests + stock-aware dropdowns ---------- */
    function startRequestPolling() {
        if (!document.getElementById('partRequestsTableBody')) return;
        setInterval(function () {
            if (typeof window.loadPartRequests === 'function' && !document.hidden) {
                window.loadPartRequests();
            }
        }, 12000);
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden && typeof window.loadPartRequests === 'function') window.loadPartRequests();
        });
    }

    /* Disables <option>s for out-of-stock items and labels them, whether
       the markup used data-stock="N" or the API's OutOfStock boolean via
       data-out-of-stock="true". */
    function applyStockAwareOptions() {
        document.querySelectorAll('select option[data-stock], select option[data-out-of-stock]').forEach(function (opt) {
            var stock = parseInt(opt.getAttribute('data-stock'), 10);
            var flagged = opt.getAttribute('data-out-of-stock') === 'true' || opt.getAttribute('data-out-of-stock') === '1';
            var outOfStock = flagged || (!isNaN(stock) && stock <= 0);
            if (outOfStock) {
                opt.disabled = true;
                if (opt.textContent.indexOf('Out of stock') === -1) {
                    opt.textContent = opt.textContent.replace(/\s*\(Stock:.*\)$/, '').replace(/\s*\(Out of stock\)$/, '') + ' (Out of stock)';
                }
            }
        });
    }

    /* Generic helper other code can call after an AJAX fetch of
       parts/materials/services/mechanics/customers to populate a
       <select> and grey out anything with QuantityAvailable <= 0. */
    function populateSelectWithStock(selectEl, items, opts) {
        if (!selectEl || !Array.isArray(items)) return;
        opts = opts || {};
        var valueKey = opts.valueKey || 'id';
        var labelKey = opts.labelKey || 'name';
        var qtyKey = opts.qtyKey || 'QuantityAvailable';
        var placeholder = opts.placeholder || 'Select...';
        selectEl.innerHTML = '<option value="">' + placeholder + '</option>' + items.map(function (item) {
            var qty = item[qtyKey];
            var outOfStock = item.OutOfStock === true || (qty !== undefined && Number(qty) <= 0);
            var label = safe(item[labelKey]) + (outOfStock ? ' (Out of stock)' : '');
            return '<option value="' + safe(item[valueKey], '') + '"' + (outOfStock ? ' disabled data-out-of-stock="true"' : '') + '>' + label + '</option>';
        }).join('');
    }
    window.populateSelectWithStock = populateSelectWithStock;

    /* ---------- 5. DASHBOARD CARDS: drop Stock In / Stock Out / Adjustment ----------
       These summary cards are rendered server-side; hide them purely on
       the client so the dashboard never shows them, without touching any
       PHP template. Nothing here computes or injects placeholder values -
       every remaining card keeps whatever real value the server rendered. */
    function pruneDashboardCards() {
        document.querySelectorAll('.stat-card, .card-custom').forEach(function (card) {
            var label = card.querySelector('.label, .stat-label');
            if (!label) return;
            var text = label.textContent.trim().toLowerCase();
            if (text === 'total stock in' || text === 'stock in' ||
                text === 'total stock out' || text === 'stock out' ||
                text === 'adjustments' || text === 'stock adjustments' || text === 'adjustment') {
                var col = card.closest('.col-sm-6, .col-lg-3, .col-md-3, .col-md-4, .col-lg-4') || card;
                col.remove();
            }
        });
    }

    /* ---------- 6. STAY ON PAGE ---------- */
    document.addEventListener('click', function (e) {
        var a = e.target.closest('a[href="#"]');
        if (a) e.preventDefault();
    });

    /* ---------- VALIDATION FUNCTIONS ---------- */
    
    /**
     * Validate Rwanda phone number (strict format)
     * Must be exactly 10 digits, numbers only, starting with 079, 078, 072, or 073
     * @param {string} phone - Phone number to validate
     * @returns {boolean} True if valid, false otherwise
     */
    function validatePhoneRwanda(phone) {
        if (!phone) return false;
        const digits = phone.replace(/\D/g, '');
        return /^(079|078|072|073)\d{7}$/.test(digits);
    }
    
    /**
     * Validate date is not in the future
     * @param {string} dateStr - Date string in YYYY-MM-DD format
     * @returns {boolean} True if valid (not future), false otherwise
     */
    function validateDateNotFuture(dateStr) {
        if (!dateStr) return true;
        const inputDate = new Date(dateStr);
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        return inputDate <= today;
    }
    
    /**
     * Validate date is not in the past
     * @param {string} dateStr - Date string in YYYY-MM-DD format
     * @returns {boolean} True if valid (not past), false otherwise
     */
    function validateDateNotPast(dateStr) {
        if (!dateStr) return true;
        const inputDate = new Date(dateStr);
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        return inputDate >= today;
    }
    
    /**
     * Validate date range (start date must be before or equal to end date)
     * @param {string} startDate - Start date string in YYYY-MM-DD format
     * @param {string} endDate - End date string in YYYY-MM-DD format
     * @returns {boolean} True if valid, false otherwise
     */
    function validateDateRange(startDate, endDate) {
        if (!startDate || !endDate) return true;
        const start = new Date(startDate);
        const end = new Date(endDate);
        return start <= end;
    }
    
    /**
     * Validate non-negative number
     * @param {number|string} value - Value to validate
     * @returns {boolean} True if valid non-negative number, false otherwise
     */
    function validateNonNegative(value) {
        if (value === '' || value === null || value === undefined) return false;
        const num = parseFloat(value);
        return !isNaN(num) && num >= 0;
    }
    
    /**
     * Validate positive number (greater than 0)
     * @param {number|string} value - Value to validate
     * @returns {boolean} True if valid positive number, false otherwise
     */
    function validatePositive(value) {
        if (value === '' || value === null || value === undefined) return false;
        const num = parseFloat(value);
        return !isNaN(num) && num > 0;
    }
    
    /**
     * Show validation error message for a field
     * @param {HTMLElement} field - The input field
     * @param {string} message - Error message to display
     */
    function showFieldError(field, message) {
        // Remove existing error
        hideFieldError(field);
        
        // Create error element
        const errorDiv = document.createElement('div');
        errorDiv.className = 'field-error';
        errorDiv.style.color = '#dc2626';
        errorDiv.style.fontSize = '0.85rem';
        errorDiv.style.marginTop = '4px';
        errorDiv.textContent = message;
        
        // Insert after the field
        field.parentNode.insertBefore(errorDiv, field.nextSibling);
        field.style.borderColor = '#dc2626';
    }
    
    /**
     * Hide validation error message for a field
     * @param {HTMLElement} field - The input field
     */
    function hideFieldError(field) {
        const errorDiv = field.parentNode.querySelector('.field-error');
        if (errorDiv) {
            errorDiv.remove();
        }
        field.style.borderColor = '';
    }
    
    /**
     * Add input event listener for phone validation
     * @param {string} fieldId - ID of the phone input field
     */
    function addPhoneValidation(fieldId) {
        const field = document.getElementById(fieldId);
        if (!field) return;
        
        field.addEventListener('input', function() {
            const value = this.value;
            const digits = value.replace(/\D/g, '');
            
            // Allow only digits
            if (value !== digits) {
                this.value = digits;
            }
            
            // Validate format
            if (value.length > 0) {
                if (!validatePhoneRwanda(value)) {
                    if (value.length === 10) {
                        showFieldError(this, 'Phone must start with 079, 078, 072, or 073');
                    }
                } else {
                    hideFieldError(this);
                }
            } else {
                hideFieldError(this);
            }
        });
        
        field.addEventListener('blur', function() {
            if (this.value.length > 0 && !validatePhoneRwanda(this.value)) {
                showFieldError(this, 'Phone must be exactly 10 digits starting with 079, 078, 072, or 073');
            }
        });
    }
    
    /**
     * Add input event listener for date validation (no future dates)
     * @param {string} fieldId - ID of the date input field
     */
    function addDateNotFutureValidation(fieldId) {
        const field = document.getElementById(fieldId);
        if (!field) return;
        
        field.addEventListener('change', function() {
            if (this.value && !validateDateNotFuture(this.value)) {
                showFieldError(this, 'Date cannot be in the future');
            } else {
                hideFieldError(this);
            }
        });
    }
    
    /**
     * Add input event listener for non-negative number validation
     * @param {string} fieldId - ID of the number input field
     */
    function addNonNegativeValidation(fieldId) {
        const field = document.getElementById(fieldId);
        if (!field) return;
        
        field.addEventListener('input', function() {
            const value = this.value;
            if (value && !validateNonNegative(value)) {
                showFieldError(this, 'Must be a non-negative number');
            } else {
                hideFieldError(this);
            }
        });
        
        field.addEventListener('blur', function() {
            if (this.value && !validateNonNegative(this.value)) {
                showFieldError(this, 'Must be a non-negative number');
            }
        });
    }
    
    // Make validation functions globally available
    window.validatePhoneRwanda = validatePhoneRwanda;
    window.validateDateNotFuture = validateDateNotFuture;
    window.validateDateNotPast = validateDateNotPast;
    window.validateDateRange = validateDateRange;
    window.validateNonNegative = validateNonNegative;
    window.validatePositive = validatePositive;
    window.showFieldError = showFieldError;
    window.hideFieldError = hideFieldError;
    window.addPhoneValidation = addPhoneValidation;
    window.addDateNotFutureValidation = addDateNotFutureValidation;
    window.addNonNegativeValidation = addNonNegativeValidation;

    /* ---------- 5. GLOBAL REAL-TIME SYNC ----------
       Any staff member's create/update/delete already triggers softReload()
       for THEM (see the setTimeout(softReload...) calls throughout this
       file). But other staff logged in at the same time only saw the
       change after they manually refreshed. This closes that gap: on
       every staff page we quietly re-poll in the background and swap in
       fresh data, so a part added by the Stock Manager shows up for the
       Mechanic and Receptionist without anyone touching F5.

       Safety rules so it never fights the person using the page:
         - only runs on /staff/ pages (where live data actually lives)
         - paused while the tab isn't visible (saves battery/bandwidth)
         - paused while the user is typing in a field or has a modal open,
           so an in-progress form or search is never yanked out from under
           them
         - uses the same softReload() as manual actions, so it's the exact
           same code path already proven safe throughout the app */
    var REALTIME_POLL_MS = 15000;
    var realtimeTimer = null;

    function userIsBusy() {
        var el = document.activeElement;
        if (el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.tagName === 'SELECT' || el.isContentEditable)) {
            return true;
        }
        return !!document.querySelector('.modal.show, .modal.in');
    }

    function startRealtimeSync() {
        if (!window.location.pathname.includes('staff/')) return;
        if (realtimeTimer) return;
        realtimeTimer = setInterval(function () {
            if (document.hidden || userIsBusy()) return;
            if (typeof softReload === 'function') softReload();
        }, REALTIME_POLL_MS);

        // Catch up immediately whenever the tab regains focus/visibility,
        // instead of waiting for the next tick of the interval.
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden && !userIsBusy() && typeof softReload === 'function') softReload();
        });
    }

    /* ---------- init ---------- */
    function init() {
        bindSearchInputs();
        applyStockAwareOptions();
        startRequestPolling();
        startRealtimeSync();
        pruneDashboardCards();
        
        // Initialize phone validation on contact form
        addPhoneValidation('contactPhone');
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    // re-apply when tables/cards are re-rendered by AJAX (e.g. loadPartRequests())
    var mo = new MutationObserver(debounce(function () {
        bindSearchInputs();
        applyStockAwareOptions();
        pruneDashboardCards();
    }, 200));
    mo.observe(document.documentElement, { childList: true, subtree: true });
})();

