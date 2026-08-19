import React, { useEffect, useState } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { useReveal, StatusBadge } from '../../components';
import { trackRepairApi } from '../../api';
import { downloadRepairInvoice } from '../../utils/invoicePdf';
import { JOB_WORKFLOW_STATUSES, jobStatusLabel, normalizeJobStatus } from '../../utils/jobStatus';

const initialForm = { fullName: '', plateNumber: '' };
const STATUS_STEPS = JOB_WORKFLOW_STATUSES;

function money(n) {
  return `${Number(n || 0).toLocaleString('en-US')} RWF`;
}

function fmtDateTime(d) {
  if (!d) return '-';
  const date = new Date(d);
  return Number.isNaN(date.getTime()) ? String(d) : date.toLocaleString();
}

function StatusTimeline({ status }) {
  const normalizedStatus = normalizeJobStatus(status);
  const effectiveIndex = STATUS_STEPS.indexOf(normalizedStatus);
  return (
    <div className="repair-status-track">
      {STATUS_STEPS.map((step, i) => {
        const state = i < effectiveIndex ? 'done' : i === effectiveIndex ? 'active' : 'upcoming';
        return (
          <div className={`repair-status-step ${state}`} key={step}>
            <div className="repair-status-dot"><i className={`bi ${state === 'done' ? 'bi-check-lg' : 'bi-circle-fill'}`}></i></div>
            <span>{jobStatusLabel(step)}</span>
          </div>
        );
      })}
    </div>
  );
}

function JobCard({ jobData, isLatest }) {
  const { job, history, parts_used: partsUsed, estimate } = jobData;
  const [open, setOpen] = useState(isLatest);
  return (
    <div className="repair-job-card">
      <button type="button" className="repair-job-card-toggle" onClick={() => setOpen((v) => !v)}>
        <div>
          <span className="repair-job-card-id">Job #{job.job_id}{isLatest && <span className="repair-job-card-current">Current</span>}</span>
          <span className="repair-job-card-dates">Started {job.start_date}{job.end_date ? ` • Completed ${job.end_date}` : ''}</span>
        </div>
        <div className="d-flex align-items-center gap-2">
          <StatusBadge status={jobStatusLabel(job.status)} okValues={['Delivered', 'Ready']} lowValues={['Cancelled']} />
          <i className={`bi ${open ? 'bi-chevron-up' : 'bi-chevron-down'}`}></i>
        </div>
      </button>

      {open && (
        <div className="repair-job-card-body">
          <StatusTimeline status={job.status} />

          <div className="repair-info-grid">
            <div className="repair-info-card">
              <div className="repair-info-icon"><i className="bi bi-person-badge"></i></div>
              <div>
                <h5>Assigned Mechanic</h5>
                <p>{job.mechanic_name}</p>
              </div>
            </div>
            <div className="repair-info-card">
              <div className="repair-info-icon"><i className="bi bi-calendar-check"></i></div>
              <div>
                <h5>Timeline</h5>
                <p>Started {job.start_date}{job.end_date ? ` • Est. completion ${job.end_date}` : ''}</p>
              </div>
            </div>
          </div>

          {job.diagnostic_notes && (
            <div className="repair-section">
              <h5><i className="bi bi-clipboard2-pulse"></i> Work Performed / Diagnosis</h5>
              <p className="repair-section-text">{job.diagnostic_notes}</p>
              {job.diagnostic_recommendation && (
                <p className="repair-section-text"><strong>Recommendation:</strong> {job.diagnostic_recommendation}</p>
              )}
            </div>
          )}

          {partsUsed && partsUsed.length > 0 && (
            <div className="repair-section">
              <h5><i className="bi bi-tools"></i> Parts Used</h5>
              <ul className="repair-plain-list">
                {partsUsed.map((p, i) => (
                  <li key={i}><span>{p.part_name}</span><span className="repair-plain-list-value">x{p.quantity}</span></li>
                ))}
              </ul>
            </div>
          )}

          {estimate ? (
            <div className="repair-section">
              <h5><i className="bi bi-receipt"></i> Invoice Summary</h5>
              <div className="repair-invoice-box">
                <div className="repair-invoice-row"><span>Labour Charges</span><span>{money(estimate.labour_charges)}</span></div>
                <div className="repair-invoice-row"><span>Spare Parts Cost</span><span>{money(estimate.spare_parts_cost)}</span></div>
                <div className="repair-invoice-row"><span>Taxes{estimate.tax_rate ? ` (${estimate.tax_rate}%)` : ''}</span><span>{money(estimate.taxes)}</span></div>
                <div className="repair-invoice-row"><span>Discounts</span><span>-{money(estimate.discounts)}</span></div>
                <div className="repair-invoice-row repair-invoice-total"><span>Total Amount</span><span>{money(estimate.total_amount)}</span></div>
                <div className="repair-invoice-row">
                  <span>Payment Status</span>
                  <StatusBadge status={estimate.payment_status} okValues={['Paid']} lowValues={['Pending']} />
                </div>
              </div><br></br>
              <button
                type="button"
                className="btn-outline-custom repair-back-btn"
                onClick={() => downloadRepairInvoice({ vehicle: jobData.vehicle, job, estimate, parts_used: partsUsed })}
              >
                <i className="bi bi-download"></i> Download Invoice
              </button>
            </div>
          ) : (
            <div className="alert-info-custom mt-3"><i className="bi bi-info-circle"></i> A final cost estimate has not been issued yet for this job.</div>
          )}

          {history && history.length > 0 && (
            <div className="repair-section">
              <h5><i className="bi bi-clock-history"></i> Status History</h5>
              <ul className="repair-history-list">
                {history.map((h, i) => (
                  <li key={i}>
                    <strong>{h.new_status}</strong>
                    {h.previous_status ? ` (from ${h.previous_status})` : ' — job opened'}
                    {h.mechanic_name ? ` • ${h.mechanic_name}` : ''}
                    {' • '}{fmtDateTime(h.changed_at)}
                    {h.notes ? ` • ${h.notes}` : ''}
                  </li>
                ))}
              </ul>
            </div>
          )}
        </div>
      )}
    </div>
  );
}

