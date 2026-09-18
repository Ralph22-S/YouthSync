import { useMemo, useState } from 'react';
import { useStore } from '../../store.jsx';
import { PageHeader } from '../../components/layouts.jsx';
import { Badge, Confirm, Empty, Field, FormErrors, Section, Stat, Table } from '../../components/ui.jsx';
import { validateUser, digitsOnly, hasErrors, CONTACT_LENGTH } from '../../lib/validation.js';
import { FilterBar, SORT_NEWEST } from '../../components/filters.jsx';

const ROLES = { sk_official: 'SK official', youth: 'Youth' };
const EMPTY = { name: '', email: '', mobile: '', role: 'sk_official', password: '' };

export default function Users() {
  const { users, org, usage, limits, saveUser, toggleUserActive, deleteUser, user: me } = useStore();
  const [query, setQuery] = useState('');
  const [role, setRole] = useState('');
  const [status, setStatus] = useState('');
  const [sort, setSort] = useState('newest');
  const [values, setValues] = useState(EMPTY);
  const [errors, setErrors] = useState({});
  const [editing, setEditing] = useState(null);

  const team = useMemo(() => users
    .filter((u) => u.orgId === org?.id)
    // Newest first: accounts created in this session carry a createdAt stamp.
    .sort((a, b) => (b.createdAt || '').localeCompare(a.createdAt || '') || b.id - a.id), [users, org]);

  const ordered = (rows) => {
    if (sort === 'name') return [...rows].sort((a, b) => a.name.localeCompare(b.name));
    if (sort === 'oldest') return [...rows].reverse();
    return rows;
  };

  const rows = ordered(team
    .filter((u) => (role ? u.role === role : true))
    .filter((u) => (status ? (status === 'active' ? u.active : !u.active) : true))
    .filter((u) => `${u.name} ${u.email}`.toLowerCase().includes(query.toLowerCase())));

  const set = (key) => (event) => {
    const value = key === 'mobile' ? digitsOnly(event.target.value) : event.target.value;
    setValues((v) => ({ ...v, [key]: value }));
    setErrors((e) => (e[key] ? { ...e, [key]: undefined } : e));
  };

  const startEdit = (account) => {
    setEditing(account.id);
    setValues({ name: account.name, email: account.email, mobile: account.mobile || '', role: account.role, password: '' });
    setErrors({});
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };

  const reset = () => { setEditing(null); setValues(EMPTY); setErrors({}); };

  const submit = async (event) => {
    event.preventDefault();

    const taken = users
      .filter((u) => u.id !== editing)
      .map((u) => u.email.toLowerCase());

    const found = validateUser(values, { existingEmails: taken, requirePassword: !editing });
    if (hasErrors(found)) { setErrors(found); return; }

    const saved = await saveUser(editing ? { ...values, id: editing } : values);
    if (saved) reset();
  };

  return (
    <>
      <PageHeader title="User management"
        subtitle={`${team.length} account(s) in ${org?.name ?? 'this organization'}`} />

      <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Stat label="Total accounts" value={team.length} />
        <Stat label="Active" value={team.filter((u) => u.active).length} />
        <Stat label="Deactivated" value={team.filter((u) => !u.active).length} />
        <Stat label="Staff slots used" value={`${usage.accounts} / ${limits.accounts ?? '∞'}`} />
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        <form onSubmit={submit} className="card h-fit space-y-4 p-5" noValidate>
          <h2 className="text-sm font-bold text-navy-900">{editing ? 'Edit user' : 'Add user'}</h2>
          <FormErrors errors={errors} />

          <Field label="Full name *" error={errors.name}>
            <input className="field" value={values.name} onChange={set('name')} />
          </Field>
          <Field label="Email *" error={errors.email}>
            <input className="field" type="email" value={values.email} onChange={set('email')} />
          </Field>
          <Field label="Mobile number" error={errors.mobile} hint={`${CONTACT_LENGTH} digits if provided.`}>
            <input className="field" type="tel" inputMode="numeric" maxLength={CONTACT_LENGTH}
              placeholder="09171234567" value={values.mobile} onChange={set('mobile')} />
          </Field>
          <Field label="Role">
            <select className="field" value={values.role} onChange={set('role')}>
              {Object.entries(ROLES).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
            </select>
          </Field>
          <Field label={editing ? 'New password (leave blank to keep)' : 'Password *'} error={errors.password}>
            <input className="field" type="password" value={values.password} onChange={set('password')} />
          </Field>

          <div className="flex gap-2">
            <button type="submit" className="btn btn-primary flex-1">{editing ? 'Save changes' : 'Add user'}</button>
            {editing && <button type="button" className="btn btn-ghost" onClick={reset}>Cancel</button>}
          </div>
        </form>

        <div className="lg:col-span-2">
          <FilterBar search={query} onSearch={setQuery} placeholder="Name or email"
            sort={{ value: sort, onChange: setSort, options: SORT_NEWEST }}
            onReset={() => { setQuery(''); setRole(''); setStatus(''); setSort('newest'); }}>
            <Field label="Role">
              <select className="field" value={role} onChange={(e) => setRole(e.target.value)}>
                <option value="">All roles</option>
                {Object.entries(ROLES).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
              </select>
            </Field>
            <Field label="Status">
              <select className="field" value={status} onChange={(e) => setStatus(e.target.value)}>
                <option value="">All statuses</option>
                <option value="active">Active</option>
                <option value="inactive">Deactivated</option>
              </select>
            </Field>
          </FilterBar>

          {rows.length === 0 ? (
            <Empty title="No accounts match this filter" body="Clear the search or add a new user from the form." />
          ) : (
            <Table head={['Name', 'Role', 'Mobile', 'Status', 'Actions']} minWidth={620}>
              {rows.map((account) => (
                <tr key={account.id} className="hover:bg-shell">
                  <td className="td">
                    <span className="font-medium text-navy-900">
                      {account.name} {account.owner && <span className="badge bg-[#EEF1F5] text-[#4A5B70]">Owner</span>}
                    </span>
                    <span className="block text-xs text-[#8391A4]">{account.email}</span>
                  </td>
                  <td className="td">{ROLES[account.role] ?? account.role}</td>
                  <td className="td">{account.mobile || '—'}</td>
                  <td className="td"><Badge value={account.active ? 'active' : 'suspended'} /></td>
                  <td className="td">
                    <div className="flex flex-wrap gap-1.5">
                      <button type="button" className="btn btn-ghost btn-sm" onClick={() => startEdit(account)}>Edit</button>
                      {account.id !== me.id && (
                        <Confirm className="btn btn-ghost btn-sm"
                          message={`${account.active ? 'Deactivate' : 'Activate'} ${account.name}?`}
                          onConfirm={() => toggleUserActive(account.id)}>
                          {account.active ? 'Deactivate' : 'Activate'}
                        </Confirm>
                      )}
                      {!account.owner && account.id !== me.id && (
                        <Confirm className="btn btn-danger btn-sm"
                          message={`Delete ${account.name}? This cannot be undone.`}
                          onConfirm={() => deleteUser(account.id)}>Delete</Confirm>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </Table>
          )}
        </div>
      </div>
    </>
  );
}
