import React, { useEffect, useRef, useState } from 'react';
import { Modal, StatusBadge } from '../../components';
import { trackRepairApi } from '../../api';
import { downloadRepairInvoice } from '../../utils/invoicePdf';

const MODAL_ID = 'trackRepairModal';
const initialForm = { fullName: '', plateNumber: '' };

const STATUS_STEPS = ['Pending', 'Diagnosed', 'In Progress', 'Awaiting Parts', 'Ready', 'Delivered'];

function money(n) {
  return `${Number(n || 0).toLocaleString('en-US')} RWF`;
}

function fmtDateTime(d) {
  if (!d) return '-';
  const date = new Date(d);
  return Number.isNaN(date.getTime()) ? String(d) : date.toLocaleString();
}

function StatusTimeline({ status }) {
  // "Awaiting Parts" is a side-branch off "In Progress", not a forward step -
  // show progress through the main line up to whichever of the two applies.
  const effectiveIndex = STATUS_STEPS.indexOf(status === 'Awaiting Parts' ? 'In Progress' : status);
  return (
    <div className="repair-status-track">
      {STATUS_STEPS.filter((s) => s !== 'Awaiting Parts').map((step, i) => {
        const state = i < effectiveIndex ? 'done' : i === effectiveIndex ? 'active' : 'upcoming';
        return (
          <div className={`repair-status-step ${state}`} key={step}>
            <div className="repair-status-dot"><i className={`bi ${state === 'done' ? 'bi-check-lg' : 'bi-circle-fill'}`}></i></div>
            <span>{step === 'In Progress' && status === 'Awaiting Parts' ? 'Awaiting Parts' : step}</span>
          </div>
        );
      })}
    </div>
  );
}

/**
 * Public "View Repair Status" modal. Triggered from PublicNavbar via
 * showBsModal('trackRepairModal'); mounted once in PublicLayout so it's
 * reachable from any public page.
 */
