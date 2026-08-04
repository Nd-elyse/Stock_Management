<?php
require_once __DIR__ . '/../backend/includes/csrf.php';
$csrf_token = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES); ?>" />
    <title>Home | GarageManager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,600;14..32,700;14..32,800&family=Sora:wght@500;600;700;800&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="style.css" />
</head>
<body>

<!-- PAGE LOADER -->
<div id="pageLoader"><i class="bi bi-wrench-adjustable-circle-fill loader-icon"></i></div>

<!-- NAVBAR -->
<nav class="navbar navbar-expand-lg navbar-custom fixed-top" id="mainNav">
    <div class="container">
        <a class="navbar-brand" href="index.php"><i class="bi bi-wrench-adjustable-circle-fill"></i> Garage<span>Manager</span></a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navMenu">
            <ul class="navbar-nav ms-auto align-items-lg-center gap-1 gap-lg-0">
                <li class="nav-item"><a class="nav-link active" href="index.php">Home</a></li>
                <li class="nav-item"><a class="nav-link" href="pages/about.php">About</a></li>
                <li class="nav-item"><a class="nav-link" href="pages/contact.php">Contact</a></li>
                <li class="nav-item"><a class="nav-link btn-login" href="pages/login.php"><i class="bi bi-box-arrow-in-right"></i> Login</a></li>
            </ul>
        </div>
    </div>
</nav>

<!-- HERO -->
<section class="hero" id="home">
    <div class="hero-shapes">
        <div class="shape"></div><div class="shape"></div><div class="shape"></div><div class="shape"></div><div class="shape"></div>
    </div>
    <div class="hero-particles">
        <div class="dot"></div><div class="dot"></div><div class="dot"></div><div class="dot"></div>
        <div class="dot"></div><div class="dot"></div><div class="dot"></div><div class="dot"></div>
    </div>
    <div class="container hero-content">
        <div class="row align-items-center g-4">
            <div class="col-lg-7">
                <div class="hero-badge"><i class="bi bi-shield-check"></i> Trusted Garage Management</div>
                <h1 class="hero-title">Smart Garage <br /><span class="highlight">Services &amp; Stock</span> Management</h1>
                <p class="hero-subtitle">Automate vehicle repairs, track spare parts inventory, manage customers, and generate invoices — all from one centralized platform.</p>
                <div class="hero-actions">
                    <a href="pages/about.php" class="btn-outline-custom"><i class="bi bi-info-circle"></i> Learn More</a>
                </div>
                <div class="hero-stats">
                    <div class="stat-item"><span class="stat-number" data-count="1200">0</span><span class="stat-label">Happy Customers</span></div>
                    <div class="stat-item"><span class="stat-number" data-count="850">0</span><span class="stat-label">Vehicles Serviced</span></div>
                    <div class="stat-item"><span class="stat-number" data-count="340">0</span><span class="stat-label">Spare Parts</span></div>
                    <div class="stat-item"><span class="stat-number" data-count="98">0</span><span class="stat-label">Expert Mechanics</span></div>
                </div>
            </div>
            <div class="col-lg-5 hero-illustration">
                <div class="illustration-box pulse-ring">
                    <i class="bi bi-tools illustration-icon"></i>
                    <h4>Garage Dashboard</h4>
                    <p>Manage everything from one place</p>
                    <ul class="feature-list">
                        <li><i class="bi bi-check-circle-fill"></i> Customer &amp; Vehicle Records</li>
                        <li><i class="bi bi-check-circle-fill"></i> Repair Job Tracking</li>
                        <li><i class="bi bi-check-circle-fill"></i> Stock &amp; Supplier Management</li>
                        <li><i class="bi bi-check-circle-fill"></i> Invoicing &amp; Payments</li>
                        <li><i class="bi bi-check-circle-fill"></i> Role-Based Access Control</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- FEATURES -->
