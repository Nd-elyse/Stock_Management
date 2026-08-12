import React, { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { statsApi } from '../../api';
import { useReveal } from '../../components';
import './Home.css';

const STAT_KEYS = [
  { key: 'customers', label: 'Happy Customers' },
  { key: 'vehicles_serviced', label: 'Vehicles Serviced' },
  { key: 'spare_parts', label: 'Spare Parts' },
  { key: 'mechanics', label: 'Expert Mechanics' },
];

function AnimatedStat({ target, label }) {
  const [value, setValue] = useState(0);
  const elRef = useRef(null);
  const animatedRef = useRef(false);

  useEffect(() => {
    if (!elRef.current) return;
    const io = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting && !animatedRef.current) {
            animatedRef.current = true;
            const duration = 1600;
            const start = performance.now();
            const tick = (now) => {
              const progress = Math.min((now - start) / duration, 1);
              const eased = 1 - Math.pow(1 - progress, 3);
              setValue(Math.floor(target * eased));
              if (progress < 1) requestAnimationFrame(tick);
              else setValue(target);
            };
            requestAnimationFrame(tick);
            io.unobserve(entry.target);
          }
        });
      },
      { threshold: 0.4 }
    );
    io.observe(elRef.current);
    return () => io.disconnect();
  }, [target]);

  return (
    <div className="stat-item">
      <span className="stat-number" ref={elRef}>{value.toLocaleString()}</span>
      <span className="stat-label">{label}</span>
    </div>
  );
}

export default function Home() {
  const [stats, setStats] = useState({});
  useReveal([stats]);

  useEffect(() => {
    let cancelled = false;
    statsApi.getPublicStats().then((res) => {
      if (!cancelled && res && res.success && res.data) setStats(res.data);
    });
    const interval = setInterval(() => {
      if (!document.hidden) {
        statsApi.getPublicStats().then((res) => {
          if (res && res.success && res.data) setStats(res.data);
        });
      }
    }, 30000);
    return () => {
      cancelled = true;
      clearInterval(interval);
    };
  }, []);

  return (
    <>
      <section className="hero" id="home">
        <div className="hero-shapes">
          <div className="shape"></div><div className="shape"></div><div className="shape"></div><div className="shape"></div><div className="shape"></div>
        </div>
        <div className="hero-particles">
          <div className="dot"></div><div className="dot"></div><div className="dot"></div><div className="dot"></div>
          <div className="dot"></div><div className="dot"></div><div className="dot"></div><div className="dot"></div>
        </div>
        <div className="container hero-content">
          <div className="row align-items-center g-4">
            <div className="col-lg-7">
              <div className="hero-badge"><i className="bi bi-shield-check"></i> Trusted Garage Management</div>
              <h1 className="hero-title">Smart Garage <br /><span className="highlight">Services &amp; Stock</span> Management</h1>
              <p className="hero-subtitle">Automate vehicle repairs, track spare parts inventory, manage customers, and generate invoices — all from one centralized platform.</p>
              <div className="hero-actions">
                <Link to="/about" className="btn-outline-custom"><i className="bi bi-info-circle"></i> Learn More</Link>
              </div>
              <div className="hero-stats">
                {STAT_KEYS.map((s) => (
                  <AnimatedStat key={s.key} target={parseInt(stats[s.key], 10) || 0} label={s.label} />
                ))}
              </div>
            </div>
            <div className="col-lg-5 hero-illustration">
              <div className="illustration-box pulse-ring">
                <i className="bi bi-tools illustration-icon"></i>
                <h4>Garage Dashboard</h4>
                <p>Manage everything from one place</p>
                <ul className="feature-list">
                  <li><i className="bi bi-check-circle-fill"></i> Customer &amp; Vehicle Records</li>
                  <li><i className="bi bi-check-circle-fill"></i> Repair Job Tracking</li>
                  <li><i className="bi bi-check-circle-fill"></i> Stock &amp; Supplier Management</li>
                  <li><i className="bi bi-check-circle-fill"></i> Invoicing &amp; Payments</li>
                  <li><i className="bi bi-check-circle-fill"></i> Role-Based Access Control</li>
                </ul>
              </div>
            </div>
          </div>
        </div>
      </section>

      <section className="section-pad bg-white">
        <div className="container">
          <div className="text-center mb-5 reveal">
            <div className="section-eyebrow justify-content-center"><i className="bi bi-grid-3x3-gap"></i> Core Modules</div>
            <h2 className="section-title">Everything Your Garage <span className="highlight">Needs</span></h2>
            <p className="section-subtitle">A complete management suite designed for modern auto workshops in Rwanda and beyond.</p>
          </div>
          <div className="row g-4">
            {[
              ['bi-people-fill', 'Customer Management', 'Register, search, and manage complete customer profiles including contact details and service history.'],
              ['bi-car-front-fill', 'Vehicle Management', 'Track vehicles with full details — plate number, chassis, engine number, fuel type, transmission, and mileage.'],
              ['bi-wrench-adjustable', 'Repair Job Tracking', 'Assign mechanics, update job status from Pending to Delivered, and keep full repair history.'],
              ['bi-boxes', 'Stock Management', 'Manage spare parts inventory with automatic updates, low stock alerts, and category organization.'],
              ['bi-receipt-cutoff', 'Invoicing &amp; Payments', 'Generate invoices, calculate labor and parts costs, and track payment status — Pending, Partial, or Paid.'],
              ['bi-bar-chart-line-fill', 'Reports &amp; Dashboard', 'Real-time dashboards with service reports, financial summaries, and inventory analytics.'],
            ].map(([icon, title, desc], i) => (
              <div className="col-md-6 col-lg-4" key={title}>
                <div className={`feature-card reveal reveal-delay-${(i % 3) + 1}`}>
                  <div className="icon-wrap"><i className={`bi ${icon}`}></i></div>
                  <h5 dangerouslySetInnerHTML={{ __html: title }} />
                  <p>{desc}</p>
                </div>
              </div>
            ))}
          </div>
        </div>
      </section>

      <section className="section-pad" style={{ background: '#f8fafc' }}>
        <div className="container">
          <div className="text-center mb-5 reveal">
            <div className="section-eyebrow justify-content-center"><i className="bi bi-lightning-charge"></i> How It Works</div>
            <h2 className="section-title">Simple <span className="highlight">4-Step</span> Process</h2>
            <p className="section-subtitle">From booking to billing — the entire workflow handled in one system.</p>
          </div>
          <div className="row g-4 justify-content-center">
            {[
              ['1', 'Book / Register', 'Customer books a service online, or the Receptionist registers them at the front desk.', true],
              ['2', 'Approve & Assign', 'Receptionist approves the request and assigns the job to a qualified mechanic.', true],
              ['3', 'Update Progress', 'Mechanic updates repair status and requests spare parts from stock.', true],
              ['4', 'Generate Invoice', 'Invoice is generated automatically with all costs. Payment is recorded.', false],
            ].map(([num, title, desc, connector], i) => (
              <div className={`col-sm-6 col-lg-3 reveal reveal-delay-${i + 1}`} key={num}>
                <div className="process-step">
                  {connector && <div className="process-connector d-none d-lg-block"></div>}
                  <div className="step-num">{num}</div>
                  <h6>{title}</h6>
                  <p style={{ color: 'var(--text-muted)', fontSize: '0.9rem' }}>{desc}</p>
                </div>
              </div>
            ))}
          </div>
        </div>
      </section>
    </>
  );
}
