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
    <title>Contact | GarageManager</title>
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
                <li class="nav-item"><a class="nav-link" href="about.php">About</a></li>
                <li class="nav-item"><a class="nav-link active" href="contact.php">Contact</a></li>
                <li class="nav-item"><a class="nav-link btn-login" href="login.php"><i class="bi bi-box-arrow-in-right"></i> Login</a></li>
            </ul>
        </div>
    </div>
</nav>

<!-- PAGE HERO -->
<section class="page-hero">
    <div class="container">
        <h1>Get in <span class="highlight">Touch</span></h1>
        <p>Have questions about GarageManager? We're here to help you modernize your garage.</p>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="../index.php">Home</a></li>
                <li class="breadcrumb-item active">Contact</li>
            </ol>
        </nav>
    </div>
</section>

<!-- CONTACT -->
<section class="section-pad bg-white">
    <div class="container">
        <div class="row g-4 mb-5">
            <div class="col-md-4">
                <div class="feature-card contact-info-card reveal reveal-delay-1">
                    <div class="icon-wrap"><i class="bi bi-geo-alt-fill"></i></div>
                    <div><h5>Visit Us</h5><p>KN 5 Avenue, Kigali Heights<br />Kigali, Rwanda</p></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="feature-card contact-info-card reveal reveal-delay-2">
                    <div class="icon-wrap"><i class="bi bi-telephone-fill"></i></div>
                    <div><h5>Call Us</h5><p>+250 788 123 456<br />+250 722 987 654</p></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="feature-card contact-info-card reveal reveal-delay-3">
                    <div class="icon-wrap"><i class="bi bi-envelope-fill"></i></div>
                    <div><h5>Email Us</h5><p>info@garagemanager.rw<br />support@garagemanager.rw</p></div>
                </div>
            </div>
        </div>
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="card-custom contact-form-panel p-4 p-md-5 reveal">
                    <div class="section-eyebrow"><i class="bi bi-chat-dots"></i> Message Us</div>
                    <h3 class="section-title" style="font-size:1.6rem;">Send Us a Message</h3>
                    <p style="color:var(--text-muted);margin-bottom:2rem;">Fill out the form below and our team will get back to you within 24 hours.</p>
                    <form id="contactForm" onsubmit="submitContactForm(event)">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label-custom">Full Name</label>
                                <input type="text" id="contactName" class="form-control form-control-custom" placeholder="John Doe" required data-allow-numeric="false" pattern="[a-zA-Z\s\-']+" title="Name should contain only letters and spaces" />
                            </div>
                            <div class="col-md-6">
                                <label class="form-label-custom">Email Address</label>
                                <input type="email" id="contactEmail" class="form-control form-control-custom" placeholder="john@example.com" required />
                            </div>
                            <div class="col-md-6">
                                <label class="form-label-custom">Phone Number</label>
                                <input type="tel" id="contactPhone" class="form-control form-control-custom" placeholder="0781234567" pattern="^(079|078|072|073)\d{7}$" maxlength="10" title="Phone must be exactly 10 digits starting with 079, 078, 072, or 073" data-allow-numeric="true" />
                            </div>
                            <div class="col-md-6">
                                <label class="form-label-custom">Subject</label>
                                <select id="contactSubject" class="form-select form-control-custom">
                                    <option>General Inquiry</option>
                                    <option>Technical Support</option>
                                    <option>Sales / Pricing</option>
                                    <option>Partnership</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label-custom">Message</label>
                                <textarea id="contactMessage" class="form-control form-control-custom" rows="5" placeholder="Tell us how we can help..." required></textarea>
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn-primary-full"><i class="bi bi-send"></i> Send Message</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- MAP -->
<section class="section-pad-sm" style="background:#f8fafc;">
    <div class="container">
        <div class="map-frame reveal">
            <div class="map-badge"><i class="bi bi-geo-alt-fill"></i> GarageManager HQ — Kigali Heights</div>
            <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d31888.123456!2d29.123456!3d-1.123456!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x0%3A0x0!2sKigali!5e0!3m2!1sen!2srw!4v1234567890" width="100%" height="350" style="border:0;display:block;" allowfullscreen="" loading="lazy"></iframe>
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

