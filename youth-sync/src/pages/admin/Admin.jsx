import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { useStore } from '../../store.jsx';
import { PageHeader, subLabel } from '../../components/layouts.jsx';
import { Badge, BarChart, Confirm, Empty, Field, Section, Stat, Table } from '../../components/ui.jsx';
import { MUNICIPALITIES, planByCode, SUBSCRIPTION_STATUSES } from '../../data/mock.js';

export function AdminDashboard() {
  const { orgs, users, db, runCycle } = useStore();
  const active = orgs.filter((o) => ['active', 'trial'].includes(o.subStatus));
  const expiring = active.filter((o) => o.expiresIn !== null && o.expiresIn >= 0 && o.expiresIn <= 3);
  const pending = orgs.filter((o) => o.status === 'pending');
  const paid = db.payments.filter((p) => p.status === 'paid');

  const stats = [
    ['SK organizations', orgs.length],
    ['Active subscriptions', active.length],
    ['Expiring in 3 days', expiring.length],
    ['Expired', orgs.filter((o) => o.subStatus === 'expired').length],
    ['Free plan', orgs.filter((o) => o.plan === 'free').length],
    ['Trial', orgs.filter((o) => o.subStatus === 'trial').length],
    ['Basic', orgs.filter((o) => o.plan === 'basic' && o.subStatus === 'active').length],
    ['Premium', orgs.filter((o) => o.plan === 'premium' && o.subStatus === 'active').length],
    ['Total users', users.length],
    ['Pending registrations', pending.length],
    ['Revenue collected', `₱${paid.reduce((sum, p) => sum + p.amount, 0).toLocaleString()}`],
    ['Payments recorded', db.payments.length],
  ];

  const byPlan = orgs.reduce((acc, o) => { const n = planByCode(o.plan).name; acc[n] = (acc[n] || 0) + 1; return acc; }, {});
  const byStatus = orgs.reduce((acc, o) => { acc[o.subStatus] = (acc[o.subStatus] || 0) + 1; return acc; }, {});
  const revenueByPlan = paid.reduce((acc, p) => { acc[planByCode(p.planCode).name] = (acc[planByCode(p.planCode).name] || 0) + p.amount; return acc; }, {});

  return (
    <>
      <PageHeader title="System overview" subtitle="Every SK organization, subscription, and payment on this deployment."
        actions={<>
          <Confirm message="Run the subscription cycle now? This advances expiry counters and expires due subscriptions." onConfirm={runCycle}>
            Run subscription cycle
          </Confirm>
          <Link to="/admin/organizations/new" className="btn btn-primary">Add organization</Link>
        </>} />

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        {stats.map(([label, value]) => <Stat key={label} label={label} value={typeof value === 'number' ? value.toLocaleString() : value} />)}
      </div>

      {pending.length > 0 && (
        <div className="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-card border border-[#F2DCA8] bg-sun-100 px-4 py-3 text-sm text-[#7A5A05]">
          <span>{pending.length} organization(s) are waiting for verification.</span>
          <Link to="/admin/organizations?status=pending" className="btn btn-accent">Review registrations</Link>
        </div>
      )}

      <div className="mt-6 grid gap-4 lg:grid-cols-2">
        <BarChart title="Organizations per plan" series={byPlan} />
        <BarChart title="Subscription status" color="#E0A21A" series={byStatus} />
        <BarChart title="Revenue per plan (₱)" color="#12664A" series={revenueByPlan} />
        <BarChart title="Youth records per organization" color="#265280"
          series={Object.fromEntries(orgs.map((o) => [o.barangay, db.youth.filter((y) => y.orgId === o.id).length]))} />
      </div>

      <Section title="Recent activity across organizations">
        <div className="mt-2">
          {db.activity.slice(0, 8).map((log) => (
            <div key={log.id} className="mt-3 border-b border-[#EEF1F4] pb-2 last:border-0">
              <p className="text-sm text-navy-900">{log.description}</p>
              <p className="text-xs text-[#8391A4]">{log.user} · {log.at}</p>
            </div>
          ))}
        </div>
      </Section>
    </>
  );
}

