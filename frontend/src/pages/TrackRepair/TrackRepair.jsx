import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { useReveal, StatusBadge } from '../../components';
import { trackRepairApi } from '../../api';

const initialForm = { fullName: '', plateNumber: '' };

const STATUS_STEPS = ['Pending', 'Diagnosed', 'In Progress', 'Awaiting Parts', 'Ready', 'Delivered'];

function money(n) {
  return `${Number(n || 0).toLocaleString('en-US')} RWF`;
}

function StatusTimeline({ status }) {
  // Awaiting Parts is a side-branch off "In Progress", not a forward step -
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

export default function TrackRepair() {
  useReveal();
  const [form, setForm] = useState(initialForm);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');
  const [result, setResult] = useState(null);

  const update = (field) => (e) => setForm((f) => ({ ...f, [field]: e.target.value }));

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!form.fullName.trim() || !form.plateNumber.trim()) {
      setError('Please enter both your full name and vehicle plate number.');
      return;
    }
    setError('');
    setSubmitting(true);
    setResult(null);
    const res = await trackRepairApi.lookup(form.fullName.trim(), form.plateNumber.trim());
    setSubmitting(false);
    if (res.success) setResult(res.data);
    else setError(res.message || 'No matching repair record found. Please check your details and try again.');
  };

  return (
    <>
      <section className="page-hero">
        <div className="container">
          <h1>Track Your <span className="highlight">Repair Status</span></h1>
          <p>Enter your full name and vehicle plate number exactly as given at drop-off to see live repair progress.</p>
          <nav>
            <ol className="breadcrumb">
              <li className="breadcrumb-item"><Link to="/">Home</Link></li>
              <li className="breadcrumb-item active">Track Repair</li>
            </ol>
          </nav>
        </div>
      </section>

      <section className="section-pad bg-white">
        <div className="container">
          <div className="row justify-content-center">
            <div className="col-lg-8">
              <div className="card-custom contact-form-panel p-4 p-md-5 reveal">
                <div className="section-eyebrow"><i className="bi bi-search"></i> Repair Lookup</div>
                <h3 className="section-title" style={{ fontSize: '1.6rem' }}>Find Your Vehicle</h3>
                <p style={{ color: 'var(--text-muted)', marginBottom: '2rem' }}>
                  Both fields must match our records exactly (name as registered, plate number in any format).
                </p>
                <form onSubmit={handleSubmit} noValidate>
                  <div className="row g-3">
                    <div className="col-md-6">
                      <label className="form-label-custom">Full Name</label>
                      <input
                        type="text"
                        className="form-control form-control-custom"
                        placeholder="John Doe"
                        value={form.fullName}
                        onChange={update('fullName')}
                      />
                    </div>
                    <div className="col-md-6">
                      <label className="form-label-custom">Vehicle Plate Number</label>
                      <input
                        type="text"
                        className="form-control form-control-custom"
                        placeholder="RAB 123 A"
                        value={form.plateNumber}
                        onChange={update('plateNumber')}
                      />
                    </div>
                    {error && (
                      <div className="col-12">
                        <div className="invalid-feedback" style={{ display: 'block' }}><i className="bi bi-exclamation-circle"></i> {error}</div>
                      </div>
                    )}
                    <div className="col-12">
                      <button type="submit" className="btn-primary-full" disabled={submitting}>
                        {submitting ? (<><span className="spinner-border spinner-border-sm" /> Searching...</>) : (<><i className="bi bi-search"></i> Check Repair Status</>)}
                      </button>
                    </div>
                  </div>
                </form>
              </div>
            </div>
          </div>

          {result && (
            <div className="row justify-content-center mt-4">
              <div className="col-lg-10">
                <div className="card-custom p-4 p-md-5 reveal">
                  <div className="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
                    <div>
                      <div className="section-eyebrow"><i className="bi bi-car-front-fill"></i> Vehicle</div>
                      <h3 className="section-title" style={{ fontSize: '1.4rem' }}>
                        {result.vehicle.manufacturer} {result.vehicle.model} — {result.vehicle.plate_number}
                      </h3>
                      <p style={{ color: 'var(--text-muted)', margin: 0 }}>Owner: {result.vehicle.owner_name} • Year: {result.vehicle.year || 'N/A'}</p>
                    </div>
                    {result.job && <StatusBadge status={result.job.status} okValues={['Delivered', 'Ready']} lowValues={['Cancelled']} />}
                  </div>

                  {!result.job && (
                    <div className="alert-info-custom"><i className="bi bi-info-circle"></i> {result.message}</div>
                  )}

                  {result.job && (
                    <>
                      <StatusTimeline status={result.job.status} />

                      <div className="row g-3 mt-2">
                        <div className="col-md-6">
                          <div className="feature-card" style={{ padding: '1.25rem' }}>
                            <div className="icon-wrap" style={{ width: 44, height: 44, fontSize: '1.1rem' }}><i className="bi bi-person-badge"></i></div>
                            <div><h5 style={{ fontSize: '1rem', marginBottom: 4 }}>Assigned Mechanic</h5><p style={{ margin: 0 }}>{result.job.mechanic_name}</p></div>
                          </div>
                        </div>
                        <div className="col-md-6">
                          <div className="feature-card" style={{ padding: '1.25rem' }}>
                            <div className="icon-wrap" style={{ width: 44, height: 44, fontSize: '1.1rem' }}><i className="bi bi-calendar-check"></i></div>
                            <div><h5 style={{ fontSize: '1rem', marginBottom: 4 }}>Timeline</h5><p style={{ margin: 0 }}>Started {result.job.start_date}{result.job.end_date ? ` • Est. completion ${result.job.end_date}` : ''}</p></div>
                          </div>
                        </div>
                      </div>

                      {result.job.diagnostic_notes && (
                        <div className="mt-3">
                          <h5 style={{ fontSize: '1rem' }}><i className="bi bi-clipboard2-pulse"></i> Mechanic's Diagnosis</h5>
                          <p style={{ color: 'var(--text-muted)' }}>{result.job.diagnostic_notes}</p>
                          {result.job.diagnostic_recommendation && (
                            <p style={{ color: 'var(--text-muted)' }}><strong>Recommendation:</strong> {result.job.diagnostic_recommendation}</p>
                          )}
                        </div>
                      )}

                      {result.parts_used && result.parts_used.length > 0 && (
                        <div className="mt-4">
                          <h5 style={{ fontSize: '1rem' }}><i className="bi bi-tools"></i> Parts Used So Far</h5>
                          <table className="table table-borderless mb-0">
                            <tbody>
                              {result.parts_used.map((p, i) => (
                                <tr key={i}><td>{p.part_name}</td><td className="text-end">x{p.quantity}</td></tr>
                              ))}
                            </tbody>
                          </table>
                        </div>
                      )}

                      {result.estimate ? (
                        <div className="mt-4">
                          <h5 style={{ fontSize: '1rem' }}><i className="bi bi-receipt"></i> Cost Estimate / Invoice</h5>
                          <table className="table table-borderless mb-0">
                            <tbody>
                              <tr><td>Labour Charges</td><td className="text-end">{money(result.estimate.labour_charges)}</td></tr>
                              <tr><td>Spare Parts Cost</td><td className="text-end">{money(result.estimate.spare_parts_cost)}</td></tr>
                              <tr><td>Taxes</td><td className="text-end">{money(result.estimate.taxes)}</td></tr>
                              <tr><td>Discounts</td><td className="text-end">-{money(result.estimate.discounts)}</td></tr>
                              <tr style={{ fontWeight: 700 }}><td>Total</td><td className="text-end">{money(result.estimate.total_amount)}</td></tr>
                              <tr><td>Payment Status</td><td className="text-end"><StatusBadge status={result.estimate.payment_status} /></td></tr>
                            </tbody>
                          </table>
                        </div>
                      ) : (
                        <div className="alert-info-custom mt-4"><i className="bi bi-info-circle"></i> A final cost estimate has not been issued yet.</div>
                      )}

                      {result.history && result.history.length > 0 && (
                        <div className="mt-4">
                          <h5 style={{ fontSize: '1rem' }}><i className="bi bi-clock-history"></i> Repair Timeline</h5>
                          <ul style={{ listStyle: 'none', padding: 0, margin: 0 }}>
                            {result.history.map((h, i) => (
                              <li key={i} style={{ padding: '0.5rem 0', borderBottom: i < result.history.length - 1 ? '1px solid #eef1f5' : 'none', color: 'var(--text-muted)' }}>
                                <strong style={{ color: 'var(--text-dark)' }}>{h.new_status}</strong>
                                {h.previous_status ? ` (from ${h.previous_status})` : ' — job opened'}
                                {h.mechanic_name ? ` • ${h.mechanic_name}` : ''}
                                {' • '}{new Date(h.changed_at).toLocaleString()}
                              </li>
                            ))}
                          </ul>
                        </div>
                      )}
                    </>
                  )}
                </div>
              </div>
            </div>
          )}
        </div>
      </section>
    </>
  );
}
