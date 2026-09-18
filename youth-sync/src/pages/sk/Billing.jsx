import { useStore } from '../../store.jsx';
import { PageHeader } from '../../components/layouts.jsx';
import { Badge, Empty, Section, Table } from '../../components/ui.jsx';
import { Link } from 'react-router-dom';

const peso = (amount) => `₱${Number(amount || 0).toLocaleString()}`;

/**
 * Billing is presentational only. Payment records come from the frontend store
 * and are structured so a backend can supply them later without UI changes.
 */
export default function Billing() {
  const { org, plan, planName, scoped, qrUses, usage, limits } = useStore();
  const payments = scoped('payments');
  const latest = payments[0];

  const cycleLabel = org.cycle === 'none' ? '—' : org.cycle;
  const amount = org.cycle === 'yearly' ? plan.priceYearly : plan.priceMonthly;

  return (
    <>
      <PageHeader title="Billing"
        subtitle="Subscription, payment method and billing history."
        actions={<Link to="/sk/subscription/plans" className="btn btn-primary">Change Plan</Link>} />

      <div className="grid gap-5 lg:grid-cols-3">
        <Section title="Current subscription">
          <dl className="mt-4 space-y-3 text-sm">
            <div className="flex items-center justify-between gap-4">
              <dt className="text-ink-muted">Plan</dt><dd className="font-semibold">{planName}</dd>
            </div>
            <div className="flex items-center justify-between gap-4">
              <dt className="text-ink-muted">Status</dt><dd><Badge value={org.subStatus} /></dd>
            </div>
            <div className="flex items-center justify-between gap-4">
              <dt className="text-ink-muted">Billing cycle</dt><dd className="font-semibold capitalize">{cycleLabel}</dd>
            </div>
            <div className="flex items-center justify-between gap-4">
              <dt className="text-ink-muted">Amount</dt>
              <dd className="font-semibold">{amount ? `${peso(amount)} / ${org.cycle === 'yearly' ? 'year' : 'month'}` : peso(0)}</dd>
            </div>
            <div className="flex items-center justify-between gap-4">
              <dt className="text-ink-muted">Next billing</dt>
              <dd className="font-semibold">{org.expiresIn === null ? '—' : `In ${org.expiresIn} day(s)`}</dd>
            </div>
          </dl>
        </Section>

        <Section title="Plan usage">
          <div className="mt-4 space-y-3.5">
            {[['youth', 'Youth records', usage.youth], ['accounts', 'User accounts', usage.accounts],
              ['programs', 'Programs', usage.programs], ['assistance', 'Assistance', usage.assistance],
              ['qr', 'QR scans', qrUses]].map(([key, label, used]) => {
              const limit = limits[key];
              const pct = limit ? Math.min(100, Math.round((used / limit) * 100)) : 0;
              return (
                <div key={key}>
                  <div className="flex justify-between text-xs">
                    <span className="text-ink-muted">{label}</span>
                    <span className="font-semibold">{used.toLocaleString()} / {limit === null ? 'Unlimited' : limit.toLocaleString()}</span>
                  </div>
                  <div className="mt-1 h-1.5 rounded-full bg-shell">
                    <div className="h-1.5 rounded-full transition-all"
                      style={{ width: `${limit ? Math.max(3, pct) : 4}%`,
                        background: pct >= 100 ? '#A3231C' : pct >= 80 ? '#E0A21A' : '#22568C' }} />
                  </div>
                </div>
              );
            })}
          </div>
        </Section>

        <Section title="Payment method">
          {latest ? (
            <>
              <dl className="mt-4 space-y-3 text-sm">
                <div className="flex items-center justify-between gap-4">
                  <dt className="text-ink-muted">Method</dt>
                  <dd className="font-semibold capitalize">{String(latest.method || '').replace(/_/g, ' ')}</dd>
                </div>
                <div className="flex items-center justify-between gap-4">
                  <dt className="text-ink-muted">Reference</dt>
                  <dd className="font-mono text-xs">{latest.reference}</dd>
                </div>
              </dl>
              <button type="button" className="btn btn-secondary btn-sm mt-4" disabled>Update Payment Method</button>
              <p className="mt-2 t-help">Payment method management is handled by the payment provider.</p>
            </>
          ) : (
            <p className="mt-4 t-muted">No payment method on file.</p>
          )}
        </Section>
      </div>

      <div className="mt-5">
        <h2 className="t-section mb-3">Billing history</h2>
        {payments.length === 0 ? (
          <Empty title="No billing history." body="Payments appear here once a paid plan is activated." />
        ) : (
          <Table head={['Date', 'Description', 'Plan', 'Amount', 'Status', 'Reference']} minWidth={760}>
            {payments.map((payment) => (
              <tr key={payment.id} className="row-hover">
                <td className="td">{payment.paidAt || '—'}</td>
                <td className="td">{payment.cycle === 'yearly' ? 'Annual subscription' : 'Monthly subscription'}</td>
                <td className="td capitalize">{payment.planCode}</td>
                <td className="td font-semibold">{peso(payment.amount)}</td>
                <td className="td"><Badge value={payment.status} /></td>
                <td className="td font-mono text-xs text-ink-muted">{payment.reference}</td>
              </tr>
            ))}
          </Table>
        )}
      </div>
    </>
  );
}
