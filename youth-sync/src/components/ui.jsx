import { useEffect, useState } from 'react';
import { useStore } from '../store.jsx';

const TONE = {
  success: 'bg-success-bg text-success',
  warning: 'bg-warning-bg text-warning',
  danger: 'bg-danger-bg text-danger',
  info: 'bg-info-bg text-info',
  neutral: 'bg-shell text-ink-muted',
};

/** One status vocabulary for the whole product. */
const STATUS_TONE = {
  active: 'success', approved: 'success', verified: 'success', paid: 'success', present: 'success',
  published: 'success', released: 'success', completed: 'success', open: 'success',
  pending: 'warning', trial: 'warning', waitlisted: 'warning', medium: 'warning', full: 'warning',
  'expiring soon': 'warning', 'needs resubmission': 'warning', needs_resubmission: 'warning',
  'resubmission requested': 'warning', 'not yet attended': 'warning', submitted: 'warning',
  rejected: 'danger', expired: 'danger', suspended: 'danger', failed: 'danger', absent: 'danger',
  high: 'danger', missing: 'danger',
  registered: 'info', applied: 'info', ongoing: 'info', 'under review': 'info', excused: 'info',
  free: 'neutral', draft: 'neutral', cancelled: 'neutral', archived: 'neutral', closed: 'neutral',
  'not registered': 'neutral', inactive: 'neutral', refunded: 'neutral', low: 'neutral',
};

const titleCase = (value) => String(value).replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase());

export function Badge({ value, tone }) {
  const key = String(value).toLowerCase();
  const resolved = tone || STATUS_TONE[key] || 'neutral';
  return <span className={`badge ${TONE[resolved]}`}>{titleCase(value)}</span>;
}

export function Stat({ label, value, hint }) {
  return (
    <div className="card px-4 py-3.5">
      <p className="text-xs font-medium text-ink-muted">{label}</p>
      <p className="mt-1.5 text-[26px] font-semibold leading-none tracking-tight text-navy-900">{value}</p>
      {hint && <p className="mt-1.5 t-help">{hint}</p>}
    </div>
  );
}

