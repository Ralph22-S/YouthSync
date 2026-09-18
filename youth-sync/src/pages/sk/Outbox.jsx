import { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useStore } from '../../store.jsx';
import { PageHeader } from '../../components/layouts.jsx';
import { Badge, Confirm, Empty, Field, Stat, Tabs } from '../../components/ui.jsx';
import { FilterBar, SORT_NEWEST, sortRows } from '../../components/filters.jsx';

/**
 * Simulated SMS and email. Records are created automatically by the selection,
 * approval and attendance flows — nothing here sends anything for real.
 */
export default function Outbox() {
  const { scoped, clearOutbox } = useStore();
  // The sidebar links straight to a tab, so the URL drives it.
  const [params, setParams] = useSearchParams();
  const tab = params.get('tab') === 'email' ? 'email' : 'sms';
  const setTab = (value) => setParams({ tab: value });
  const [query, setQuery] = useState('');
  const [sort, setSort] = useState('newest');

  const sms = scoped('sms');
  const emails = scoped('emails');
  const matching = (tab === 'sms' ? sms : emails).filter((row) => {
    if (!query) return true;
    const haystack = tab === 'sms'
      ? `${row.name} ${row.mobile} ${row.text} ${row.related ?? ''}`
      : `${row.name} ${row.email} ${row.subject} ${row.body} ${row.related ?? ''}`;
    return haystack.toLowerCase().includes(query.toLowerCase());
  });
  const rows = sortRows(matching, sort, (r) => r.name || '');

  return (
    <>
      <PageHeader title="Outbox"
        subtitle="Simulated messages created automatically when youth are selected, approved, or checked in."
        actions={rows.length > 0 && (
          <Confirm className="btn btn-ghost"
            message={`Clear the ${tab === 'sms' ? 'SMS' : 'email'} outbox?`}
            onConfirm={() => clearOutbox(tab === 'sms' ? 'sms' : 'emails')}>Clear outbox</Confirm>
        )} />

      <div className="mb-4 grid grid-cols-3 gap-3">
        <Stat label="SMS messages" value={sms.length} />
        <Stat label="Email messages" value={emails.length} />
        <Stat label="Delivery" value="Simulated" hint="No gateway is connected" />
      </div>

      <Tabs active={tab} onSelect={setTab} items={[
        { key: 'sms', label: 'SMS outbox', count: sms.length },
        { key: 'email', label: 'Email outbox', count: emails.length },
      ]} />

      <FilterBar search={query} onSearch={setQuery}
        placeholder={tab === 'sms' ? 'Recipient, mobile, or message' : 'Recipient, email, or subject'}
        sort={{ value: sort, onChange: setSort, options: SORT_NEWEST }}
        onReset={() => { setQuery(''); setSort('newest'); }} />

      {rows.length === 0 ? (
        <Empty title="Nothing here yet"
          body="Confirm a youth selection, approve an application, or scan a QR code and the message appears at the top of this list." />
      ) : (
        <div className="space-y-3">
          {rows.map((row) => (
            <article key={row.id} className="card p-4">
              <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0">
                  <p className="text-sm font-semibold text-navy-900">
                    {row.name} <span className="font-normal text-[#7A889B]">· {tab === 'sms' ? row.mobile : row.email}</span>
                  </p>
                  <p className="text-xs text-[#8391A4]">
                    {row.type}{row.related ? ` · ${row.related}` : ''} · {row.sentAt}
                  </p>
                </div>
                <Badge value={row.status} />
              </div>

              {tab === 'email' && <p className="mt-2 text-sm font-medium text-navy-900">{row.subject}</p>}
              <p className="mt-1 text-sm text-[#4A5B70]">{tab === 'sms' ? row.text : row.body}</p>
              {tab === 'sms' && (
                <p className="mt-1 text-xs text-[#8391A4]">
                  {row.characters} characters · {Math.ceil(row.characters / 160)} segment(s)
                </p>
              )}
            </article>
          ))}
        </div>
      )}
    </>
  );
}