export function Organizations() {
  const { orgs, users, approveOrg } = useStore();
  const [query, setQuery] = useState('');
  const [searchParams] = useSearchParams();
  const [status, setStatus] = useState(searchParams.get('status') || '');

  // Keep the filter in step when the dashboard links here with ?status=pending.
  useEffect(() => { setStatus(searchParams.get('status') || ''); }, [searchParams]);

  const rows = orgs
    .filter((o) => (status ? o.status === status : true))
    .filter((o) => [o.barangay, o.municipality, o.chairperson].join(' ').toLowerCase().includes(query.toLowerCase()));

  return (
    <>
      <PageHeader title="SK organizations"
        subtitle={`${rows.length} organization(s) · ${orgs.filter((o) => o.status === 'pending').length} awaiting verification`}
        actions={<Link to="/admin/organizations/new" className="btn btn-primary">Add organization</Link>} />

      <div className="card mb-4 grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
        <div className="sm:col-span-2">
          <Field label="Search"><input className="field" value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Barangay, municipality, or chairperson" /></Field>
        </div>
        <Field label="Status">
          <select className="field" value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="">All</option>
            {['pending', 'active', 'suspended', 'rejected'].map((s) => <option key={s} value={s}>{s[0].toUpperCase() + s.slice(1)}</option>)}
          </select>
        </Field>
        <div className="flex items-end"><button type="button" className="btn btn-ghost w-full" onClick={() => { setQuery(''); setStatus(''); }}>Reset filters</button></div>
      </div>

      <Table head={['Organization', 'Chairperson', 'Plan', 'Subscription', 'Users', 'Status', '']} minWidth={860}>
        {rows.map((org) => (
          <tr key={org.id} className="hover:bg-shell">
            <td className="td">
              <span className="font-medium text-navy-900">{org.name}</span>
              <span className="block text-xs text-[#8391A4]">{org.email}</span>
            </td>
            <td className="td">{org.chairperson}</td>
            <td className="td">{planByCode(org.plan).name}</td>
            <td className="td">
              <Badge value={subLabel(org)} />
              <span className="block text-xs text-[#8391A4]">{org.expiresIn === null ? 'No expiration' : `${org.expiresIn} day(s)`}</span>
            </td>
            <td className="td">{users.filter((u) => u.orgId === org.id).length}</td>
            <td className="td"><Badge value={org.status} /></td>
            <td className="td">
              <div className="flex gap-2">
                <Link to={`/admin/organizations/${org.id}`} className="btn btn-ghost btn-sm">Open</Link>
                {org.status === 'pending' && (
                  <Confirm className="btn btn-accent btn-sm" message="Approve this organization and start its 7-day Premium trial?"
                    onConfirm={() => approveOrg(org.id)}>Approve</Confirm>
                )}
              </div>
            </td>
          </tr>
        ))}
      </Table>
    </>
  );
}

