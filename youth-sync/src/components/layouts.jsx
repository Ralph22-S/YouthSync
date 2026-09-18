import { useEffect, useState } from 'react';
import { Link, NavLink, Outlet, useLocation, useNavigate, Navigate } from 'react-router-dom';
import {
  LayoutDashboard, Users, ClipboardList, CalendarDays, QrCode, HandHeart, Bell,
  FileBarChart, Building2, ScrollText, Settings, CreditCard, Wallet, UserCog,
  UserCircle, LogOut, Menu, X, ChevronRight,
} from 'lucide-react';
import { useStore } from '../store.jsx';
import { Flash } from './ui.jsx';

const initials = (name = '') => name.split(/\s+/).filter(Boolean)
  .map((part, i, all) => (i === 0 || i === all.length - 1 ? part[0] : ''))
  .join('').slice(0, 2).toUpperCase();

/** SK inbox: organization notices that are not addressed to a single youth. */
export const skInbox = (notification, orgId) => notification.orgId === orgId && !notification.youthId;

/** Youth inbox: their own notices only. */
export const youthInbox = (notification, orgId, youthId) => notification.youthId === youthId;

export const subLabel = (org) => {
  if (!org) return 'free';
  if (['active', 'trial'].includes(org.subStatus) && org.expiresIn !== null && org.expiresIn <= 3 && org.expiresIn >= 0) {
    return 'expiring soon';
  }
  return org.subStatus;
};

/* ------------------------------------------------------------------ shell */

function NavSection({ label, items, onNavigate }) {
  return (
    <div className="mb-5">
      <p className="mb-1.5 px-3 text-[10px] font-semibold uppercase tracking-[0.08em] text-white/40">{label}</p>
      <ul className="space-y-0.5">
        {items.map((item) => (
          <li key={item.to + item.label}>
            <NavLink to={item.to} end={item.end} onClick={onNavigate}
              className={({ isActive }) => [
                'group relative flex items-center gap-2.5 rounded-lg py-2 pl-3 pr-2 text-[13px] transition-colors',
                isActive ? 'bg-white/10 font-semibold text-white' : 'text-white/65 hover:bg-white/5 hover:text-white',
              ].join(' ')}>
              {({ isActive }) => (
                <>
                  {isActive && <span className="absolute inset-y-1.5 left-0 w-0.5 rounded-r bg-sun" aria-hidden="true" />}
                  <item.icon size={17} strokeWidth={1.9} className={isActive ? 'text-sun' : 'text-white/45 group-hover:text-white/80'} />
                  <span className="flex-1 truncate">{item.label}</span>
                  {item.badge > 0 && (
                    <span className="rounded-md bg-sun px-1.5 py-0.5 text-[10px] font-bold text-navy-900">{item.badge}</span>
                  )}
                </>
              )}
            </NavLink>
          </li>
        ))}
      </ul>
    </div>
  );
}

function Shell({ sections, contextName, contextMeta }) {
  const [open, setOpen] = useState(false);
  const { user, logout, sessionReady } = useStore();
  if (sessionReady === false) {
    return <div className="grid min-h-full place-items-center text-sm text-ink-muted">Restoring session…</div>;
  }
  const navigate = useNavigate();
  const location = useLocation();

  useEffect(() => { setOpen(false); }, [location.pathname]);

  return (
    <div className="min-h-full">
      {open && <div className="fixed inset-0 z-30 bg-navy-950/50 lg:hidden" onClick={() => setOpen(false)} aria-hidden="true" />}

      <aside className={[
        'fixed inset-y-0 left-0 z-40 flex w-[264px] flex-col bg-navy-900',
        open ? 'flex' : 'hidden lg:flex',
      ].join(' ')}>
        <div className="flex items-center gap-2.5 border-b border-white/10 px-4 py-4">
          <span className="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-sun text-[13px] font-bold text-navy-900">YS</span>
          <span className="min-w-0 flex-1">
            <span className="block truncate text-sm font-semibold text-white">YouthSync</span>
            <span className="block truncate text-[11px] text-white/50">{contextMeta}</span>
          </span>
          <button type="button" onClick={() => setOpen(false)} aria-label="Close navigation"
            className="grid h-8 w-8 place-items-center rounded-lg text-white/60 hover:bg-white/10 lg:hidden">
            <X size={18} />
          </button>
        </div>

        <nav className="flex-1 overflow-y-auto px-2.5 py-4" aria-label="Main">
          {sections.map((section) => (
            <NavSection key={section.label} label={section.label} items={section.items} onNavigate={() => setOpen(false)} />
          ))}
        </nav>

        <div className="border-t border-white/10 p-3">
          <div className="flex items-center gap-2.5 rounded-lg px-2 py-2">
            <span className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-white/10 text-[11px] font-bold text-white">
              {initials(user?.name)}
            </span>
            <span className="min-w-0 flex-1">
              <span className="block truncate text-[13px] font-medium text-white">{user?.name}</span>
              <span className="block truncate text-[11px] text-white/50">{contextName}</span>
            </span>
            <button type="button" onClick={async () => { await logout(); navigate('/'); }} aria-label="Sign out"
              className="grid h-8 w-8 place-items-center rounded-lg text-white/60 hover:bg-white/10 hover:text-white">
              <LogOut size={16} />
            </button>
          </div>
        </div>
      </aside>

      <div className="flex min-h-full flex-col lg:pl-[264px]">
        <header className="sticky top-0 z-20 flex items-center gap-3 border-b border-line bg-white/95 px-4 py-2.5 backdrop-blur sm:px-6 lg:hidden">
          <button type="button" onClick={() => setOpen(true)} aria-label="Open navigation"
            className="grid h-9 w-9 place-items-center rounded-lg border border-line text-ink-muted">
            <Menu size={18} />
          </button>
          <span className="truncate text-sm font-semibold">YouthSync</span>
        </header>

        <Banner />

        <main className="flex-1 px-4 py-6 sm:px-6 lg:px-8">
          <div className="mx-auto w-full max-w-[1180px]">
            <Outlet />
          </div>
        </main>
      </div>

      <Flash />
    </div>
  );
}

