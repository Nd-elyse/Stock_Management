import React from 'react';
import { Link } from 'react-router-dom';
import { useReveal } from '../../components';

const OBJECTIVES = [
  ['bi-file-earmark-text', 'Digitize Records', 'Replace paper-based customer, vehicle, and repair records with a centralized digital system.'],
  ['bi-clock-history', 'Track Repair Status', 'Monitor repair progress in real-time from Pending through Diagnosed, In Progress, Ready, to Delivered.'],
  ['bi-exclamation-triangle', 'Prevent Stock-Outs', 'Automatic low-stock alerts ensure spare parts are always available when needed.'],
  ['bi-receipt', 'Automate Billing', 'Generate accurate invoices combining labor costs and spare parts used in repairs.'],
  ['bi-lock-shield', 'Secure Access', 'Role-based authentication ensures each user only accesses what they need.'],
  ['bi-graph-up-arrow', 'Data-Driven Insights', 'Comprehensive reports and dashboards help owners make informed business decisions.'],
];

export default function About() {
  useReveal();

  return (
    <>
      <section className="page-hero">
        <div className="container">
          <h1>About <span className="highlight">GarageManager</span></h1>
          <p>A comprehensive garage management system designed to streamline auto workshop operations.</p>
          <nav>
            <ol className="breadcrumb">
              <li className="breadcrumb-item"><Link to="/">Home</Link></li>
              <li className="breadcrumb-item active">About</li>
            </ol>
          </nav>
        </div>
      </section>

      <section className="section-pad bg-white">
        <div className="container">
          <div className="row align-items-center g-5">
            <div className="col-lg-6 reveal-left">
              <div className="section-eyebrow"><i className="bi bi-info-circle"></i> Our Story</div>
              <h2 className="section-title">Built for Modern <span className="highlight">Auto Workshops</span></h2>
              <p style={{ color: 'var(--text-muted)', fontSize: '1rem', lineHeight: 1.8 }}>
                GarageManager is a centralized platform that automates vehicle repair workflows, tracks spare parts inventory, manages customer records, and generates invoices — all in one place.
              </p>
              <p style={{ color: 'var(--text-muted)', fontSize: '1rem', lineHeight: 1.8 }}>
                Designed specifically for auto workshops in Rwanda, it addresses the challenges of manual record-keeping, lost repair histories, inventory shortages, and disorganized billing.
              </p>
              <div className="row g-2 mt-2">
                {['Role-Based Access', 'Real-Time Tracking', 'Automated Invoicing', 'Stock Alerts'].map((t) => (
                  <div className="col-6" key={t}>
                    <div className="check-point"><i className="bi bi-check-circle-fill"></i><span>{t}</span></div>
                  </div>
                ))}
              </div>
            </div>
            <div className="col-lg-6 reveal-scale">
              <div className="hero-illustration" style={{ animation: 'none', opacity: 1, transform: 'none' }}>
                <div className="illustration-box" style={{ maxWidth: '100%' }}>
                  <i className="bi bi-building illustration-icon" style={{ fontSize: '3.5rem' }}></i>
                  <h4>Our Mission</h4>
                  <p>To digitize and streamline garage operations across Africa, reducing manual errors and improving service delivery.</p>
                  <div className="row g-3 mt-3 text-start">
                    {[['4', 'User Roles'], ['6', 'Core Modules'], ['100%', 'Web-Based'], ['24/7', 'Available']].map(([val, label]) => (
                      <div className="col-6" key={label}>
                        <div className="mini-stat">
                          <div className="mini-stat-value">{val}</div>
                          <div className="mini-stat-label">{label}</div>
                        </div>
                      </div>
                    ))}
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      <section className="section-pad" style={{ background: '#f8fafc' }}>
        <div className="container">
          <div className="text-center mb-5 reveal">
            <div className="section-eyebrow justify-content-center"><i className="bi bi-bullseye"></i> Our Goals</div>
            <h2 className="section-title">Project <span className="highlight">Objectives</span></h2>
            <p className="section-subtitle">What GarageManager aims to achieve for auto workshops.</p>
          </div>
          <div className="row g-4">
            {OBJECTIVES.map(([icon, title, desc], i) => (
              <div className="col-md-6 col-lg-4" key={title}>
                <div className={`feature-card reveal reveal-delay-${(i % 3) + 1}`}>
                  <div className="icon-wrap"><i className={`bi ${icon}`}></i></div>
                  <h5>{title}</h5>
                  <p>{desc}</p>
                </div>
              </div>
            ))}
          </div>
        </div>
      </section>
    </>
  );
}