export function OrganizationDetail() {
  const { id } = useParams();
  const { orgs, users, db, approveOrg, setOrgStatus } = useStore();
  const org = orgs.find((o) => o.id === Number(id));
  const [status, setStatus] = useState(org?.status || 'active');
  const [note, setNote] = useState(org?.statusNote || '');

  if (!org) return <Empty title="Organization not found" body="That organization is not on this deployment." />;

  const team = users.filter((u) => u.orgId === org.id);
  const payments = db.payments.filter((p) => p.orgId === org.id);
  const activity = db.activity.filter((a) => a.orgId === org.id);

  return (
    <>
      <PageHeader title={org.name} subtitle={`${org.province} · ${planByCode(org.plan).name} plan`}
        actions={<>
          {org.status === 'pending' && (
            <Confirm className="btn btn-accent" message="Approve this organization and start its 7-day Premium trial?"
              onConfirm={() => approveOrg(org.id)}>Approve organization</Confirm>
          )}
          <Link to="/admin/organizations" className="btn btn-ghost">Back</Link>
        </>} />

      <div className="grid gap-4 lg:grid-cols-3">
        <div className="space-y-4 lg:col-span-2">
          <Section title="Organization details" action={<Badge value={org.status} />}>
            <dl className="mt-4 grid gap-3 sm:grid-cols-2">
              {[['Barangay', org.barangay], ['Municipality', org.municipality], ['Chairperson', org.chairperson],
                ['Official email', org.email], ['Contact number', org.contact],
                ['Youth records', db.youth.filter((y) => y.orgId === org.id).length.toLocaleString()]].map(([label, value]) => (
                <div key={label}><dt className="text-xs text-[#7A889B]">{label}</dt><dd className="text-sm text-navy-900">{value || '—'}</dd></div>
              ))}
            </dl>
            {org.statusNote && <p className="mt-4 rounded-lg bg-shell px-3 py-2 text-sm text-[#5A6C82]">Note: {org.statusNote}</p>}
          </Section>

          <Section title="Users">
            <div className="mt-3 divide-y divide-line">
              {team.map((user) => (
                <div key={user.id} className="flex items-center justify-between py-2">
                  <div>
                    <p className="text-sm font-medium text-navy-900">{user.name} {user.owner && <span className="badge bg-[#EEF1F5] text-[#4A5B70]">Owner</span>}</p>
                    <p className="text-xs text-[#8391A4]">{user.email} · last login {user.lastLogin}</p>
                  </div>
                  <Badge value={user.active ? 'active' : 'suspended'} />
                </div>
              ))}
            </div>
          </Section>

          <Section title="Activity">
            {activity.length === 0 ? <p className="mt-3 text-sm text-[#7A889B]">No activity recorded.</p> : activity.map((log) => (
              <div key={log.id} className="mt-3 border-b border-[#EEF1F4] pb-2 last:border-0">
                <p className="text-sm text-navy-900">{log.description}</p>
                <p className="text-xs text-[#8391A4]">{log.user} · {log.at}</p>
              </div>
            ))}
          </Section>
        </div>

        <aside className="space-y-4">
          <form className="card space-y-4 p-5" onSubmit={(e) => { e.preventDefault(); setOrgStatus(org.id, status, note); }}>
            <h2 className="text-sm font-bold text-navy-900">Change account status</h2>
            <Field label="Status">
              <select className="field" value={status} onChange={(e) => setStatus(e.target.value)}>
                {['active', 'suspended', 'rejected'].map((s) => <option key={s} value={s}>{s[0].toUpperCase() + s.slice(1)}</option>)}
              </select>
            </Field>
            <Field label="Note (optional)"><textarea className="field" rows="3" value={note} onChange={(e) => setNote(e.target.value)} /></Field>
            <button type="submit" className="btn btn-primary w-full">Update status</button>
          </form>

          <Section title="Payments">
            {payments.length === 0 ? <p className="mt-3 text-sm text-[#7A889B]">No payments recorded.</p> : payments.map((p) => (
              <div key={p.id} className="mt-3 border-b border-[#EEF1F4] pb-2 text-sm last:border-0">
                <div className="flex justify-between"><span className="font-medium text-navy-900">₱{p.amount.toLocaleString()}</span><Badge value={p.status} /></div>
                <p className="text-xs text-[#8391A4]">{p.reference}</p>
              </div>
            ))}
          </Section>
        </aside>
      </div>
    </>
  );
}

