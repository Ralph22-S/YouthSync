import { useStore } from '../../store.jsx';
import { PageHeader } from '../../components/layouts.jsx';
import { Badge, Empty, Pagination, Section, Skeleton, Table } from '../../components/ui.jsx';
import { Link } from 'react-router-dom';
import { pageSizesFor, usePagedList } from '../../lib/usePagedList.js';
import { methodText, PaymentStatusBadge, pesoAmount, usePaymentOptions, VerificationBadge } from '../../components/payments.jsx';

const peso = (amount) => `₱${Number(amount || 0).toLocaleString()}`;

/**
 * Billing summary. Plan, status, expiry and usage come from GET /sk/subscription;
 * the amount is the backend catalog's reference list price for the stored plan
 * and cycle. Online payments (PayMongo) are listed from GET /sk/subscription/checkouts;
 * nothing on this page charges anything.
 */
export default function Billing() {
  const { org, plan, storedPlan, usage, limits } = useStore();
  const payment = usePaymentOptions();
  const online = usePagedList('/sk/subscription/checkouts', {}, { perPage: 10, errorMessage: 'Unable to load online payments.' });
  const listed = storedPlan || plan;

  const cycleLabel = org.cycle === 'none' ? '—' : org.cycle;
  const amount = org.cycle === 'yearly' ? listed?.priceYearly : org.cycle === 'monthly' ? listed?.priceMonthly : null;
  const nextDate = org.daysRemaining === null || org.daysRemaining === undefined
    ? (org.subStatus === 'expired' || org.subStatus === 'cancelled' ? `${org.subStatusLabel || org.subStatus}${org.expiresAt ? ` (${org.expiresAt})` : ''}` : '—')
    : `${org.expiresAt} · in ${org.daysRemaining} day(s)`;

  return (
    <>
      <PageHeader title="Billing"
        subtitle="Subscription, payment method and online payments."
        actions={<Link to="/sk/subscription/plans" className="btn btn-primary">Change Plan</Link>} />

      <div className="grid gap-5 lg:grid-cols-3">
        <Section title="Current subscription">
          <dl className="mt-4 space-y-3 text-sm">
            <div className="flex items-center justify-between gap-4">
              <dt className="text-ink-muted">Plan</dt><dd className="font-semibold">{listed?.name}{org.restrictedToFree ? ' (Free limits apply)' : ''}</dd>
            </div>
            <div className="flex items-center justify-between gap-4">
              <dt className="text-ink-muted">Status</dt><dd><Badge value={org.subStatusLabel || org.subStatus} /></dd>
            </div>
            <div className="flex items-center justify-between gap-4">
              <dt className="text-ink-muted">Billing cycle</dt><dd className="font-semibold capitalize">{cycleLabel}</dd>
            </div>
            <div className="flex items-center justify-between gap-4">
              <dt className="text-ink-muted">List price</dt>
              <dd className="font-semibold">{amount ? `${peso(amount)} / ${org.cycle === 'yearly' ? 'year' : 'month'}` : '—'}</dd>
            </div>
            <div className="flex items-center justify-between gap-4">
              <dt className="text-ink-muted">Term ends</dt>
              <dd className="font-semibold">{nextDate}</dd>
            </div>
          </dl>
          <p className="mt-3 t-help">
            {payment.options?.enabled
              ? `Online payment through PayMongo is available${payment.options.mode === 'test' ? ' (TEST mode)' : ''}. Use Change Plan to pay.`
              : 'List prices are for reference. Online payment is not configured.'}
          </p>
        </Section>

        <Section title="Plan usage">
          <div className="mt-4 space-y-3.5">
            {[['youth', 'Youth records', usage.youth], ['accounts', 'User accounts', usage.accounts],
              ['programs', 'Programs', usage.programs], ['assistance', 'Assistance', usage.assistance],
              ['qr', 'QR scans (lifetime)', usage.qr]].map(([key, label, used = 0]) => {
              const limit = limits[key] ?? null;
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
          <p className="mt-4 t-muted">
            {payment.options?.enabled
              ? 'Paid online through PayMongo at checkout. No card or wallet details are stored in YouthSync.'
              : 'No payment method on file.'}
          </p>
        </Section>
      </div>

      <div className="mt-5" data-testid="online-payments">
        <h2 className="t-section mb-3">Online payments (PayMongo)</h2>
        {online.error ? (
          <Empty title="Online payments could not be loaded" body={online.error}>
            <button type="button" className="btn btn-primary" onClick={online.reload}>Retry</button>
          </Empty>
        ) : !online.data ? (
          <Skeleton rows={2} />
        ) : online.rows.length === 0 ? (
          <Empty title="No online payments." body="Checkouts you start from Plans appear here with their confirmed status." />
        ) : (
          <>
            <Table head={['Started', 'Plan', 'Amount', 'Method', 'Status', 'Verification', 'Paid', 'Reference']} minWidth={940}>
              {online.rows.map((tx) => (
                <tr key={tx.id} className="row-hover">
                  <td className="td">{tx.createdAt || '—'}</td>
                  <td className="td">{tx.planName} · <span className="capitalize">{tx.cycle}</span>{tx.mode === 'test' ? <span className="ml-1 text-xs text-warning">(test)</span> : null}</td>
                  <td className="td font-semibold">{pesoAmount(tx.amount)}</td>
                  <td className="td">{methodText(tx)}</td>
                  <td className="td">
                    <PaymentStatusBadge status={tx.status} />
                    {tx.status === 'pending' && <Link className="ml-2 text-xs underline" to={`/sk/subscription/payment/success?tx=${tx.id}`}>Check</Link>}
                  </td>
                  <td className="td"><VerificationBadge verification={tx.verification} label={tx.verificationLabel} /></td>
                  <td className="td">{tx.paidAt || '—'}</td>
                  <td className="td font-mono text-xs text-ink-muted">{tx.reference}</td>
                </tr>
              ))}
            </Table>
            <Pagination page={online.page} pageSize={online.perPage} total={online.total} totalPages={online.totalPages}
              onPage={online.setPage} loading={online.loading} noun="payments"
              pageSizes={pageSizesFor(10)} onPageSize={online.setPerPage} />
          </>
        )}
      </div>
    </>
  );
}
