import { useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { useStore } from '../../store.jsx';
import { PageHeader, subLabel, skInbox } from '../../components/layouts.jsx';
import { Badge, BarChart, Confirm, Empty, Field, Section, Stat, Tabs } from '../../components/ui.jsx';
import { age, fullName } from '../../data/mock.js';

const CATEGORIES = {
  scholarship: 'Scholarship', assistance: 'Assistance', programs: 'Programs & events',
  applications: 'Application updates', subscription: 'Subscription', system: 'System',
};

export function Notifications() {
  const { org, db, readNotification, readAllNotifications, deleteNotification } = useStore();
  const [filter, setFilter] = useState('all');
  const all = db.notifications.filter((n) => skInbox(n, org.id));

  const rows = all.filter((n) => {
    if (filter === 'all') return true;
    if (filter === 'unread') return !n.read;
    if (filter === 'read') return n.read;
    return n.category === filter;
  });

  const count = (key) => (key === 'all' ? all.length
    : key === 'unread' ? all.filter((n) => !n.read).length
    : key === 'read' ? all.filter((n) => n.read).length
    : all.filter((n) => n.category === key).length);

  const unread = count('unread');

  return (
    <>
      <PageHeader title="Notifications" subtitle={`${unread} unread of ${all.length} total`}
        actions={<>
          <Link to="/sk/outbox" className="btn btn-ghost">Open SMS &amp; email outbox</Link>
          {unread > 0 && <Confirm message="Mark every notification as read?"
            onConfirm={() => readAllNotifications((n) => skInbox(n, org.id))}>Mark all as read</Confirm>}
        </>} />

<Tabs active={filter} onSelect={setFilter} items={[
  { key: 'all', label: 'All', count: count('all') },
  { key: 'unread', label: 'Unread', count: count('unread') },
  { key: 'read', label: 'Read', count: count('read') },
  ...Object.entries(CATEGORIES).map(([key, label]) => ({ key, label, count: count(key) })),
]} />

      {rows.length === 0 ? (
        <Empty title="Nothing here" body="Notifications about applications, activities, and your subscription will appear in this inbox." />
      ) : (
        <div className="card divide-y divide-line">
          {rows.map((n) => (
            <div key={n.id} className={`flex flex-wrap items-start gap-3 p-4 ${n.read ? '' : 'bg-[#F8FAFD]'}`}>
              <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                  {!n.read && <span className="h-2 w-2 rounded-full bg-navy-600" />}
                  <p className="text-sm font-semibold text-navy-900">{n.title}</p>
                  <span className="badge bg-[#EEF1F5] text-[#4A5B70]">{CATEGORIES[n.category]}</span>
                </div>
                <p className="mt-1 text-sm text-[#5A6C82]">{n.body}</p>
                <p className="mt-1 text-xs text-[#8391A4]">{n.createdAt}</p>
              </div>
              <div className="flex gap-2">
                {n.link
                  ? <Link to={n.link} onClick={() => readNotification(n.id)} className="btn btn-ghost btn-sm">Open</Link>
                  : !n.read && <button type="button" className="btn btn-ghost btn-sm" onClick={() => readNotification(n.id)}>Mark as read</button>}
                <Confirm className="btn btn-danger btn-sm" message="Delete this notification?" onConfirm={() => deleteNotification(n.id)}>Delete</Confirm>
              </div>
            </div>
          ))}
        </div>
      )}
    </>
  );
}

const groupCount = (rows, key) => rows.reduce((acc, row) => {
  const k = typeof key === 'function' ? key(row) : row[key];
  acc[k] = (acc[k] || 0) + 1;
  return acc;
}, {});

export function Reports() {
  const { scoped, db, org, allows, plan, planName, notify } = useStore();
  const [type, setType] = useState('youth');
  const youth = scoped('youth').filter((y) => !y.archived);
  const assistance = scoped('assistance');
  const beneficiaries = scoped('beneficiaries');
  const programs = scoped('programs');
  const registrations = scoped('registrations');
  const payments = scoped('payments');

  const approved = beneficiaries.filter((b) => ['approved', 'released'].includes(b.status));
  const categoryOf = (b) => assistance.find((a) => a.id === b.programId)?.category;

  const download = (format) => {
    if (format === 'xls' && !allows('excel_export')) {
      notify(`Excel export is not included in the ${plan.name} plan. Upgrade to unlock it.`, 'warning');
      return;
    }
    notify(`In the full build this downloads a ${format.toUpperCase()} file. This preview has no backend to generate it.`);
  };

  return (
    <>
      <PageHeader title="Reports" subtitle={`Generated ${new Date().toDateString()}`}
        actions={<>
          <button type="button" className="btn btn-ghost" onClick={() => window.print()}>Print</button>
          <button type="button" className="btn btn-ghost" onClick={() => download('csv')}>Export CSV</button>
          <button type="button" className="btn btn-ghost" onClick={() => download('xls')}>Export Excel</button>
        </>} />

      <Tabs active={type} onSelect={setType} items={[
        { key: 'youth', label: 'Youth report' },
        { key: 'assistance', label: 'Assistance report' },
        { key: 'programs', label: 'Program report' },
        { key: 'subscription', label: 'Subscription report' },
      ]} />

      {!allows('pdf_export') && (
        <p className="mb-4 rounded-card border border-[#F2DCA8] bg-sun-100 px-4 py-2.5 text-sm text-[#7A5A05]">
          PDF and Excel export are Basic and Premium features. Print and CSV export are available on your plan.{' '}
          <Link to="/sk/subscription/plans" className="font-semibold underline">View plans</Link>
        </p>
      )}

      {type === 'youth' && (
        <>
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <Stat label="Total youth" value={youth.length.toLocaleString()} />
            {Object.entries(groupCount(youth, (y) => (age(y.birthDate) <= 17 ? '15-17' : age(y.birthDate) <= 24 ? '18-24' : '25-30')))
              .sort()
              .map(([group, value]) => <Stat key={group} label={`Age ${group}`} value={value.toLocaleString()} />)}
          </div>
          <div className="mt-4 grid gap-4 lg:grid-cols-3">
            <BarChart title="Gender" series={groupCount(youth, 'gender')} />
            <BarChart title="Education" series={groupCount(youth, 'education')} />
            <BarChart title="Employment" series={groupCount(youth, 'employment')} />
          </div>
        </>
      )}

      {type === 'assistance' && (
        <>
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <Stat label="Youth needing assistance" value={youth.filter((y) => y.level === 'High').length} hint="High priority" />
            <Stat label="Scholarship beneficiaries" value={approved.filter((b) => categoryOf(b) === 'scholarship').length} />
            <Stat label="Financial assistance" value={approved.filter((b) => categoryOf(b) === 'financial').length} />
            <Stat label="Other assistance" value={approved.filter((b) => categoryOf(b) === 'other').length} />
          </div>
          <div className="mt-4 grid gap-4 lg:grid-cols-2">
            <BarChart title="Priority distribution" color="#E0A21A" series={groupCount(youth, 'level')} />
            <Section title="Priority list">
              <p className="mt-2 text-sm text-[#5A6C82]">The High priority youth currently on file, with the reasons recorded for each.</p>
              <ul className="mt-3 max-h-56 space-y-1.5 overflow-y-auto text-sm text-[#4A5B70]">
                {youth.filter((y) => y.level === 'High').slice(0, 15).map((y) => (
                  <li key={y.id}>· <Link to={`/sk/youth/${y.id}`} className="underline">{fullName(y)}</Link> — {y.reasons[0]}</li>
                ))}
              </ul>
            </Section>
          </div>
        </>
      )}

      {type === 'programs' && (
        <>
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
            <Stat label="Programs" value={programs.filter((p) => p.kind === 'program').length} />
            <Stat label="Events" value={programs.filter((p) => p.kind === 'event').length} />
            <Stat label="Registrations" value={registrations.length} />
            <Stat label="Unique participants" value={new Set(registrations.map((r) => r.youthId)).size} />
            <Stat label="Attended" value={registrations.filter((r) => r.attendance === 'present').length} />
          </div>
          <div className="mt-4"><BarChart title="Activities by status" series={groupCount(programs, 'status')} /></div>
        </>
      )}

      {type === 'subscription' && (
        <>
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <Stat label="Current plan" value={planName} />
            <Stat label="Status" value={subLabel(org)} />
            <Stat label="Billing cycle" value={org.cycle} />
            <Stat label="Days remaining" value={org.expiresIn === null ? 'No expiration' : org.expiresIn} />
          </div>
          <div className="card mt-4 overflow-x-auto">
            <table className="w-full" style={{ minWidth: 600 }}>
              <thead><tr>{['Reference', 'Plan', 'Cycle', 'Amount', 'Status', 'Date'].map((h) => <th key={h} className="th">{h}</th>)}</tr></thead>
              <tbody>
                {payments.length === 0 ? (
                  <tr><td className="td text-[#7A889B]" colSpan={6}>No payments recorded.</td></tr>
                ) : payments.map((p) => (
                  <tr key={p.id}>
                    <td className="td font-mono text-xs">{p.reference}</td>
                    <td className="td">{p.planCode}</td>
                    <td className="td">{p.cycle}</td>
                    <td className="td">₱{p.amount.toLocaleString()}</td>
                    <td className="td"><Badge value={p.status} /></td>
                    <td className="td">{p.paidAt || '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}

      <p className="mt-6 text-xs text-[#8391A4]">
        Report covers {db.youth.filter((y) => y.orgId === org.id).length} youth record(s) held by {org.name}.
      </p>
    </>
  );
}

const FEATURE_LABELS = {
  csv_import: 'CSV import', excel_export: 'Excel export', pdf_export: 'PDF export',
  advanced_analytics: 'Advanced analytics', advanced_reports: 'Advanced reports',
  activity_logs: 'Activity logs', audit_trail: 'Detailed audit trail',
  backup_tools: 'Backup tools', priority_support: 'Priority support',
};

export function Subscription() {
  const { org, plan, planName, usage, limits, scoped, downgrade, qrUses } = useStore();
  const payments = scoped('payments');
  const [target, setTarget] = useState('basic');

  return (
    <>
      <PageHeader title="Subscription" subtitle="One subscription covers your whole organization."
        actions={<Link to="/sk/subscription/plans" className="btn btn-primary">View plans</Link>} />

      <div className="grid gap-4 lg:grid-cols-3">
        <section className="card p-5 lg:col-span-2">
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div>
              <p className="text-xs text-[#7A889B]">Current plan</p>
              <h2 className="text-2xl font-bold text-navy-900">{planName}</h2>
              <p className="text-sm text-[#5A6C82]">{plan.tagline}</p>
            </div>
            <Badge value={subLabel(org)} />
          </div>

          <dl className="mt-5 grid gap-4 sm:grid-cols-3">
            <div><dt className="text-xs text-[#7A889B]">Billing cycle</dt><dd className="text-sm font-medium text-navy-900">{org.cycle}</dd></div>
            <div><dt className="text-xs text-[#7A889B]">Days remaining</dt><dd className="text-sm font-medium text-navy-900">{org.expiresIn === null ? 'No expiration' : org.expiresIn}</dd></div>
            <div><dt className="text-xs text-[#7A889B]">Status</dt><dd className="text-sm font-medium text-navy-900">{org.subStatus}</dd></div>
          </dl>

          <h3 className="mt-6 text-sm font-bold text-navy-900">Usage against your plan</h3>
          <div className="mt-3 space-y-3">
            {[['youth', 'Youth records'], ['accounts', 'User accounts'], ['programs', 'Active programs & events'],
              ['assistance', 'Active assistance programs'], ['qr', 'QR scans used']].map(([key, label]) => {
              const limit = limits[key];
              const used = key === 'qr' ? qrUses : usage[key];
              const pct = limit ? Math.min(100, Math.round((used / limit) * 100)) : 0;
              return (
                <div key={key}>
                  <div className="flex justify-between text-xs text-[#5A6C82]">
                    <span>{label}</span>
                    <span className="font-semibold text-navy-900">{used.toLocaleString()} / {limit === null ? 'Unlimited' : limit.toLocaleString()}</span>
                  </div>
                  <div className="mt-1 h-2 rounded-full bg-[#EDF0F4]">
                    <div className="h-2 rounded-full" style={{ width: `${limit ? Math.max(2, pct) : 4}%`, background: pct >= 100 ? '#B3261E' : pct >= 80 ? '#E0A21A' : '#22568C' }} />
                  </div>
                </div>
              );
            })}
          </div>

          {['active', 'trial'].includes(org.subStatus) && (
            <div className="mt-6 flex flex-wrap items-end gap-3 border-t border-line pt-5">
              <Field label="Request a downgrade">
                <select className="field" value={target} onChange={(e) => setTarget(e.target.value)}>
                  <option value="basic">Basic</option><option value="free">Free</option>
                </select>
              </Field>
              <Confirm message="Request this downgrade? All existing records are kept." onConfirm={() => downgrade(target)}>Request downgrade</Confirm>
              <p className="w-full text-xs text-[#8391A4]">Records are never deleted. You simply cannot add new ones beyond the lower plan limit.</p>
            </div>
          )}
        </section>

        <aside className="space-y-4">
          <Section title={`Included in ${planName}`}>
            <ul className="mt-3 space-y-1.5 text-sm">
              {Object.entries(FEATURE_LABELS).map(([key, label]) => (
                <li key={key} className={`flex items-center gap-2 ${plan.features[key] ? 'text-navy-900' : 'text-[#9AA7B6]'}`}>
                  <span>{plan.features[key] ? '✓' : '—'}</span>{label}
                </li>
              ))}
            </ul>
          </Section>

          <Section title="Payment history">
            {payments.length === 0 ? <p className="mt-3 text-sm text-[#7A889B]">No payments recorded yet.</p> : payments.map((p) => (
              <div key={p.id} className="mt-3 border-b border-[#EEF1F4] pb-2 text-sm last:border-0">
                <div className="flex justify-between">
                  <span className="font-medium text-navy-900">₱{p.amount.toLocaleString()}</span>
                  <Badge value={p.status} />
                </div>
                <p className="text-xs text-[#8391A4]">{p.reference} · {p.paidAt}</p>
              </div>
            ))}
          </Section>
        </aside>
      </div>
    </>
  );
}

export function Plans() {
  const { plans, org, downgrade } = useStore();
  const [cycle, setCycle] = useState('monthly');
  const navigate = useNavigate();
  const limitLabel = (v) => (v === null ? 'Unlimited' : v.toLocaleString());

  return (
    <>
      <PageHeader title="Choose a plan" subtitle="Yearly billing saves about 17%." />

      <div className="mb-5 inline-flex rounded-lg border border-line bg-white p-1">
        {['monthly', 'yearly'].map((option) => (
          <button key={option} type="button" onClick={() => setCycle(option)}
            className={`rounded-md px-4 py-1.5 text-sm font-semibold ${cycle === option ? 'bg-navy-900 text-white' : 'text-[#4A5B70]'}`}>
            {option === 'monthly' ? 'Monthly' : 'Yearly — save 17%'}
          </button>
        ))}
      </div>

      <div className="grid gap-5 lg:grid-cols-3">
        {plans.map((plan) => {
          const isCurrent = org.plan === plan.code && org.subStatus !== 'expired';
          const price = cycle === 'yearly' ? plan.priceYearly : plan.priceMonthly;
          return (
            <div key={plan.code} className={`card flex flex-col p-6 ${plan.popular ? 'border-navy-900 ring-1 ring-navy-900' : ''}`}>
              {plan.popular && <span className="badge mb-3 self-start bg-navy-900 text-white">Most popular</span>}
              <h3 className="text-lg font-bold text-navy-900">{plan.name}</h3>
              <p className="mt-1 text-sm text-[#5A6C82]">{plan.tagline}</p>
              <p className="mt-4 text-3xl font-bold text-navy-900">₱{price.toLocaleString()}</p>
              <p className="text-sm text-[#7A889B]">{price > 0 ? `per ${cycle === 'yearly' ? 'year' : 'month'}` : 'Permanent. Does not expire.'}</p>
              {plan.priceYearly > 0 && <p className="mt-1 text-sm text-[#12664A]">Save ₱{(plan.priceMonthly * 12 - plan.priceYearly).toLocaleString()} on yearly billing</p>}

              <ul className="mt-5 flex-1 space-y-1.5 text-sm text-[#4A5B70]">
                <li>{limitLabel(plan.limits.youth)} youth records</li>
                <li>{limitLabel(plan.limits.accounts)} staff account(s)</li>
                <li>{limitLabel(plan.limits.programs)} active programs and events</li>
                <li>{limitLabel(plan.limits.assistance)} active assistance programs</li>
                <li>{plan.features.csv_import ? 'CSV import' : 'Manual data entry'}</li>
                <li>{plan.features.advanced_reports ? 'Advanced reports and analytics' : 'Standard reports'}</li>
              </ul>

              {isCurrent ? (
                <span className="btn btn-ghost mt-6 opacity-70">Your current plan</span>
              ) : plan.code === 'free' ? (
                <Confirm className="btn btn-ghost mt-6" message="Move to the Free plan? Your records are kept." onConfirm={() => downgrade('free')}>Move to Free</Confirm>
              ) : (
                <button type="button" className={`btn mt-6 ${plan.popular ? 'btn-primary' : 'btn-ghost'}`}
                  onClick={() => navigate(`/sk/subscription/checkout?plan=${plan.code}&cycle=${cycle}`)}>
                  Choose {plan.name}
                </button>
              )}
            </div>
          );
        })}
      </div>
    </>
  );
}

export function Checkout() {
  const [params] = useSearchParams();
  const navigate = useNavigate();
  const { plans, payAndActivate } = useStore();
  const [method, setMethod] = useState('ewallet');
  const [confirmed, setConfirmed] = useState(false);
  const [outcome, setOutcome] = useState('success');
  const [receipt, setReceipt] = useState(null);

  const code = params.get('plan') || 'basic';
  const cycle = params.get('cycle') || 'monthly';
  const plan = plans.find((p) => p.code === code) || plans[1];
  const amount = cycle === 'yearly' ? plan.priceYearly : plan.priceMonthly;
  const expires = new Date();
  expires.setDate(expires.getDate() + (cycle === 'yearly' ? 365 : 30));

  if (receipt && receipt.status === 'failed') {
    return (
      <>
        <PageHeader title="Payment failed" subtitle="Step 5 of 5" />
        <div className="card mx-auto max-w-xl p-6">
          <span className="grid h-12 w-12 place-items-center rounded-full bg-[#FCEDEC] text-lg">!</span>
          <h2 className="mt-4 text-lg font-bold text-navy-900">Your payment did not go through</h2>
          <p className="mt-1 text-sm text-[#5A6C82]">
            Reference {receipt.reference}. Your subscription was not activated and nothing was charged.
            Your existing records are untouched.
          </p>
          <div className="mt-6 flex gap-3">
            <button type="button" className="btn btn-primary" onClick={() => { setReceipt(null); setOutcome('success'); }}>Retry payment</button>
            <Link to="/sk/subscription/plans" className="btn btn-ghost">Back to plans</Link>
          </div>
        </div>
      </>
    );
  }

  if (receipt) {
    return (
      <>
        <PageHeader title="Subscription confirmed" subtitle="Step 5 of 5" />
        <div className="card mx-auto max-w-xl p-6">
          <span className="grid h-12 w-12 place-items-center rounded-full bg-[#E7F5EE] text-lg">✓</span>
          <h2 className="mt-4 text-lg font-bold text-navy-900">{plan.name} plan is active</h2>
          <p className="mt-1 text-sm text-[#5A6C82]">
            Payment confirmed automatically — no admin approval was needed. Your subscription runs until {expires.toDateString()}.
          </p>
          <dl className="mt-5 space-y-2.5 border-t border-line pt-5 text-sm">
            <div className="flex justify-between"><dt className="text-[#5A6C82]">Reference number</dt><dd className="font-mono text-xs font-semibold text-navy-900">{receipt.reference}</dd></div>
            <div className="flex justify-between"><dt className="text-[#5A6C82]">Amount</dt><dd className="font-semibold text-navy-900">₱{receipt.amount.toLocaleString()}</dd></div>
            <div className="flex justify-between"><dt className="text-[#5A6C82]">Billing cycle</dt><dd className="font-semibold text-navy-900">{receipt.cycle}</dd></div>
            <div className="flex justify-between"><dt className="text-[#5A6C82]">Payment status</dt><dd><Badge value={receipt.status} /></dd></div>
          </dl>
          <p className="mt-4 rounded-lg bg-sun-100 px-3 py-2 text-xs leading-relaxed text-[#7A5A05]">
            This is a payment record. No payment gateway is connected to this preview.
          </p>
          <div className="mt-6 flex gap-3">
            <Link to="/sk/subscription" className="btn btn-primary">View subscription</Link>
            <Link to="/sk" className="btn btn-ghost">Back to dashboard</Link>
          </div>
        </div>
      </>
    );
  }

  return (
    <>
      <PageHeader title="Checkout" subtitle="Step 3 of 5 — review your subscription, then confirm." />
      <div className="grid gap-4 lg:grid-cols-3">
        <section className="card p-5 lg:col-span-2">
          <ol className="flex flex-wrap gap-4 border-b border-line pb-4 text-xs">
            {['Select plan', 'Billing cycle', 'Review', 'Payment', 'Confirmation'].map((step, index) => (
              <li key={step} className={`flex items-center gap-1.5 ${index <= 2 ? 'font-semibold text-navy-900' : 'text-[#9AA7B6]'}`}>
                <span className={`grid h-5 w-5 place-items-center rounded-full ${index <= 2 ? 'bg-navy-900 text-white' : 'bg-[#EDF0F4]'}`}>{index + 1}</span>{step}
              </li>
            ))}
          </ol>

          <dl className="mt-5 space-y-3 text-sm">
            {[['Plan', plan.name], ['Billing cycle', cycle], ['Price', `₱${amount.toLocaleString()}`],
              ['Start date', new Date().toDateString()], ['Expiration date', expires.toDateString()]].map(([label, value]) => (
              <div key={label} className="flex justify-between"><dt className="text-[#5A6C82]">{label}</dt><dd className="font-semibold text-navy-900">{value}</dd></div>
            ))}
            <div className="flex justify-between border-t border-line pt-3 text-base">
              <dt className="font-semibold text-navy-900">Total</dt><dd className="font-bold text-navy-900">₱{amount.toLocaleString()}</dd>
            </div>
          </dl>

          <form className="mt-6 space-y-4 border-t border-line pt-5" onSubmit={async (e) => {
            e.preventDefault();
            setReceipt(await payAndActivate(plan.code, cycle, outcome));
          }}>
            <Field label="Payment method">
              <select className="field" value={method} onChange={(e) => setMethod(e.target.value)}>
                <option value="ewallet">E-wallet</option>
                <option value="bank_transfer">Bank transfer</option>
              </select>
            </Field>
            <Field label="Simulate the gateway result" hint="There is no real gateway here, so you choose what it returns.">
              <select className="field" value={outcome} onChange={(e) => setOutcome(e.target.value)}>
                <option value="success">Payment succeeds → subscription activates automatically</option>
                <option value="fail">Payment fails → subscription stays inactive, retry allowed</option>
              </select>
            </Field>
            <label className="flex items-start gap-2 text-sm text-[#4A5B70]">
              <input type="checkbox" className="mt-0.5 h-4 w-4 rounded border-[#C7D0DA]" checked={confirmed} onChange={(e) => setConfirmed(e.target.checked)} required />
              I understand no real payment gateway is connected and this checkout records a simulated payment.
            </label>
            <button type="submit" className="btn btn-primary w-full" disabled={!confirmed}>Confirm subscription</button>
            <button type="button" className="btn btn-ghost w-full" onClick={() => navigate('/sk/subscription/plans')}>Back to plans</button>
          </form>
        </section>

        <aside className="card h-fit p-5">
          <h2 className="text-sm font-bold text-navy-900">What you unlock</h2>
          <ul className="mt-3 space-y-1.5 text-sm text-[#4A5B70]">
            <li>{plan.limits.youth === null ? 'Unlimited' : plan.limits.youth.toLocaleString()} youth records</li>
            <li>{plan.limits.accounts} staff accounts</li>
            <li>{plan.features.csv_import ? 'CSV import' : 'Manual entry'}</li>
            <li>{plan.features.advanced_reports ? 'Advanced reports' : 'Standard reports'}</li>
            <li>{plan.features.priority_support ? 'Priority support' : 'Standard support'}</li>
          </ul>
          <p className="mt-4 rounded-lg bg-shell px-3 py-2 text-xs leading-relaxed text-[#5A6C82]">
            On a successful payment the subscription activates immediately — no administrator approval step.
            A reminder is sent 3 days before expiration and the status changes to Expired automatically on the date.
          </p>
        </aside>
      </div>
    </>
  );
}

export function Settings() {
  const { org, user, users, usage, limits, plan, allows, scoped, updateOrg, saveStaff, removeStaff, notify, resetDemoData } = useStore();
  const [details, setDetails] = useState({ ...org });
  const [staff, setStaff] = useState({ name: '', email: '' });
  const team = users.filter((u) => u.orgId === org.id);
  const logs = scoped('activity');

  return (
    <>
      <PageHeader title="Settings" subtitle="Organization details, your account, and staff access." />
      <div className="grid gap-4 lg:grid-cols-2">
        <form className="card space-y-4 p-5" onSubmit={(e) => { e.preventDefault(); updateOrg(details); }}>
          <h2 className="text-sm font-bold text-navy-900">Organization</h2>
          <div className="grid gap-4 sm:grid-cols-2">
            {[['barangay', 'Barangay'], ['municipality', 'Municipality'], ['province', 'Province'],
              ['chairperson', 'SK chairperson'], ['email', 'Official email'], ['contact', 'Contact number']].map(([key, label]) => (
              <Field key={key} label={label}>
                <input className="field" value={details[key] || ''} onChange={(e) => setDetails({ ...details, [key]: e.target.value })} required />
              </Field>
            ))}
          </div>
          <button type="submit" className="btn btn-primary">Save organization details</button>
        </form>

        <form className="card space-y-4 p-5" onSubmit={(e) => { e.preventDefault(); notify('Your account details were saved.'); }}>
          <h2 className="text-sm font-bold text-navy-900">Your account</h2>
          <Field label="Full name"><input className="field" defaultValue={user.name} required /></Field>
          <Field label="Email"><input className="field" type="email" defaultValue={user.email} required /></Field>
          <Field label="Current password"><input className="field" type="password" /></Field>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="New password"><input className="field" type="password" /></Field>
            <Field label="Confirm"><input className="field" type="password" /></Field>
          </div>
          <button type="submit" className="btn btn-primary">Save account</button>
        </form>

        <Section title="Staff accounts">
          <p className="mt-1 text-xs text-[#7A889B]">
            {usage.accounts} of {limits.accounts === null ? 'unlimited' : limits.accounts} allowed on the {plan.name} plan.
          </p>
          <div className="mt-3 divide-y divide-line">
            {team.map((member) => (
              <div key={member.id} className="flex items-center justify-between py-2">
                <div>
                  <p className="text-sm font-medium text-navy-900">
                    {member.name} {member.owner && <span className="badge bg-[#EEF1F5] text-[#4A5B70]">Owner</span>}
                  </p>
                  <p className="text-xs text-[#8391A4]">{member.email}</p>
                </div>
                {!member.owner && (
                  <Confirm className="btn btn-danger btn-sm" message="Remove this staff account?" onConfirm={() => removeStaff(member.id)}>Remove</Confirm>
                )}
              </div>
            ))}
          </div>
          <form className="mt-4 space-y-3 border-t border-line pt-4" onSubmit={(e) => {
            e.preventDefault();
            if (!staff.name || !staff.email) return;
            saveStaff(staff);
            setStaff({ name: '', email: '' });
          }}>
            <div className="grid gap-3 sm:grid-cols-3">
              <input className="field" placeholder="Full name" value={staff.name} onChange={(e) => setStaff({ ...staff, name: e.target.value })} />
              <input className="field" type="email" placeholder="Email" value={staff.email} onChange={(e) => setStaff({ ...staff, email: e.target.value })} />
              <input className="field" type="password" placeholder="Password" />
            </div>
            <button type="submit" className="btn btn-ghost">Add staff account</button>
          </form>
        </Section>

        <Section title="System data">
          <p className="mt-2 text-sm text-[#5A6C82]">
            Everything you add is saved in this browser&apos;s localStorage, so a refresh keeps it.
            Resetting restores the starting records and signs you out.
          </p>
          <Confirm className="btn btn-danger mt-3"
            message="Reset all records? Everything added in this browser will be lost."
            onConfirm={resetDemoData}>Reset records</Confirm>
        </Section>

        <Section title="Activity log">
          {!allows('activity_logs') ? (
            <p className="mt-3 text-sm text-[#7A889B]">Activity logs are a Basic and Premium feature.</p>
          ) : (
            <div className="mt-3 max-h-80 space-y-2 overflow-y-auto">
              {logs.map((log) => (
                <div key={log.id} className="border-b border-[#EEF1F4] pb-2 last:border-0">
                  <p className="text-sm text-navy-900">{log.description}</p>
                  <p className="text-xs text-[#8391A4]">{log.user} · {log.at} · {log.ip}</p>
                </div>
              ))}
            </div>
          )}
        </Section>
      </div>
    </>
  );
}