export function BarChart({ title, series, color = '#22568C', empty = 'No data yet.' }) {
  const entries = Object.entries(series || {}).filter(([, v]) => v !== undefined);
  const max = Math.max(1, ...entries.map(([, v]) => v));
  return (
    <div className="card p-4">
      <h3 className="text-sm font-semibold text-navy-800">{title}</h3>
      {entries.length === 0 ? (
        <p className="mt-3 text-sm text-[#7A889B]">{empty}</p>
      ) : (
        <div className="mt-3 space-y-2">
          {entries.map(([label, value]) => (
            <div key={label}>
              <div className="flex items-baseline justify-between text-xs text-[#5A6C82]">
                <span>{label}</span><span className="font-semibold text-navy-900">{value.toLocaleString()}</span>
              </div>
              <div className="mt-1 h-2 w-full rounded-full bg-[#EDF0F4]">
                <div className="h-2 rounded-full" style={{ width: `${Math.max(3, Math.round((value / max) * 100))}%`, background: color }} />
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

export function Empty({ title = 'No records found.', body, children }) {
  return (
    <div className="card px-6 py-12 text-center">
      <p className="text-sm font-semibold text-ink">{title}</p>
      {body && <p className="mx-auto mt-1.5 max-w-md t-muted">{body}</p>}
      {children && <div className="mt-5 flex justify-center gap-2">{children}</div>}
    </div>
  );
}

/** Shown while a list is being prepared, instead of a blank screen. */
export function Skeleton({ rows = 5 }) {
  return (
    <div className="card divide-y divide-line" aria-busy="true" aria-live="polite">
      {Array.from({ length: rows }).map((_, i) => (
        <div key={i} className="flex items-center gap-4 p-4">
          <div className="h-9 w-9 shrink-0 animate-pulse rounded-full bg-shell" />
          <div className="flex-1 space-y-2">
            <div className="h-3 w-1/3 animate-pulse rounded bg-shell" />
            <div className="h-3 w-1/5 animate-pulse rounded bg-shell" />
          </div>
        </div>
      ))}
    </div>
  );
}

/** Result count plus previous/next, for lists that grow past one screen. */
export function Pagination({ page, pageSize, total, onPage, noun = 'records' }) {
  const pages = Math.max(1, Math.ceil(total / pageSize));
  if (total === 0) return null;
  const from = (page - 1) * pageSize + 1;
  const to = Math.min(total, page * pageSize);

  return (
    <div className="mt-4 flex flex-col items-center justify-between gap-3 sm:flex-row">
      <p className="t-help">Showing {from}–{to} of {total.toLocaleString()} {noun}</p>
      {pages > 1 && (
        <div className="flex items-center gap-1">
          <button type="button" className="btn btn-secondary btn-sm" disabled={page === 1} onClick={() => onPage(page - 1)}>Previous</button>
          <span className="px-2 t-help">Page {page} of {pages}</span>
          <button type="button" className="btn btn-secondary btn-sm" disabled={page === pages} onClick={() => onPage(page + 1)}>Next</button>
        </div>
      )}
    </div>
  );
}

export function Field({ label, children, hint, error }) {
  return (
    <div>
      <label className="label">{label}</label>
      {children}
      {error
        ? <p className="mt-1 text-xs font-medium text-[#96201A]">{error}</p>
        : hint && <p className="mt-1 text-xs text-[#8391A4]">{hint}</p>}
    </div>
  );
}

/** Summary shown at the top of a form when validation fails. */
export function FormErrors({ errors }) {
  const list = Object.values(errors || {}).filter(Boolean);
  if (!list.length) return null;

  return (
    <div className="rounded-card border border-[#F0D3D1] bg-[#FCF3F2] px-4 py-3 text-sm text-[#96201A]">
      <p className="font-semibold">Please fix the following:</p>
      <ul className="mt-1 list-disc space-y-0.5 pl-5">
        {list.map((message) => <li key={message}>{message}</li>)}
      </ul>
    </div>
  );
}

export function Section({ title, action, children }) {
  return (
    <section className="card p-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-sm font-bold text-navy-900">{title}</h2>
        {action}
      </div>
      {children}
    </section>
  );
}

export function Tabs({ items, active, onSelect }) {
  return (
    <div className="mb-4 flex flex-wrap gap-2">
      {items.map((item) => (
        <button key={item.key} type="button" onClick={() => onSelect(item.key)}
          className={`rounded-lg border px-3 py-1.5 text-sm font-medium ${
            active === item.key ? 'border-navy-900 bg-navy-900 text-white' : 'border-line bg-white text-[#4A5B70] hover:bg-shell'
          }`}>
          {item.label}{item.count !== undefined && <span className="opacity-70"> ({item.count})</span>}
        </button>
      ))}
    </div>
  );
}

export function Flash() {
  const { flash, clearFlash } = useStore();
  useEffect(() => {
    if (!flash) return undefined;
    const timer = setTimeout(clearFlash, 6000);
    return () => clearTimeout(timer);
  }, [flash, clearFlash]);

  if (!flash) return null;
  const styles = flash.tone === 'warning'
    ? 'border-[#F2DCA8] bg-sun-100 text-[#7A5A05]'
    : flash.tone === 'error'
      ? 'border-[#F0D3D1] bg-[#FCF3F2] text-[#96201A]'
      : 'border-[#BFE3D0] bg-[#F1FAF5] text-[#12664A]';

  const icon = flash.tone === 'warning' ? '!' : flash.tone === 'error' ? '×' : '✓';

  // Fixed to the bottom on a phone, top-right on a desktop, so a save is never silent.
  return (
    <div aria-live="polite"
      className="pointer-events-none fixed inset-x-3 bottom-3 z-[60] flex justify-center sm:inset-x-auto sm:bottom-auto sm:right-5 sm:top-20 sm:justify-end">
      <div className={`pointer-events-auto flex w-full max-w-md items-start gap-3 rounded-card border px-4 py-3 text-sm shadow-lg ${styles}`}>
        <span className="mt-0.5 grid h-5 w-5 shrink-0 place-items-center rounded-full bg-white/70 text-xs font-bold">{icon}</span>
        <span className="flex-1">{flash.message}</span>
        <button type="button" onClick={clearFlash} aria-label="Dismiss" className="font-bold opacity-60">×</button>
      </div>
    </div>
  );
}

export function Table({ head, children, minWidth = 720 }) {
  return (
    <div className="card overflow-x-auto">
      <table className="w-full" style={{ minWidth }}>
        <thead><tr>{head.map((h) => <th key={h} className="th">{h}</th>)}</tr></thead>
        <tbody>{children}</tbody>
      </table>
    </div>
  );
}

export function Confirm({ message, onConfirm, children, className = 'btn btn-ghost' }) {
  return (
    <button type="button" className={className}
      onClick={() => { if (window.confirm(message)) onConfirm(); }}>
      {children}
    </button>
  );
}


/**
 * Centred dialog used for every confirm / reject / resubmission step.
 * Escape and the backdrop both close it, and it is sized for a phone first.
 */
export function Modal({ open, title, description, onClose, children, footer }) {
  useEffect(() => {
    if (!open) return undefined;
    const onKey = (e) => { if (e.key === 'Escape') onClose(); };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [open, onClose]);

  if (!open) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center bg-navy-900/40 p-0 sm:items-center sm:p-4"
      role="dialog" aria-modal="true" onMouseDown={(e) => { if (e.target === e.currentTarget) onClose(); }}>
      <div className="max-h-[92vh] w-full overflow-y-auto rounded-t-2xl border border-line bg-white p-5 shadow-xl sm:max-w-lg sm:rounded-card">
        <div className="flex items-start justify-between gap-3">
          <div>
            <h2 className="text-base font-bold text-navy-900">{title}</h2>
            {description && <p className="mt-1 text-sm text-[#5A6C82]">{description}</p>}
          </div>
          <button type="button" onClick={onClose} aria-label="Close"
            className="grid h-8 w-8 shrink-0 place-items-center rounded-lg text-[#5A6C82] hover:bg-shell">×</button>
        </div>
        <div className="mt-4">{children}</div>
        {footer && <div className="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">{footer}</div>}
      </div>
    </div>
  );
}

/** Button that opens a confirmation dialog before running the action. */
export function ConfirmButton({ label, title, description, confirmLabel = 'Confirm', onConfirm, className = 'btn btn-ghost', tone = 'btn btn-primary' }) {
  const [open, setOpen] = useState(false);

  return (
    <>
      <button type="button" className={className} onClick={() => setOpen(true)}>{label}</button>
      <Modal open={open} title={title || label} description={description} onClose={() => setOpen(false)}
        footer={<>
          <button type="button" className="btn btn-ghost" onClick={() => setOpen(false)}>Cancel</button>
          <button type="button" className={tone} onClick={() => { setOpen(false); onConfirm(); }}>{confirmLabel}</button>
        </>}>
        <p className="text-sm text-[#4A5B70]">This action is recorded in the activity log.</p>
      </Modal>
    </>
  );
}