export function OrganizationForm() {
  const navigate = useNavigate();
  const { notify } = useStore();
  return (
    <>
      <PageHeader title="Add an SK organization" />
      <form className="card space-y-5 p-5" onSubmit={(e) => {
        e.preventDefault();
        notify('In the full build this creates the organization and starts its 7-day Premium trial.');
        navigate('/admin/organizations');
      }}>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Barangay"><input className="field" required /></Field>
          <Field label="Municipality">
            <input className="field" list="admin-municipalities" required />
            <datalist id="admin-municipalities">{MUNICIPALITIES.map((m) => <option key={m} value={m} />)}</datalist>
          </Field>
          <Field label="Province"><input className="field" defaultValue="Laguna" required /></Field>
          <Field label="SK chairperson"><input className="field" required /></Field>
          <Field label="Official email"><input className="field" type="email" required /></Field>
          <Field label="Contact number"><input className="field" required /></Field>
        </div>
        <fieldset className="border-t border-line pt-5">
          <legend className="text-sm font-bold text-navy-900">Owner account</legend>
          <p className="mt-1 text-xs text-[#7A889B]">The organization is created as active and its 7-day Premium trial starts immediately.</p>
          <div className="mt-4 grid gap-4 sm:grid-cols-3">
            <Field label="Full name"><input className="field" required /></Field>
            <Field label="Email"><input className="field" type="email" required /></Field>
            <Field label="Password"><input className="field" type="password" required /></Field>
          </div>
        </fieldset>
        <div className="flex gap-3">
          <button type="submit" className="btn btn-primary">Create organization</button>
          <button type="button" className="btn btn-ghost" onClick={() => navigate('/admin/organizations')}>Cancel</button>
        </div>
      </form>
    </>
  );
}

export function Subscriptions() {
  const { orgs, plans, setOrgPlan, extendOrg, cancelOrgSub, runCycle } = useStore();
  const [status, setStatus] = useState('');
  const [drafts, setDrafts] = useState({});

  const rows = orgs.filter((o) => {
    if (!status) return true;
    if (status === 'expiring') return ['active', 'trial'].includes(o.subStatus) && o.expiresIn !== null && o.expiresIn <= 3 && o.expiresIn >= 0;
    return o.subStatus === status;
  });

  const draft = (id) => drafts[id] || { plan: orgs.find((o) => o.id === id).plan, cycle: orgs.find((o) => o.id === id).cycle === 'yearly' ? 'yearly' : 'monthly', days: 30 };
  const setDraft = (id, patch) => setDrafts((d) => ({ ...d, [id]: { ...draft(id), ...patch } }));

  return (
    <>
      <PageHeader title="Subscriptions" subtitle={`${rows.length} current subscription(s)`}
        actions={<Confirm message="Run the subscription cycle now?" onConfirm={runCycle}>Run subscription cycle</Confirm>} />

      <div className="card mb-4 grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
        <Field label="Status">
          <select className="field" value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="">All</option>
            {['free', 'trial', 'active', 'expiring', 'expired', 'payment_failed', 'cancelled'].map((s) => (
              <option key={s} value={s}>{SUBSCRIPTION_STATUSES[s] || 'Expiring soon'}</option>
            ))}
          </select>
        </Field>
        <div className="flex items-end"><button type="button" className="btn btn-ghost w-full" onClick={() => setStatus('')}>Reset filters</button></div>
      </div>

      <Table head={['Organization', 'Plan', 'Cycle', 'Started', 'Remaining', 'Status', 'Actions']} minWidth={1000}>
        {rows.map((org) => (
          <tr key={org.id} className="hover:bg-shell">
            <td className="td"><Link to={`/admin/organizations/${org.id}`} className="font-medium text-navy-900 underline">{org.name}</Link></td>
            <td className="td">{planByCode(org.plan).name}</td>
            <td className="td">{org.cycle}</td>
            <td className="td">{org.startedOn || '—'}</td>
            <td className="td">{org.expiresIn === null ? 'No expiration' : `${org.expiresIn} day(s)`}</td>
            <td className="td"><Badge value={SUBSCRIPTION_STATUSES[org.subStatus] || subLabel(org)} /></td>
            <td className="td">
              <div className="flex flex-wrap items-center gap-2">
                <select className="field !w-28 !py-1 !text-xs" value={draft(org.id).plan} onChange={(e) => setDraft(org.id, { plan: e.target.value })}>
                  {plans.map((p) => <option key={p.code} value={p.code}>{p.name}</option>)}
                </select>
                <select className="field !w-24 !py-1 !text-xs" value={draft(org.id).cycle} onChange={(e) => setDraft(org.id, { cycle: e.target.value })}>
                  <option value="monthly">Monthly</option><option value="yearly">Yearly</option>
                </select>
                <button type="button" className="btn btn-ghost btn-sm" onClick={() => setOrgPlan(org.id, draft(org.id).plan, draft(org.id).cycle)}>Set</button>
                <input type="number" min="1" max="365" className="field !w-20 !py-1 !text-xs" value={draft(org.id).days} onChange={(e) => setDraft(org.id, { days: e.target.value })} />
                <button type="button" className="btn btn-ghost btn-sm" onClick={() => extendOrg(org.id, draft(org.id).days)}>Extend</button>
                <Confirm className="btn btn-danger btn-sm" message="Cancel this subscription? Data is kept." onConfirm={() => cancelOrgSub(org.id)}>Cancel</Confirm>
              </div>
            </td>
          </tr>
        ))}
      </Table>
    </>
  );
}

