import React, { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Modal } from '../../components';
import { trackRepairApi } from '../../api';

const MODAL_ID = 'viewRepairStatusModal';
const initialForm = { fullName: '', plateNumber: '' };

/**
 * Public "View Repair Status" entry point. Triggered from the Home hero
 * and the Footer (showBsModal('viewRepairStatusModal')). Collects the
 * customer's full name + plate number, validates them against the
 * database via the existing track-repair lookup endpoint, and - only on
 * a successful match - redirects to /track-repair with the verified
 * result already attached, so that page can render it immediately
 * instead of asking again. On an invalid match, the error is shown right
 * here in the modal and no redirect happens.
 */
export default function ViewRepairStatusModal() {
  const [form, setForm] = useState(initialForm);
  const [fieldErrors, setFieldErrors] = useState({});
  const [submitting, setSubmitting] = useState(false);
  const [apiError, setApiError] = useState('');
  const rootRef = useRef(null);
  const navigate = useNavigate();

  useEffect(() => {
    const el = rootRef.current;
    if (!el) return;
    const onHidden = () => {
      setForm(initialForm);
      setFieldErrors({});
      setApiError('');
      setSubmitting(false);
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
      // Close manually rather than waiting for navigation to unmount it,
      // so the modal backdrop never lingers over the destination page.
      const el = rootRef.current?.querySelector('.modal');
      if (el && window.bootstrap) window.bootstrap.Modal.getInstance(el)?.hide();
      navigate('/track-repair', { state: { result: res.data } });
    } else {
      setApiError(res.message || 'No matching repair record found. Please check your details and try again.');
    }
  };

  return (
    <div ref={rootRef}>
      <Modal id={MODAL_ID} title="View Repair Status" icon="bi-search">
        <p className="repair-modal-intro">
          Enter your full name and vehicle plate number exactly as given at drop-off to view your repair status.
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
            {submitting ? (<><span className="spinner-border spinner-border-sm" /> Verifying...</>) : (<><i className="bi bi-search"></i> Check Repair Status</>)}
          </button>
        </form>
      </Modal>
    </div>
  );
}
