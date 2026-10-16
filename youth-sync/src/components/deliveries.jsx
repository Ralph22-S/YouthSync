import { useEffect, useState } from 'react';
import { api, friendlyApiMessage, totalPagesOf } from '../lib/api.js';
import { Badge, Empty, Pagination } from './ui.jsx';

/**
 * Formats a server DATETIME ("YYYY-MM-DD HH:MM:SS") for display without
 * shifting it through UTC. Unknown formats are shown as stored.
 */
export const formatDateTime = (value) => {
  if (!value) return '';
  const match = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?/.exec(String(value));
  if (!match) return String(value);
  const [, y, m, d, h, mi, s] = match;
  const date = new Date(Number(y), Number(m) - 1, Number(d), Number(h), Number(mi), Number(s || 0));
  if (Number.isNaN(date.getTime())) return String(value);
  return date.toLocaleString(undefined, {
    year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit',
  });
};

/**
 * Display copy for statuses stored in sms_deliveries / email_deliveries.
 * The stored value is always shown; nothing is upgraded (e.g. failed is never shown as sent).
 * "sent" only means the provider accepted the request (HTTP 2xx); handset/inbox delivery is not confirmed.
 */
const STATUS_COPY = {
  pending: { tone: 'warning', hint: 'Recorded, but the provider has not answered yet.' },
  sent: { tone: 'success', hint: 'Accepted by the provider. Final delivery to the recipient is not confirmed by YouthSync.' },
  delivered: { tone: 'success', hint: 'Delivery reported by the provider.' },
  failed: { tone: 'danger', hint: 'Not sent.' },
};

const CHANNEL = {
  sms: {
    path: '/sk/deliveries/sms',
    noun: 'SMS records',
    loading: 'Loading SMS history...',
    empty: 'No SMS delivery records yet.',
    emptyBody: 'When an application event attempts an SMS, the attempt and its stored status appear here.',
    search: 'Recipient number, event, status, or error',
    configured: 'SMS provider (Semaphore) is configured. "Sent" means Semaphore accepted the message; delivery to the phone is not confirmed by YouthSync.',
    notConfigured: 'SMS sending is not configured on this server. New SMS attempts are stored as Failed with the reason "SMS provider is not configured." No SMS is sent.',
  },
  email: {
    path: '/sk/deliveries/email',
    noun: 'email records',
    loading: 'Loading email history...',
    empty: 'No email delivery records yet.',
    emptyBody: 'When an application event attempts an email, the attempt and its stored status appear here.',
    search: 'Recipient email or name, event, status, or error',
    configured: 'Email provider (Brevo) is configured. "Sent" means Brevo accepted the message; inbox delivery is not confirmed by YouthSync.',
    notConfigured: 'Email sending is not configured on this server. New email attempts are skipped and are not recorded, so this list only shows earlier attempts. No email is sent.',
  },
};

const PAGE_SIZE = 20;

/**
 * Read-only SMS or email delivery history for the signed-in SK organization.
 * Rows come from GET /sk/deliveries/{sms|email}; the organization is taken from the session.
 */
