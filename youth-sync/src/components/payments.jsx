import { useCallback, useEffect, useState } from 'react';
import { api, friendlyApiMessage } from '../lib/api.js';
import { Badge } from './ui.jsx';

/**
 * Online payment helpers (PayMongo hosted checkout).
 * The browser never decides that a payment succeeded: every status shown here
 * comes from the YouthSync API, which confirms payments with PayMongo itself.
 */

const PAYMENT_TONE = {
  pending: 'warning', paid: 'success', failed: 'danger', cancelled: 'neutral', expired: 'neutral', review: 'danger',
  refunded: 'danger', partially_refunded: 'warning',
};

const PAYMENT_LABEL = {
  pending: 'Awaiting payment', paid: 'Paid', failed: 'Failed', cancelled: 'Cancelled', expired: 'Expired', review: 'Needs review',
  refunded: 'Refunded', partially_refunded: 'Partially refunded',
};

export function PaymentStatusBadge({ status }) {
  return <Badge value={PAYMENT_LABEL[status] || status || 'Unknown'} tone={PAYMENT_TONE[status] || 'neutral'} />;
}

const VERIFICATION_TONE = {
  provider_verified: 'success', awaiting_verification: 'warning', mismatch: 'danger', unverified: 'neutral',
};

const VERIFICATION_LABEL = {
  provider_verified: 'Verified by PayMongo',
  awaiting_verification: 'Awaiting verification',
  mismatch: 'Verification failed',
  unverified: 'Not verified',
};

/**
 * Verification comes only from the API: "Verified by PayMongo" means the server re-fetched the
 * payment from PayMongo and checked it.
 */
export function VerificationBadge({ verification, label }) {
  return <Badge value={label || VERIFICATION_LABEL[verification] || 'Not verified'} tone={VERIFICATION_TONE[verification] || 'neutral'} />;
}

export const pesoAmount = (amount) => `₱${Number(amount || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

export const methodText = (tx) => tx?.methodLabel
  || (tx?.requestedMethod ? `${String(tx.requestedMethod).replace(/_/g, ' ')} (chosen)` : '—');

/** GET /sk/subscription/payment-options: whether online checkout is available and which methods. */
export function usePaymentOptions() {
  const [state, setState] = useState({ loading: true, error: null, options: null });
  const load = useCallback(async () => {
    setState({ loading: true, error: null, options: null });
    try {
      const options = await api.get('/sk/subscription/payment-options');
      setState({ loading: false, error: null, options });
    } catch (err) {
      setState({ loading: false, error: friendlyApiMessage(err, 'Unable to check online payment availability.'), options: null });
    }
  }, []);
  useEffect(() => { load(); }, [load]);
  return { ...state, reload: load };
}