export default function TrackRepair() {
  useReveal();
  const location = useLocation();
  const navigate = useNavigate();
  const preVerifiedResult = location.state?.result || null;

  const [form, setForm] = useState(initialForm);
  const [fieldErrors, setFieldErrors] = useState({});
  const [submitting, setSubmitting] = useState(false);
  const [apiError, setApiError] = useState('');
  const [result, setResult] = useState(preVerifiedResult);

  // Clear the router state once consumed so a page refresh (which drops
  // location.state anyway) or navigating back here later starts fresh
  // rather than looking like stale state.
  useEffect(() => {
    if (preVerifiedResult) {
      navigate(location.pathname, { replace: true, state: {} });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // While viewing results, quietly re-check for updates every 20s so a
  // status change made by staff shows up without the customer reloading.
  // Uses the verified result's own name/plate (not local form state, which
  // is still empty when arriving here via the modal's redirect).
  useEffect(() => {
    if (!result?.vehicle) return;
    const name = result.vehicle.owner_name;
    const plate = result.vehicle.plate_number;
    if (!name || !plate) return;
    const poll = setInterval(async () => {
      const res = await trackRepairApi.lookup(name, plate);
      if (res.success) setResult(res.data);
    }, 20000);
    return () => clearInterval(poll);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [Boolean(result)]);

  const update = (field) => (e) => {
    setForm((f) => ({ ...f, [field]: e.target.value }));
    setFieldErrors((fe) => ({ ...fe, [field]: '' }));
  };

  const validate = () => {
    const errs = {};
    if (!form.fullName.trim() || form.fullName.trim().length < 2) errs.fullName = 'Enter your full name (as given at drop-off).';
    if (!form.plateNumber.trim() || form.plateNumber.trim().length < 2) errs.plateNumber = 'Enter your vehicle plate number.';
    setFieldErrors(errs);
    return Object.keys(errs).length === 0;
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setApiError('');
    setResult(null);
    if (!validate()) return;
    setSubmitting(true);
    const res = await trackRepairApi.lookup(form.fullName.trim(), form.plateNumber.trim());
    setSubmitting(false);
    if (res.success) {
      setResult(res.data);
    } else {
      setApiError(res.message || 'No matching repair record found. Please check your details and try again.');
    }
  };

  const handleBack = () => {
    setResult(null);
    setForm(initialForm);
    setFieldErrors({});
    setApiError('');

    if (window.history.length > 1) {
      navigate(-1);
      return;
    }
  };

  return (
    <>
      <section className="repair-hero">
        <div className="container">
          <nav className="repair-hero-crumb">
            <Link to="/">Home</Link> <i className="bi bi-chevron-right"></i> <span>Repair Status</span>
          </nav>
          <h1><i className="bi bi-search"></i> Track Your Repair</h1>
        </div>
      </section>

      <section className="section-pad-sm bg-white repair-page">
        <div className="container">
          {!result && (
          <div className="repair-lookup-card reveal">
            <form onSubmit={handleSubmit} noValidate>
              <div className="row g-3 align-items-start">
                <div className="col-md-5">
                  <label className="form-label-custom">Full Name</label>
                  <input
                    type="text"
                    className={`form-control form-control-custom${fieldErrors.fullName ? ' is-invalid' : ''}`}
                    placeholder="John Doe"
                    value={form.fullName}
                    onChange={update('fullName')}
                  />
                  {fieldErrors.fullName && <div className="invalid-feedback d-block">{fieldErrors.fullName}</div>}
                </div>
                <div className="col-md-5">
                  <label className="form-label-custom">Vehicle Plate Number</label>
                  <input
                    type="text"
                    className={`form-control form-control-custom${fieldErrors.plateNumber ? ' is-invalid' : ''}`}
                    placeholder="RAB 123 A"
                    value={form.plateNumber}
                    onChange={update('plateNumber')}
                  />
                  {fieldErrors.plateNumber && <div className="invalid-feedback d-block">{fieldErrors.plateNumber}</div>}
                </div>
                <div className="col-md-2 d-grid">
                  <label className="form-label-custom d-none d-md-block">&nbsp;</label>
                  <button type="submit" className="btn-primary-full" disabled={submitting}>
                    {submitting ? (<span className="spinner-border spinner-border-sm" />) : (<><i className="bi bi-search"></i> Check</>)}
                  </button>
                </div>
              </div>
              {apiError && (
                <div className="alert-danger-custom mt-3"><i className="bi bi-exclamation-triangle"></i> {apiError}</div>
              )}
            </form>
          </div>
          )}

          {submitting && (
            <div className="repair-loading reveal">
              <span className="spinner-border" role="status"></span>
              <p>Looking up your vehicle's repair records...</p>
            </div>
          )}

          {result && (
            <div className="repair-final-result reveal">
              <button type="button" className="btn-outline-custom repair-back-btn" onClick={handleBack}>
                <i className="bi bi-arrow-left"></i> Back to Search
              </button>
              <div className="repair-result-header">
                <div>
                  <div className="section-eyebrow"><i className="bi bi-car-front-fill"></i> Vehicle</div>
                  <h2 className="repair-result-title">
                    {[result.vehicle.manufacturer, result.vehicle.model].filter(Boolean).join(' ') || 'Vehicle'} — {result.vehicle.plate_number}
                  </h2>
                  <p className="repair-result-sub">
                    Owner: {result.vehicle.owner_name} • Year: {result.vehicle.year || 'N/A'}
                    {result.vehicle.owner_phone ? ` • ${result.vehicle.owner_phone}` : ''}
                  </p>
                </div>
                {result.job && <StatusBadge status={result.job.status} okValues={['Delivered', 'Ready']} lowValues={['Cancelled']} />}
              </div>

              {!result.job ? (
                <div className="alert-info-custom"><i className="bi bi-info-circle"></i> {result.message}</div>
              ) : (
                <>
                  {result.summary && result.summary.total_jobs > 1 && (
                    <div className="repair-summary-strip">
                      <div><span>{result.summary.total_jobs}</span>Total Services</div>
                      <div><span>{result.summary.completed_jobs}</span>Completed</div>
                      <div><span>{result.summary.in_progress_jobs}</span>In Progress</div>
                      <div><span>{money(result.summary.total_spent)}</span>Total Spent</div>
                      <div><span>{money(result.summary.balance_due)}</span>Balance Due</div>
                      <div><span>{result.summary.last_service_date || '-'}</span>Last Service</div>
                    </div>
                  )}

                  <h3 className="repair-jobs-heading">
                    {result.jobs.length > 1 ? `Service History (${result.jobs.length} records)` : 'Service Details'}
                  </h3>
                  <div className="repair-jobs-list">
                    {result.jobs.map((jobData, i) => (
                      <JobCard key={jobData.job.job_id} jobData={{ ...jobData, vehicle: result.vehicle }} isLatest={i === 0} />
                    ))}
                  </div>
                </>
              )}
            </div>
          )}
        </div>
      </section>
    </>
  );
}