export function Payments() {
  const { db, orgs } = useStore();
  const [status, setStatus] = useState('');
  const [query, setQuery] = useState('');

  const rows = db.payments
    .filter((p) => (status ? p.status === status : true))
    .filter((p) => p.reference.toLowerCase().includes(query.toLowerCase()));

  const totals = {
    paid: db.payments.filter((p) => p.status === 'paid').reduce((s, p) => s + p.amount, 0),
    pending: db.payments.filter((p) => p.status === 'pending').length,
    refunded: db.payments.filter((p) => p.status === 'refunded').reduce((s, p) => s + p.amount, 0),
  };

  return (
    <>
      <PageHeader title="Payments"
        subtitle="Successful payments activate the SK subscription automatically. There is no approval step here." />
      <div className="mb-4 grid grid-cols-3 gap-3">
        <Stat label="Total collected" value={`₱${totals.paid.toLocaleString()}`} />
        <Stat label="Pending payments" value={totals.pending} />
        <Stat label="Refunded" value={`₱${totals.refunded.toLocaleString()}`} />
      </div>

      <div className="card mb-4 grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
        <div className="sm:col-span-2"><Field label="Reference number"><input className="field" value={query} onChange={(e) => setQuery(e.target.value)} /></Field></div>
        <Field label="Status">
          <select className="field" value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="">All</option>
            {['pending', 'paid', 'failed', 'refunded'].map((s) => <option key={s} value={s}>{s[0].toUpperCase() + s.slice(1)}</option>)}
          </select>
        </Field>
        <div className="flex items-end"><button type="button" className="btn btn-ghost w-full" onClick={() => { setQuery(''); setStatus(''); }}>Reset filters</button></div>
      </div>

      <Table head={['Reference', 'Organization', 'Plan', 'Amount', 'Method', 'Payment status', 'Payment date', 'Subscription']} minWidth={940}>
        {rows.length === 0 ? (
          <tr><td className="td text-[#7A889B]" colSpan={8}>No payments recorded.</td></tr>
        ) : rows.map((payment) => (
          <tr key={payment.id} className="hover:bg-shell">
            <td className="td font-mono text-xs">{payment.reference}</td>
            <td className="td">{orgs.find((o) => o.id === payment.orgId)?.name || '—'}</td>
            <td className="td">{planByCode(payment.planCode).name} · {payment.cycle}</td>
            <td className="td">₱{payment.amount.toLocaleString()}</td>
            <td className="td">{String(payment.method || '').replace(/_/g, ' ')}</td>
            <td className="td"><Badge value={payment.status} /></td>
            <td className="td">{payment.paidAt || '—'}</td>
            <td className="td">
              {payment.status === 'paid'
                ? <span className="text-xs text-[#12664A]">Activated automatically</span>
                : payment.status === 'failed'
                  ? <span className="text-xs text-[#96201A]">Not activated — SK can retry</span>
                  : <span className="text-xs text-[#8391A4]">Awaiting gateway result</span>}
            </td>
          </tr>
        ))}
      </Table>
    </>
  );
}

