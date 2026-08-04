<?php
require_once __DIR__ . '/../../backend/includes/csrf.php';
$csrf_token = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES); ?>" />
    <title>About | GarageManager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,600;14..32,700;14..32,800&family=Sora:wght@500;600;700;800&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="../style.css" />
</head>
<body>

<div id="pageLoader"><i class="bi bi-wrench-adjustable-circle-fill loader-icon"></i></div>

<!-- NAVBAR -->
<nav class="navbar navbar-expand-lg navbar-custom fixed-top" id="mainNav">
    <div class="container">
        <a class="navbar-brand" href="../index.php"><i class="bi bi-wrench-adjustable-circle-fill"></i> Garage<span>Manager</span></a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navMenu">
            <ul class="navbar-nav ms-auto align-items-lg-center gap-1 gap-lg-0">
                <li class="nav-item"><a class="nav-link" href="../index.php">Home</a></li>
                <li class="nav-item"><a class="nav-link active" href="about.php">About</a></li>
                <li class="nav-item"><a class="nav-link" href="contact.php">Contact</a></li>
                <li class="nav-item"><a class="nav-link btn-login" href="login.php"><i class="bi bi-box-arrow-in-right"></i> Login</a></li>
            </ul>
        </div>
    </div>
</nav>

<!-- PAGE HERO -->
<section class="page-hero">
    <div class="container">
        <h1>About <span class="highlight">GarageManager</span></h1>
        <p>A comprehensive garage management system designed to streamline auto workshop operations.</p>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="../index.php">Home</a></li>
                <li class="breadcrumb-item active">About</li>
            </ol>
        </nav>
    </div>
</section>

<!-- ABOUT -->
<section class="section-pad bg-white">
    <div class="container">
        <div class="row align-items-center g-5">
            <div class="col-lg-6 reveal-left">
                <div class="section-eyebrow"><i class="bi bi-info-circle"></i> Our Story</div>
                <h2 class="section-title">Built for Modern <span class="highlight">Auto Workshops</span></h2>
                <p style="color:var(--text-muted);font-size:1rem;line-height:1.8;">GarageManager is a centralized platform that automates vehicle repair workflows, tracks spare parts inventory, manages customer records, and generates invoices — all in one place.</p>
                <p style="color:var(--text-muted);font-size:1rem;line-height:1.8;">Designed specifically for auto workshops in Rwanda, it addresses the challenges of manual record-keeping, lost repair histories, inventory shortages, and disorganized billing.</p>
                <div class="row g-2 mt-2">
                    <div class="col-6"><div class="check-point"><i class="bi bi-check-circle-fill"></i><span>Role-Based Access</span></div></div>
                    <div class="col-6"><div class="check-point"><i class="bi bi-check-circle-fill"></i><span>Real-Time Tracking</span></div></div>
                    <div class="col-6"><div class="check-point"><i class="bi bi-check-circle-fill"></i><span>Automated Invoicing</span></div></div>
                    <div class="col-6"><div class="check-point"><i class="bi bi-check-circle-fill"></i><span>Stock Alerts</span></div></div>
                </div>
            </div>
            <div class="col-lg-6 reveal-scale">
                <div class="hero-illustration" style="animation:none;opacity:1;transform:none;">
                    <div class="illustration-box" style="max-width:100%;">
                        <i class="bi bi-building illustration-icon" style="font-size:3.5rem;"></i>
                        <h4>Our Mission</h4>
                        <p>To digitize and streamline garage operations across Africa, reducing manual errors and improving service delivery.</p>
                        <div class="row g-3 mt-3 text-start">
                            <div class="col-6"><div class="mini-stat"><div class="mini-stat-value">4</div><div class="mini-stat-label">User Roles</div></div></div>
                            <div class="col-6"><div class="mini-stat"><div class="mini-stat-value">6</div><div class="mini-stat-label">Core Modules</div></div></div>
                            <div class="col-6"><div class="mini-stat"><div class="mini-stat-value">100%</div><div class="mini-stat-label">Web-Based</div></div></div>
                            <div class="col-6"><div class="mini-stat"><div class="mini-stat-value">24/7</div><div class="mini-stat-label">Available</div></div></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- OBJECTIVES -->
<section class="section-pad" style="background:#f8fafc;">
    <div class="container">
        <div class="text-center mb-5 reveal">
            <div class="section-eyebrow justify-content-center"><i class="bi bi-bullseye"></i> Our Goals</div>
            <h2 class="section-title">Project <span class="highlight">Objectives</span></h2>
            <p class="section-subtitle">What GarageManager aims to achieve for auto workshops.</p>
        </div>
        <div class="row g-4">
            <div class="col-md-6 col-lg-4">
                <div class="feature-card reveal reveal-delay-1">
                    <div class="icon-wrap"><i class="bi bi-file-earmark-text"></i></div>
                    <h5>Digitize Records</h5>
                    <p>Replace paper-based customer, vehicle, and repair records with a centralized digital system.</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-4">
                <div class="feature-card reveal reveal-delay-2">
                    <div class="icon-wrap"><i class="bi bi-clock-history"></i></div>
                    <h5>Track Repair Status</h5>
                    <p>Monitor repair progress in real-time from Pending through Diagnosed, In Progress, Ready, to Delivered.</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-4">
                <div class="feature-card reveal reveal-delay-3">
                    <div class="icon-wrap"><i class="bi bi-exclamation-triangle"></i></div>
                    <h5>Prevent Stock-Outs</h5>
                    <p>Automatic low-stock alerts ensure spare parts are always available when needed.</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-4">
                <div class="feature-card reveal reveal-delay-1">
                    <div class="icon-wrap"><i class="bi bi-receipt"></i></div>
                    <h5>Automate Billing</h5>
                    <p>Generate accurate invoices combining labor costs and spare parts used in repairs.</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-4">
                <div class="feature-card reveal reveal-delay-2">
                    <div class="icon-wrap"><i class="bi bi-lock-shield"></i></div>
                    <h5>Secure Access</h5>
                    <p>Role-based authentication ensures each user only accesses what they need.</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-4">
                <div class="feature-card reveal reveal-delay-3">
                    <div class="icon-wrap"><i class="bi bi-graph-up-arrow"></i></div>
                    <h5>Data-Driven Insights</h5>
                    <p>Comprehensive reports and dashboards help owners make informed business decisions.</p>
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
                    <a href="../index.php">Home</a>
                    <a href="about.php">About</a>
                    <a href="contact.php">Contact</a>
                    <a href="login.php">Login</a>
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
<script src="../main.js?v=<?php echo filemtime(__DIR__ . '/../main.js'); ?>" defer></script>
</body>
</html>

