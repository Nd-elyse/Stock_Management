import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { useReveal } from '../../components';
import { useToast } from '../../context';
import { contactApi } from '../../api';
import { phoneError, digitsOnly } from '../../utils/validators';

const NAME_PATTERN = /^[a-zA-Z\s\-']+$/;
const validateEmail = (email) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);

const initialForm = { name: '', email: '', phone: '', subject: 'General Inquiry', message: '' };

export default function Contact() {
  useReveal();
  const { showToast } = useToast();
  const [form, setForm] = useState(initialForm);
  const [errors, setErrors] = useState({});
  const [submitting, setSubmitting] = useState(false);

  const update = (field) => (e) => setForm((f) => ({ ...f, [field]: e.target.value }));

  const validate = () => {
    const next = {};
    if (!form.name.trim()) next.name = 'Full Name is required.';
    else if (!NAME_PATTERN.test(form.name)) next.name = 'Name should contain only letters and spaces.';
    if (!form.email.trim()) next.email = 'Email Address is required.';
    else if (!validateEmail(form.email)) next.email = 'Please enter a valid email address.';
    if (form.phone && phoneError(form.phone)) {
      next.phone = phoneError(form.phone);
    }
    if (!form.message.trim()) next.message = 'Message is required.';
    setErrors(next);
    return Object.keys(next).length === 0;
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!validate()) return;
    setSubmitting(true);
    const res = await contactApi.send({
      full_name: form.name,
      email: form.email,
      phone: form.phone,
      subject: form.subject,
      message: form.message,
    });
    setSubmitting(false);
    if (res.success) {
      showToast('Your message has been sent. We will get back to you within 24 hours.', 'success');
      setForm(initialForm);
    } else {
      showToast(res.message || 'Something went wrong. Please try again.', 'danger');
    }
  };

  return (
    <>
      <section className="page-hero">
        <div className="container">
          <h1>Get in <span className="highlight">Touch</span></h1>
          <p>Have questions about GarageManager? We're here to help you modernize your garage.</p>
          <nav>
            <ol className="breadcrumb">
              <li className="breadcrumb-item"><Link to="/">Home</Link></li>
              <li className="breadcrumb-item active">Contact</li>
            </ol>
          </nav>
        </div>
      </section>

      <section className="section-pad bg-white">
        <div className="container">
          <div className="row g-4 mb-5">
            <div className="col-md-4">
              <div className="feature-card contact-info-card reveal reveal-delay-1">
                <div className="icon-wrap"><i className="bi bi-geo-alt-fill"></i></div>
                <div><h5>Visit Us</h5><p>KN 5 Avenue, Kigali Heights<br />Kigali, Rwanda</p></div>
              </div>
            </div>
            <div className="col-md-4">
              <div className="feature-card contact-info-card reveal reveal-delay-2">
                <div className="icon-wrap"><i className="bi bi-telephone-fill"></i></div>
                <div><h5>Call Us</h5><p>+250 788 123 456<br />+250 722 987 654</p></div>
              </div>
            </div>
            <div className="col-md-4">
              <div className="feature-card contact-info-card reveal reveal-delay-3">
                <div className="icon-wrap"><i className="bi bi-envelope-fill"></i></div>
                <div><h5>Email Us</h5><p>info@garagemanager.rw<br />support@garagemanager.rw</p></div>
              </div>
            </div>
          </div>

          <div className="row justify-content-center">
            <div className="col-lg-8">
              <div className="card-custom contact-form-panel p-4 p-md-5 reveal">
                <div className="section-eyebrow"><i className="bi bi-chat-dots"></i> Message Us</div>
                <h3 className="section-title" style={{ fontSize: '1.6rem' }}>Send Us a Message</h3>
                <p style={{ color: 'var(--text-muted)', marginBottom: '2rem' }}>
                  Fill out the form below and our team will get back to you within 24 hours.
                </p>
                <form onSubmit={handleSubmit} noValidate>
                  <div className="row g-3">
                    <div className="col-md-6">
                      <label className="form-label-custom">Full Name</label>
                      <input
                        type="text"
                        className={`form-control form-control-custom${errors.name ? ' is-invalid' : ''}`}
                        placeholder="John Doe"
                        value={form.name}
                        onChange={update('name')}
                      />
                      {errors.name && <div className="invalid-feedback" style={{ display: 'block' }}>{errors.name}</div>}
                    </div>
                    <div className="col-md-6">
                      <label className="form-label-custom">Email Address</label>
                      <input
                        type="email"
                        className={`form-control form-control-custom${errors.email ? ' is-invalid' : ''}`}
                        placeholder="john@example.com"
                        value={form.email}
                        onChange={update('email')}
                      />
                      {errors.email && <div className="invalid-feedback" style={{ display: 'block' }}>{errors.email}</div>}
                    </div>
                    <div className="col-md-6">
                      <label className="form-label-custom">Phone Number</label>
                      <input
                        type="tel"
                        className={`form-control form-control-custom${errors.phone ? ' is-invalid' : ''}`}
                        placeholder="0781234567"
                        inputMode="numeric"
                        maxLength={10}
                        value={form.phone}
                        onChange={(e) => setForm((f) => ({ ...f, phone: digitsOnly(e.target.value) }))}
                      />
                      {errors.phone && <div className="invalid-feedback" style={{ display: 'block' }}>{errors.phone}</div>}
                    </div>
                    <div className="col-md-6">
                      <label className="form-label-custom">Subject</label>
                      <select className="form-select form-control-custom" value={form.subject} onChange={update('subject')}>
                        <option>General Inquiry</option>
                        <option>Technical Support</option>
                        <option>Sales / Pricing</option>
                        <option>Partnership</option>
                      </select>
                    </div>
                    <div className="col-12">
                      <label className="form-label-custom">Message</label>
                      <textarea
                        className={`form-control form-control-custom${errors.message ? ' is-invalid' : ''}`}
                        rows={5}
                        placeholder="Tell us how we can help..."
                        value={form.message}
                        onChange={update('message')}
                      ></textarea>
                      {errors.message && <div className="invalid-feedback" style={{ display: 'block' }}>{errors.message}</div>}
                    </div>
                    <div className="col-12">
                      <button type="submit" className="btn-primary-full" disabled={submitting}>
                        {submitting ? (
                          <><span className="spinner-border spinner-border-sm" /> Sending...</>
                        ) : (
                          <><i className="bi bi-send"></i> Send Message</>
                        )}
                      </button>
                    </div>
                  </div>
                </form>
              </div>
            </div>
          </div>
        </div>
      </section>

      <section className="section-pad-sm" style={{ background: '#f8fafc' }}>
        <div className="container">
          <div className="map-frame reveal">
            <div className="map-badge"><i className="bi bi-geo-alt-fill"></i> GarageManager HQ — Kigali Heights</div>
            <iframe
              title="GarageManager HQ location"
              src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d31888.123456!2d29.123456!3d-1.123456!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x0%3A0x0!2sKigali!5e0!3m2!1sen!2srw!4v1234567890"
              width="100%"
              height="350"
              style={{ border: 0, display: 'block' }}
              allowFullScreen=""
              loading="lazy"
            ></iframe>
          </div>
        </div>
      </section>
    </>
  );
}
