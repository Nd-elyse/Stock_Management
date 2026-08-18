/* ============================================================
   Shared form-validation helpers used across every dashboard form.
   ============================================================ */

// Valid Rwandan mobile numbers: exactly 10 digits, numeric only, and must
// start with one of the real network prefixes: 072, 073, 078, or 079.
const VALID_PREFIXES = ['072', '073', '078', '079'];

export function phoneError(value) {
  const v = (value ?? '').toString().trim();
  if (!v) return null; // emptiness is handled separately by "required" where applicable
  if (!/^\d{10}$/.test(v)) return 'Phone number must be exactly 10 digits (numbers only).';
  if (!VALID_PREFIXES.some((p) => v.startsWith(p))) {
    return `Phone number must start with ${VALID_PREFIXES.join(', ')}.`;
  }
  return null;
}

// Live onChange handler: strips non-digits and caps at 10 characters as
// the user types, so invalid characters can never even be entered.
export function digitsOnly(value, maxLen = 10) {
  return (value ?? '').toString().replace(/\D/g, '').slice(0, maxLen);
}

// Today's date as YYYY-MM-DD, for use as a date input's `max` attribute
// to block selecting any future date.
export function todayStr() {
  const d = new Date();
  const pad = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}