function Banner() {
  const { org, user } = useStore();
  if (!org || user.role !== 'sk_official') return null;

  if (subLabel(org) === 'expiring soon') {
    return (
      <div className="border-b border-warning-border bg-warning-bg px-4 py-2 text-xs text-warning sm:px-6 lg:px-8">
        Your {org.subStatus === 'trial' ? 'trial' : 'subscription'} expires in {org.expiresIn} day(s).{' '}
        <Link to="/sk/subscription/plans" className="font-semibold underline">View plans</Link>
      </div>
    );
  }
  if (org.subStatus === 'expired') {
    return (
      <div className="border-b border-danger-border bg-danger-bg px-4 py-2 text-xs text-danger sm:px-6 lg:px-8">
        Your subscription has expired. Records are retained and advanced features are unavailable until renewal.{' '}
        <Link to="/sk/subscription/plans" className="font-semibold underline">Renew subscription</Link>
      </div>
    );
  }
  return null;
}

/* ------------------------------------------------------- role-based shells */

export function SkLayout() {
  const { user, org, db, sessionReady, skLoading, skError, retrySk } = useStore();
  if (sessionReady === false) {
    return <div className="grid min-h-full place-items-center text-sm text-ink-muted">Restoring session…</div>;
  }
  if (!user) return <Navigate to="/login" replace />;
  if (user.role !== 'sk_official') return <Navigate to={user.role === 'system_admin' ? '/admin' : '/youth'} replace />;
  if (user.mustChangePassword) return <Navigate to="/change-password" replace />;

  const unread = db.notifications.filter((n) => skInbox(n, org.id) && !n.read).length;
  const pending = db.applications.filter((a) => a.orgId === org.id
    && ['pending', 'needs_resubmission'].includes(a.status)).length;

  const sections = [
    { label: 'Overview', items: [{ to: '/sk', label: 'Dashboard', icon: LayoutDashboard, end: true }] },
    { label: 'Youth Management', items: [
      { to: '/sk/youth', label: 'Youth Records', icon: Users },
      { to: '/sk/applications', label: 'Applications', icon: ClipboardList, badge: pending },
    ] },
    { label: 'Programs', items: [
      { to: '/sk/programs', label: 'Programs & Events', icon: CalendarDays },
      { to: '/sk/attendance', label: 'Attendance', icon: QrCode },
    ] },
    { label: 'Assistance', items: [{ to: '/sk/assistance', label: 'Assistance Programs', icon: HandHeart }] },
    { label: 'Communication', items: [
      { to: '/sk/notifications', label: 'Notifications', icon: Bell, badge: unread },
      { to: '/sk/outbox', label: 'Outbox', icon: ScrollText },
    ] },
    { label: 'Reports', items: [{ to: '/sk/reports', label: 'Reports', icon: FileBarChart }] },
    { label: 'Organization', items: [
      { to: '/sk/subscription', label: 'Subscription', icon: CreditCard },
      { to: '/sk/billing', label: 'Billing', icon: Wallet },
      { to: '/sk/users', label: 'User Accounts', icon: UserCog },
      { to: '/sk/activity', label: 'Activity Logs', icon: ScrollText },
      { to: '/sk/settings', label: 'Settings', icon: Settings },
    ] },
  ];

  return (
    <>
      {skError && (
        <div className="border-b border-[#F0D3D1] bg-[#FCF3F2] px-4 py-2 text-sm text-[#96201A]">
          {skError}{' '}
          <button type="button" className="underline" onClick={() => retrySk()}>Retry</button>
        </div>
      )}
      {skLoading && (
        <div className="border-b border-line bg-shell px-4 py-2 text-sm text-ink-muted">Loading SK records…</div>
      )}
      <Shell sections={sections} contextName="SK Official" contextMeta={org ? `${org.barangay}, ${org.municipality}` : ''} />
    </>
  );
}