<section class="section-pad bg-white">
    <div class="container">
        <div class="text-center mb-5 reveal">
            <div class="section-eyebrow justify-content-center"><i class="bi bi-grid-3x3-gap"></i> Core Modules</div>
            <h2 class="section-title">Everything Your Garage <span class="highlight">Needs</span></h2>
            <p class="section-subtitle">A complete management suite designed for modern auto workshops in Rwanda and beyond.</p>
        </div>
        <div class="row g-4">
            <div class="col-md-6 col-lg-4">
                <div class="feature-card reveal reveal-delay-1">
                    <div class="icon-wrap"><i class="bi bi-people-fill"></i></div>
                    <h5>Customer Management</h5>
                    <p>Register, search, and manage complete customer profiles including contact details and service history.</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-4">
                <div class="feature-card reveal reveal-delay-2">
                    <div class="icon-wrap"><i class="bi bi-car-front-fill"></i></div>
                    <h5>Vehicle Management</h5>
                    <p>Track vehicles with full details — plate number, chassis, engine number, fuel type, transmission, and mileage.</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-4">
                <div class="feature-card reveal reveal-delay-3">
                    <div class="icon-wrap"><i class="bi bi-wrench-adjustable"></i></div>
                    <h5>Repair Job Tracking</h5>
                    <p>Assign mechanics, update job status from Pending to Delivered, and keep full repair history.</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-4">
                <div class="feature-card reveal reveal-delay-1">
                    <div class="icon-wrap"><i class="bi bi-boxes"></i></div>
                    <h5>Stock Management</h5>
                    <p>Manage spare parts inventory with automatic updates, low stock alerts, and category organization.</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-4">
                <div class="feature-card reveal reveal-delay-2">
                    <div class="icon-wrap"><i class="bi bi-receipt-cutoff"></i></div>
                    <h5>Invoicing &amp; Payments</h5>
                    <p>Generate invoices, calculate labor and parts costs, and track payment status — Pending, Partial, or Paid.</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-4">
                <div class="feature-card reveal reveal-delay-3">
                    <div class="icon-wrap"><i class="bi bi-bar-chart-line-fill"></i></div>
                    <h5>Reports &amp; Dashboard</h5>
                    <p>Real-time dashboards with service reports, financial summaries, and inventory analytics.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- HOW IT WORKS -->
<section class="section-pad" style="background:#f8fafc;">
    <div class="container">
        <div class="text-center mb-5 reveal">
            <div class="section-eyebrow justify-content-center"><i class="bi bi-lightning-charge"></i> How It Works</div>
            <h2 class="section-title">Simple <span class="highlight">4-Step</span> Process</h2>
            <p class="section-subtitle">From booking to billing — the entire workflow handled in one system.</p>
        </div>
        <div class="row g-4 justify-content-center">
            <div class="col-sm-6 col-lg-3 reveal reveal-delay-1">
                <div class="process-step">
                    <div class="process-connector d-none d-lg-block"></div>
                    <div class="step-num">1</div>
                    <h6>Book / Register</h6>
                    <p style="color:var(--text-muted);font-size:0.9rem;">Customer books a service online, or the Receptionist registers them at the front desk.</p>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3 reveal reveal-delay-2">
                <div class="process-step">
                    <div class="process-connector d-none d-lg-block"></div>
                    <div class="step-num">2</div>
                    <h6>Approve &amp; Assign</h6>
                    <p style="color:var(--text-muted);font-size:0.9rem;">Receptionist approves the request and assigns the job to a qualified mechanic.</p>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3 reveal reveal-delay-3">
                <div class="process-step">
                    <div class="process-connector d-none d-lg-block"></div>
                    <div class="step-num">3</div>
                    <h6>Update Progress</h6>
                    <p style="color:var(--text-muted);font-size:0.9rem;">Mechanic updates repair status and requests spare parts from stock.</p>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3 reveal reveal-delay-4">
                <div class="process-step">
                    <div class="step-num">4</div>
                    <h6>Generate Invoice</h6>
                    <p style="color:var(--text-muted);font-size:0.9rem;">Invoice is generated automatically with all costs. Payment is recorded.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- FOOTER -->
<footer class="footer-custom">
    <div class="container">
        <div class="row g-4 align-items-center">
            <div class="col-md-4">
                <div class="footer-brand"><i class="bi bi-wrench-adjustable-circle-fill"></i> Garage<span>Manager</span></div>
                <p class="footer-text mt-2" style="max-width:320px;">Smart garage management system for modern auto workshops. Built with <i class="bi bi-heart-fill" style="color:var(--primary-blue);"></i> in Rwanda.</p>
            </div>
            <div class="col-md-4">
                <div class="footer-links">
                    <a href="/">Home</a>
                    <a href="pages/about.php">About</a>
                    <a href="pages/contact.php">Contact</a>
                    <a href="pages/login.php">Login</a>
                </div>
            </div>
            <div class="col-md-4 text-md-end">
                <a href="#" class="social-link"><i class="bi bi-facebook"></i></a>
                <a href="#" class="social-link"><i class="bi bi-twitter-x"></i></a>
                <a href="#" class="social-link"><i class="bi bi-instagram"></i></a>
                <a href="#" class="social-link"><i class="bi bi-linkedin"></i></a>
            </div>
        </div>
        <hr class="footer-divider" />
        <div class="row align-items-center">
            <div class="col-md-6"><p class="copyright mb-0">&copy; <?php echo date('Y'); ?> <strong>GarageManager</strong> &mdash; All rights reserved.</p></div>
            <div class="col-md-6 text-md-end"><p class="copyright mb-0"><i class="bi bi-shield-lock"></i> Secure &bull; Reliable &bull; Efficient</p></div>
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js" defer></script>
<script src="main.js?v=<?php echo filemtime(__DIR__ . '/main.js'); ?>" defer></script>
</body>
</html>