export function DeliveryHistory({ channel }) {
  const copy = CHANNEL[channel] || CHANNEL.sms;
  const [search, setSearch] = useState('');
  const [query, setQuery] = useState('');
  const [page, setPage] = useState(1);
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [reloadKey, setReloadKey] = useState(0);

  useEffect(() => {
    const timer = setTimeout(() => {
      setQuery(search.trim());
      setPage(1);
    }, 300);
    return () => clearTimeout(timer);
  }, [search]);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    setError(null);
    api.get(copy.path, { query: { page, perPage: PAGE_SIZE, q: query || undefined } })
      .then((result) => {
        if (cancelled) return;
        // The API returns an empty page past the end; if rows were removed
        // meanwhile, step back to the last real page instead of showing it.
        const last = totalPagesOf(result);
        if (page > last) {
          setPage(last);
          return;
        }
        setData(result || { items: [], total: 0 });
      })
      .catch((err) => {
        if (!cancelled && err?.name !== 'AbortError') {
          setError(friendlyApiMessage(err, 'Unable to load delivery history.'));
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => { cancelled = true; };
  }, [copy.path, page, query, reloadKey]);

  const rows = Array.isArray(data?.items) ? data.items : [];
  const total = Number(data?.total) || 0;

  return (
    <div>
      {data && typeof data.providerConfigured === 'boolean' && (
        <p className={`mb-4 rounded-lg border px-4 py-2 text-sm ${data.providerConfigured
          ? 'border-line bg-white text-[#4A5B70]'
          : 'border-[#F2DCA8] bg-sun-100 text-[#7A5A05]'}`}>
          {data.providerConfigured ? copy.configured : copy.notConfigured}
        </p>
      )}

      <div className="card mb-4 flex flex-col gap-3 p-4 sm:flex-row sm:items-end">
        <div className="flex-1">
          <label className="label" htmlFor={`delivery-search-${channel}`}>Search</label>
          <input id={`delivery-search-${channel}`} className="field" value={search}
            onChange={(e) => setSearch(e.target.value)} placeholder={copy.search} />
        </div>
        <button type="button" className="btn btn-ghost" disabled={loading}
          onClick={() => setReloadKey((k) => k + 1)}>
          {loading ? 'Loading...' : 'Refresh'}
        </button>
      </div>

      {error ? (
        <div className="card border-[#F0D3D1] bg-[#FCF3F2] px-6 py-8 text-center">
          <p className="text-sm font-semibold text-[#96201A]">{error}</p>
          <button type="button" className="btn btn-ghost mt-4" onClick={() => setReloadKey((k) => k + 1)}>Retry</button>
        </div>
      ) : loading && !data ? (
        <Empty title={copy.loading} />
      ) : rows.length === 0 ? (
        query
          ? <Empty title="No records match this search." body="Clear the search to see every delivery record." />
          : <Empty title={copy.empty} body={copy.emptyBody} />
      ) : (
        <>
          {loading && <p className="mb-2 text-sm text-ink-muted">{copy.loading}</p>}
          <div className="card divide-y divide-line">
            {rows.map((row) => {
              const status = String(row.status || '').toLowerCase();
              const meta = STATUS_COPY[status];
              const recipient = channel === 'sms'
                ? (row.recipient || row.mobile)
                : (row.name && row.name !== row.email ? `${row.name} · ${row.email}` : row.email);
              return (
                <article key={row.id} className="p-4">
                  <div className="flex flex-wrap items-start justify-between gap-2">
                    <div className="min-w-0">
                      <p className="text-sm font-semibold text-navy-900">{recipient || 'Recipient not recorded'}</p>
                      <p className="mt-0.5 text-xs text-[#8391A4]">
                        Event: {row.eventCode || 'not recorded'}{row.eventId ? ` #${row.eventId}` : ''}
                        {row.notificationId ? ` · Notification #${row.notificationId}` : ''}
                        {row.provider ? ` · Provider: ${row.provider}` : ''}
                      </p>
                    </div>
                    <span title={meta?.hint || 'Status as stored on the server.'}>
                      <Badge value={row.status || 'unknown'} tone={meta?.tone || 'neutral'} />
                    </span>
                  </div>
                  <dl className="mt-2 grid gap-x-6 gap-y-1 text-xs text-[#5A6C82] sm:grid-cols-2">
                    <div><dt className="inline font-medium">Recorded: </dt><dd className="inline">{formatDateTime(row.createdAt) || 'not recorded'}</dd></div>
                    <div><dt className="inline font-medium">Sent: </dt><dd className="inline">{row.sentAt ? formatDateTime(row.sentAt) : 'Not sent'}</dd></div>
                    {row.providerReference && (
                      <div className="sm:col-span-2"><dt className="inline font-medium">Provider reference: </dt><dd className="inline break-all">{row.providerReference}</dd></div>
                    )}
                  </dl>
                  {row.errorMessage && (
                    <p className="mt-2 text-sm text-[#96201A]">Reason: {row.errorMessage}</p>
                  )}
                  {meta?.hint && status === 'sent' && (
                    <p className="mt-1 text-xs text-[#8391A4]">{meta.hint}</p>
                  )}
                </article>
              );
            })}
          </div>
          <Pagination page={page} pageSize={PAGE_SIZE} total={total} totalPages={totalPagesOf(data)}
            onPage={setPage} loading={loading} noun={copy.noun} />
        </>
      )}
    </div>
  );
}