export function YouthLayout() {
  const { user, org, db, sessionReady } = useStore();
  if (sessionReady === false) {
    return <div className="grid min-h-full place-items-center text-sm text-ink-muted">Restoring session…</div>;
  }
  if (!user) return <Navigate to="/login" replace />;
  if (user.role !== 'youth') return <Navigate to={user.role === 'system_admin' ? '/admin' : '/sk'} replace />;
  if (user.mustChangePassword) return <Navigate to="/change-password" replace />;

  const unread = db.notifications.filter((n) => youthInbox(n, user.orgId, user.youthId) && !n.read).length;
  const open = db.applications.filter((a) => a.youthId === user.youthId
    && ['pending', 'needs_resubmission'].includes(a.status)).length;

  const sections = [
    { label: 'Overview', items: [{ to: '/youth', label: 'Dashboard', icon: LayoutDashboard, end: true }] },
    { label: 'My Account', items: [
      { to: '/youth/profile', label: 'My Profile', icon: UserCircle },
      { to: '/youth/qr', label: 'My QR Code', icon: QrCode },
    ] },
    { label: 'Programs', items: [
      { to: '/youth/events', label: 'Available Programs', icon: CalendarDays },
      { to: '/youth/registrations', label: 'My Registrations', icon: ClipboardList },
      { to: '/youth/attendance', label: 'My Attendance', icon: QrCode },
    ] },
    { label: 'Assistance', items: [
      { to: '/youth/assistance', label: 'Available Assistance', icon: HandHeart },
      { to: '/youth/applications', label: 'My Applications', icon: ClipboardList, badge: open },
    ] },
    { label: 'Notifications', items: [{ to: '/youth/notifications', label: 'Notifications', icon: Bell, badge: unread }] },
  ];

  return <Shell sections={sections} contextName="Youth" contextMeta={org ? `${org.barangay}, ${org.municipality}` : ''} />;
}

export function AdminLayout() {
  const { user, orgs, sessionReady } = useStore();
  if (sessionReady === false) {
    return <div className="grid min-h-full place-items-center text-sm text-ink-muted">Restoring session…</div>;
  }
  if (!user) return <Navigate to="/login" replace />;
  if (user.role !== 'system_admin') return <Navigate to={user.role === 'youth' ? '/youth' : '/sk'} replace />;

  const pending = orgs.filter((o) => o.status === 'pending').length;

  const sections = [
    { label: 'Overview', items: [{ to: '/admin', label: 'Dashboard', icon: LayoutDashboard, end: true }] },
    { label: 'Organizations', items: [
      { to: '/admin/organizations', label: 'SK Organizations', icon: Building2, badge: pending },
    ] },
    { label: 'Subscriptions', items: [
      { to: '/admin/subscriptions', label: 'Subscriptions', icon: CreditCard },
      { to: '/admin/payments', label: 'Payments', icon: Wallet },
    ] },
    { label: 'System', items: [
      { to: '/admin/users', label: 'User Accounts', icon: UserCog },
      { to: '/admin/monitoring', label: 'Audit Logs', icon: ScrollText },
    ] },
  ];

  return <Shell sections={sections} contextName="System Administrator" contextMeta="Administration" />;
}

/* --------------------------------------------------------- public wrapper */

export function GuestLayout({ children, wide }) {
  return (
    <div className="flex min-h-full flex-col">
      <header className="border-b border-line bg-white">
        <div className="mx-auto flex max-w-5xl items-center justify-between px-5 py-3.5">
          <Link to="/" className="flex items-center gap-2.5">
            <span className="grid h-9 w-9 place-items-center rounded-lg bg-navy-900 text-[13px] font-bold text-sun">YS</span>
            <span className="text-sm font-semibold">YouthSync</span>
          </Link>
          <Link to="/" className="btn btn-quiet btn-sm">Back to site</Link>
        </div>
      </header>

      <main className="guest-auth mx-auto w-full px-5 py-8 sm:py-10">{children}</main>

      <footer className="border-t border-line py-5 text-center text-xs text-ink-subtle">
        YouthSync · Sangguniang Kabataan management
      </footer>
      <Flash />
    </div>
  );
}

/* ------------------------------------------------------------ page header */

/**
 * One header pattern for every authenticated page: title, optional short
 * subtitle, actions right-aligned on desktop and stacked on mobile.
 */
export function PageHeader({ title, subtitle, actions, breadcrumb }) {
  return (
    <div className="mb-6">
      {breadcrumb && (
        <nav className="mb-2 flex items-center gap-1 text-xs text-ink-subtle" aria-label="Breadcrumb">
          {breadcrumb.map((crumb, index) => (
            <span key={crumb.label} className="flex items-center gap-1">
              {index > 0 && <ChevronRight size={12} />}
              {crumb.to ? <Link to={crumb.to} className="hover:text-navy-700 hover:underline">{crumb.label}</Link>
                : <span className="text-ink-muted">{crumb.label}</span>}
            </span>
          ))}
        </nav>
      )}
      <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div className="min-w-0">
          <h1 className="t-page truncate">{title}</h1>
          {subtitle && <p className="mt-1 t-muted">{subtitle}</p>}
        </div>
        {actions && <div className="flex flex-wrap items-center gap-2 sm:shrink-0">{actions}</div>}
      </div>
    </div>
  );
}