export default function TrackRepairModal() {
  const [step, setStep] = useState('form'); // 'form' | 'result'
  const [form, setForm] = useState(initialForm);
  const [fieldErrors, setFieldErrors] = useState({});
  const [submitting, setSubmitting] = useState(false);
  const [apiError, setApiError] = useState('');
  const [result, setResult] = useState(null);
  const [downloading, setDownloading] = useState(false);
  const rootRef = useRef(null);

  // Reset everything once the modal has fully closed, so the next open
  // always starts on a clean lookup form.
  useEffect(() => {
    const el = rootRef.current;
    if (!el) return;
    const onHidden = () => {
      setStep('form');
      setForm(initialForm);
      setFieldErrors({});
      setApiError('');
      setResult(null);
      setSubmitting(false);
      setDownloading(false);
    };
    el.addEventListener('hidden.bs.modal', onHidden);
    return () => el.removeEventListener('hidden.bs.modal', onHidden);
  }, []);

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
    if (!validate()) return;
    setSubmitting(true);
    const res = await trackRepairApi.lookup(form.fullName.trim(), form.plateNumber.trim());
    setSubmitting(false);
    if (res.success) {
      setResult(res.data);
      setStep('result');
    } else {
      setApiError(res.message || 'No matching repair record found. Please check your details and try again.');
    }
  };

  const handleBack = () => {
    setStep('form');
    setResult(null);
    setApiError('');
  };

  const handleDownload = () => {
    if (!result?.estimate) return;
    setDownloading(true);
    try {
      downloadRepairInvoice(result);
    } finally {
      setDownloading(false);
    }
  };

  return (
    <div ref={rootRef}>
      <Modal
        id={MODAL_ID}
        title={step === 'result' ? 'Repair Status' : 'View Repair Status'}
        icon={step === 'result' ? 'bi-clipboard2-pulse' : 'bi-search'}
        size={step === 'result' ? 'modal-lg modal-dialog-scrollable' : ''}
      >
        {step === 'form' && (
          <>
            <p className="repair-modal-intro">
              Enter your full name and vehicle plate number exactly as given at drop-off to see live repair progress.
            </p>
            <form onSubmit={handleSubmit} noValidate>
              <div className="mb-3">
                <label className="form-label-custom">Full Name</label>
                <input
                  type="text"
                  className={`form-control form-control-custom${fieldErrors.fullName ? ' is-invalid' : ''}`}
                  placeholder="John Doe"
                  value={form.fullName}
                  onChange={update('fullName')}
                  autoFocus
                />
                {fieldErrors.fullName && <div className="invalid-feedback d-block">{fieldErrors.fullName}</div>}
              </div>
              <div className="mb-3">
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

              {apiError && (
                <div className="alert-danger-custom mb-3"><i className="bi bi-exclamation-triangle"></i> {apiError}</div>
              )}

              <button type="submit" className="btn-primary-full" disabled={submitting}>
                {submitting ? (<><span className="spinner-border spinner-border-sm" /> Searching...</>) : (<><i className="bi bi-search"></i> Check Repair Status</>)}
              </button>
            </form>
          </>
        )}

        {step === 'result' && result && (
          <div className="repair-result">
            <div className="repair-result-header">
              <div>
                <div className="section-eyebrow"><i className="bi bi-car-front-fill"></i> Vehicle</div>
                <h3 className="repair-result-title">
                  {[result.vehicle.manufacturer, result.vehicle.model].filter(Boolean).join(' ') || 'Vehicle'} — {result.vehicle.plate_number}
                </h3>
                <p className="repair-result-sub">Owner: {result.vehicle.owner_name} • Year: {result.vehicle.year || 'N/A'}</p>
              </div>
              {result.job && <StatusBadge status={result.job.status} okValues={['Delivered', 'Ready']} lowValues={['Cancelled']} />}
            </div>

            {!result.job && (
              <div className="alert-info-custom"><i className="bi bi-info-circle"></i> {result.message}</div>
            )}

            {result.job && (
              <>
                <StatusTimeline status={result.job.status} />

                <div className="repair-info-grid">
                  <div className="repair-info-card">
                    <div className="repair-info-icon"><i className="bi bi-person-badge"></i></div>
                    <div>
                      <h5>Assigned Mechanic</h5>
                      <p>{result.job.mechanic_name}</p>
                    </div>
                  </div>
                  <div className="repair-info-card">
                    <div className="repair-info-icon"><i className="bi bi-calendar-check"></i></div>
                    <div>
                      <h5>Timeline</h5>
                      <p>Started {result.job.start_date}{result.job.end_date ? ` • Est. completion ${result.job.end_date}` : ''}</p>
                    </div>
                  </div>
                </div>

                {result.job.diagnostic_notes && (
                  <div className="repair-section">
                    <h5><i className="bi bi-clipboard2-pulse"></i> Work Performed / Diagnosis</h5>
                    <p className="repair-section-text">{result.job.diagnostic_notes}</p>
                    {result.job.diagnostic_recommendation && (
                      <p className="repair-section-text"><strong>Recommendation:</strong> {result.job.diagnostic_recommendation}</p>
                    )}
                  </div>
                )}

                {result.parts_used && result.parts_used.length > 0 && (
                  <div className="repair-section">
                    <h5><i className="bi bi-tools"></i> Parts Used So Far</h5>
                    <ul className="repair-plain-list">
                      {result.parts_used.map((p, i) => (
                        <li key={i}><span>{p.part_name}</span><span className="repair-plain-list-value">x{p.quantity}</span></li>
                      ))}
                    </ul>
                  </div>
                )}

                {result.estimate ? (
                  <div className="repair-section">
                    <h5><i className="bi bi-receipt"></i> Invoice Summary</h5>
                    <div className="repair-invoice-box">
                      <div className="repair-invoice-row"><span>Labour Charges</span><span>{money(result.estimate.labour_charges)}</span></div>
                      <div className="repair-invoice-row"><span>Spare Parts Cost</span><span>{money(result.estimate.spare_parts_cost)}</span></div>
                      <div className="repair-invoice-row"><span>Taxes{result.estimate.tax_rate ? ` (${result.estimate.tax_rate}%)` : ''}</span><span>{money(result.estimate.taxes)}</span></div>
                      <div className="repair-invoice-row"><span>Discounts</span><span>-{money(result.estimate.discounts)}</span></div>
                      <div className="repair-invoice-row repair-invoice-total"><span>Total Amount</span><span>{money(result.estimate.total_amount)}</span></div>
                      <div className="repair-invoice-row">
                        <span>Payment Status</span>
                        <StatusBadge status={result.estimate.payment_status} okValues={['Paid']} lowValues={['Pending']} />
                      </div>
                    </div>

                    <button
                      type="button"
                      className="btn-primary-full mt-3"
                      onClick={handleDownload}
                      disabled={downloading}
                    >
                      {downloading ? (<><span className="spinner-border spinner-border-sm" /> Preparing...</>) : (<><i className="bi bi-download"></i> Download Invoice</>)}
                    </button>
                  </div>
                ) : (
                  <div className="alert-info-custom mt-3"><i className="bi bi-info-circle"></i> A final cost estimate has not been issued yet.</div>
                )}

                {result.history && result.history.length > 0 && (
                  <div className="repair-section">
                    <h5><i className="bi bi-clock-history"></i> Repair Timeline</h5>
                    <ul className="repair-history-list">
                      {result.history.map((h, i) => (
                        <li key={i}>
                          <strong>{h.new_status}</strong>
                          {h.previous_status ? ` (from ${h.previous_status})` : ' — job opened'}
                          {h.mechanic_name ? ` • ${h.mechanic_name}` : ''}
                          {' • '}{fmtDateTime(h.changed_at)}
                        </li>
                      ))}
                    </ul>
                  </div>
                )}
              </>
            )}

            <div className="repair-modal-footer">
              <button type="button" className="btn-outline-custom" onClick={handleBack}>
                <i className="bi bi-arrow-left"></i> Search Another Vehicle
              </button>
            </div>
          </div>
        )}
      </Modal>
    </div>
  );
}