export function Users() {
  const { users, orgs, toggleUser, user: me } = useStore();
  const [query, setQuery] = useState('');
  const [role, setRole] = useState('');
  const [status, setStatus] = useState('');

  const rows = users
    .filter((u) => (role ? u.role === role : true))
    .filter((u) => (status ? (status === 'active' ? u.active : !u.active) : true))
    .filter((u) => `${u.name} ${u.email}`.toLowerCase().includes(query.toLowerCase()));

  return (
    <>
      <PageHeader title="Users" subtitle={`${rows.length} account(s) across all organizations`} />
      <div className="card mb-4 grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
        <div className="sm:col-span-2"><Field label="Search"><input className="field" value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Name or email" /></Field></div>
        <Field label="Role">
          <select className="field" value={role} onChange={(e) => setRole(e.target.value)}>
            <option value="">All</option>
            <option value="system_admin">System Administrator</option>
            <option value="sk_official">SK User</option>
            <option value="youth">Youth</option>
          </select>
        </Field>
        <Field label="Status">
          <select className="field" value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="">All</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
        </Field>
        <div className="flex items-end"><button type="button" className="btn btn-ghost w-full" onClick={() => { setQuery(''); setRole(''); setStatus(''); }}>Reset filters</button></div>
      </div>

      <Table head={['Name', 'Email', 'Organization', 'Role', 'Last login', 'Status', '']} minWidth={820}>
        {rows.map((user) => (
          <tr key={user.id} className="hover:bg-shell">
            <td className="td font-medium text-navy-900">{user.name}</td>
            <td className="td">{user.email}</td>
            <td className="td">{orgs.find((o) => o.id === user.orgId)?.name || '—'}</td>
            <td className="td">
              {user.role === 'system_admin' ? 'System Administrator' : user.role === 'youth' ? 'Youth' : 'SK User'}
            </td>
            <td className="td">{user.lastLogin}</td>
            <td className="td"><Badge value={user.active ? 'active' : 'suspended'} /></td>
            <td className="td">
              {user.id === me.id ? <span className="text-xs text-[#8391A4]">You</span> : (
                <Confirm className="btn btn-ghost btn-sm" message="Change this account's active status?" onConfirm={() => toggleUser(user.id)}>
                  {user.active ? 'Deactivate' : 'Activate'}
                </Confirm>
              )}
            </td>
          </tr>
        ))}
      </Table>
    </>
  );
}

export function Monitoring() {
  const { db, orgs, notify } = useStore();
  const [action, setAction] = useState('');
  const activity = db.activity.filter((log) => (action ? log.action === action : true));

  return (
    <>
      <PageHeader title="System monitoring" subtitle="Activity across organizations, system events, and connection status."
        actions={<Confirm message="Record a backup checkpoint now?" onConfirm={() => notify('Backup checkpoint recorded.')}>Record backup checkpoint</Confirm>} />

      <div className="mb-4 grid gap-3 sm:grid-cols-3">
        <Stat label="Data source" value="Mocked" hint="No database connected in this preview" />
        <Stat label="Organizations tracked" value={orgs.length} />
        <Stat label="System events logged" value={db.systemLogs.length} />
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <Section title="Activity log">
          <select className="field mt-3" value={action} onChange={(e) => setAction(e.target.value)}>
            <option value="">All actions</option>
            {['login', 'create', 'edit', 'report'].map((a) => <option key={a} value={a}>{a[0].toUpperCase() + a.slice(1)}</option>)}
          </select>
          <div className="mt-3 max-h-96 space-y-2 overflow-y-auto">
            {activity.map((log) => (
              <div key={log.id} className="border-b border-[#EEF1F4] pb-2 last:border-0">
                <p className="text-sm text-navy-900">{log.description}</p>
                <p className="text-xs text-[#8391A4]">{log.user} · {log.at} · {log.ip}</p>
              </div>
            ))}
          </div>
        </Section>

        <Section title="System events">
          <div className="mt-3 space-y-2">
            {db.systemLogs.map((log) => (
              <div key={log.id} className="border-b border-[#EEF1F4] pb-2 last:border-0">
                <div className="flex items-start justify-between gap-2">
                  <p className="text-sm text-navy-900">{log.message}</p>
                  <Badge value={log.level === 'info' ? 'active' : log.level === 'warning' ? 'pending' : 'expired'} />
                </div>
                <p className="text-xs text-[#8391A4]">{log.event} · {log.at}</p>
              </div>
            ))}
          </div>
        </Section>
      </div>
    </>
  );
}
