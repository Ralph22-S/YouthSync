import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useStore } from '../../store.jsx';
import { PageHeader } from '../../components/layouts.jsx';
import { Badge, Empty, Field, Modal, NeedsStoreData, Section, Stat } from '../../components/ui.jsx';
import { QrImage, QrScanner } from '../../components/qr.jsx';
import { age, fullName, youthCode } from '../../data/mock.js';

/**
 * Attendance is a two-step action on purpose.
 * Scanning or manual selection identifies the youth.
 * Only confirmation turns that into Present with a timestamp.
 */
export default function Attendance() {
  // Activity picker, each activity's attendance list and the manual youth picker.
  return <NeedsStoreData keys={['programs', 'registrations', 'youth']}><AttendanceView /></NeedsStoreData>;
}

function AttendanceView() {
  const {
    scoped, db, scanYouthQr, confirmAttendance, markAttendance,
    startAttendanceSession, attendanceSessions, recordManualAttendance, mutating,
    qrLimit, qrUses, qrRemaining, plan,
  } = useStore();

  const activities = scoped('programs')
    .filter((p) => ['published', 'ongoing', 'completed'].includes(p.status))
    .sort((a, b) => String(b.scheduledOn || '').localeCompare(String(a.scheduledOn || '')));

  const [programId, setProgramId] = useState(activities[0]?.id ?? '');
  const [result, setResult] = useState(null);
  const [pending, setPending] = useState(null);
  const [manualQuery, setManualQuery] = useState('');
  const [busy, setBusy] = useState('');
  const [error, setError] = useState('');

  const program = db.programs.find((p) => p.id === Number(programId));
  const session = attendanceSessions?.[Number(programId)] || attendanceSessions?.[programId];
  const rows = db.registrations
    .filter((r) => r.programId === Number(programId) && r.status !== 'cancelled')
    .map((r) => ({ ...r, youth: db.youth.find((y) => y.id === r.youthId) }))
    .filter((r) => r.youth)
    .sort((a, b) => (b.scannedAt || '').localeCompare(a.scannedAt || '') || a.youth.lastName.localeCompare(b.youth.lastName));

  const present = rows.filter((r) => r.attendance === 'present' || r.source === 'self').length;
  const shownStatus = (row) => (row.source === 'self' ? 'present' : (row.attendance || (row.pending ? 'pending' : '')));
  const sourceLabel = (source) => (
    source === 'self' ? 'Self check-in' : source === 'qr' ? 'Youth QR' : source === 'manual' ? 'Manual' : ''
  );
  const query = manualQuery.trim().toLowerCase();
  const youthPool = scoped('youth').filter((y) => !y.archived);
  const manualMatches = (query
    ? youthPool.filter((y) => fullName(y).toLowerCase().includes(query) || youthCode(y).toLowerCase().includes(query))
    : youthPool
  ).slice(0, 8);

  const locked = Boolean(busy) || mutating;

  const handleStart = async () => {
    setError('');
    setResult(null);
    setBusy('starting');
    try {
      await startAttendanceSession(Number(programId));
    } catch (err) {
      setError(err?.message || 'Unable to start the attendance session.');
    } finally {
      setBusy('');
    }
  };

  const handleScan = async (token) => {
    if (locked) return;
    setError('');
    setBusy('scanning');
    try {
      const outcome = await scanYouthQr(token, Number(programId));
      if (outcome?.pending && outcome.registration) {
        setPending(outcome);
        setResult(null);
        return;
      }
      setPending(null);
      setResult(outcome);
    } finally {
      setBusy('');
    }
  };

  const handleManual = async (youth) => {
    if (locked) return;
    setError('');
    setBusy('saving');
    try {
      const outcome = await recordManualAttendance(Number(programId), youth.id);
      setPending(outcome);
      setResult(null);
    } catch (err) {
      setError(err?.message || 'Unable to record manual attendance.');
    } finally {
      setBusy('');
    }
  };

  const handleConfirm = async () => {
    if (!pending?.registration?.id || locked) return;
    setError('');
    setBusy('confirming');
    try {
      const confirmed = await confirmAttendance(pending.registration.id);
      setResult(confirmed?.ok
        ? { ok: true, message: 'Attendance confirmed as present.' }
        : { ok: false, message: confirmed?.message || 'Unable to confirm attendance.' });
      setPending(null);
    } finally {
      setBusy('');
    }
  };

  const handleStatus = async (id, status) => {
    if (!status || locked) return;
    setError('');
    setBusy('saving');
    try {
      const saved = await markAttendance(id, status);
      if (saved === null) setError('Unable to update attendance status.');
    } finally {
      setBusy('');
    }
  };

  if (activities.length === 0) {
    return (
      <>
        <PageHeader title="Attendance" />
        <Empty title="No activity to take attendance for"
          body="Publish an event or program first. Then start an attendance session and scan a Youth QR.">
          <Link to="/sk/programs/new" className="btn btn-primary">Create an activity</Link>
        </Empty>
      </>
    );
  }

  return (
    <>
      <PageHeader title="Attendance"
        subtitle="Start a session, scan the youth's QR, then confirm. Manual attendance uses the same confirmation step."
        actions={qrLimit !== null && (
          <span className="badge bg-shell text-[#4A5B70]">QR scans: {qrUses} / {qrLimit} on {plan.name}</span>
        )} />

      <div className="card mb-4 p-4">
        <Field label="Activity">
          <select className="field" value={programId} disabled={locked}
            onChange={(e) => { setProgramId(e.target.value); setResult(null); setPending(null); setError(''); }}>
            {activities.map((p) => (
              <option key={p.id} value={p.id}>{p.name} — {p.scheduledOn ? new Date(p.scheduledOn).toDateString() : 'No date'}</option>
            ))}
          </select>
        </Field>
      </div>

      {qrLimit !== null && qrRemaining === 0 && (
        <p className="mb-4 rounded-card border border-[#F2DCA8] bg-sun-100 px-4 py-3 text-sm text-[#7A5A05]">
          QR scan limit reached — the {plan.name} plan includes {qrLimit} scan{qrLimit === 1 ? '' : 's'}.{' '}
          <Link to="/sk/subscription/plans" className="font-semibold underline">Upgrade your plan</Link> for more.
          You can still mark attendance manually in the list below.
        </p>
      )}

      {error && (
        <p className="mb-4 rounded-card border border-[#F0D3D1] bg-[#FCF3F2] px-4 py-3 text-sm text-[#96201A]">{error}</p>
      )}

      <div className="mb-4 grid grid-cols-3 gap-3">
        <Stat label="Records" value={rows.length} />
        <Stat label="Present" value={present} />
        <Stat label="Not yet attended" value={rows.length - present} />
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        <div>
          <Section title="Attendance session">
            <button type="button" className="btn btn-primary mt-3" disabled={locked} onClick={handleStart}>
              {busy === 'starting' ? 'Starting...' : 'Start attendance session'}
            </button>
            {session?.publicCode && (
              <div className="mt-4 text-center">
                <p className="label">Attendance Code</p>
                <p className="mt-1 font-mono text-lg font-semibold text-navy-900">{session.publicCode}</p>
                <div className="mt-3 flex justify-center"><QrImage value={session.publicCode} size={180} alt="Attendance QR code" /></div>
                <p className="mt-2 text-xs text-[#8391A4]">Youth can scan this QR or type the code. Active until {session.expiresAt || 'the session closes'}.</p>
              </div>
            )}
          </Section>

          <div className="mt-4">
          <Section title="Scan Youth QR">
            <div className="mt-3">
              <QrScanner onScan={handleScan} placeholder="YSYOUTH-YTH-001" />
            </div>
            {busy === 'scanning' && <p className="mt-2 text-sm text-[#5A6C82]">Scanning...</p>}

            <div className="mt-5 border-t border-line pt-4">
              <p className="label">Manual attendance</p>
              <p className="mb-2 t-help">
                Select a youth in this barangay. Saving creates the attendance record, then you confirm it.
              </p>
              <input className="field" value={manualQuery} onChange={(e) => setManualQuery(e.target.value)}
                placeholder="Search youth" disabled={locked} />
              <div className="mt-2 max-h-56 space-y-1.5 overflow-y-auto">
                {manualMatches.length === 0 ? (
                  <p className="t-help">No youth match that search.</p>
                ) : manualMatches.map((youth) => {
                  const existing = rows.find((r) => r.youthId === youth.id);
                  return (
                    <div key={youth.id} className="flex items-center justify-between gap-2 rounded-lg border border-line px-3 py-2">
                      <span className="min-w-0">
                        <span className="block truncate text-sm font-medium">{fullName(youth)}</span>
                        <span className="block font-mono t-help">{youthCode(youth)}</span>
                      </span>
                      {existing?.attendance === 'present' || existing?.source === 'self'
                        ? <Badge value="present" />
                        : (
                          <button type="button" className="btn btn-secondary btn-sm" disabled={locked}
                            onClick={() => handleManual(youth)}>
                            {busy === 'saving' ? 'Saving...' : 'Mark'}
                          </button>
                        )}
                    </div>
                  );
                })}
              </div>
            </div>

            {result && (
              <div className={`mt-4 rounded-card border px-4 py-3 text-sm ${
                result.ok ? 'border-[#BFE3D0] bg-[#F1FAF5] text-[#12664A]' : 'border-[#F0D3D1] bg-[#FCF3F2] text-[#96201A]'}`}>
                <p className="font-semibold">{result.ok ? 'Attendance recorded' : 'Not recorded'}</p>
                <p className="mt-0.5">{result.message}</p>
              </div>
            )}
          </Section>
          </div>
        </div>

        <div className="lg:col-span-2">
          <Section title={`Attendance — ${program?.name || ''}`}>
            {rows.length === 0 ? (
              <p className="mt-3 text-sm text-[#7A889B]">No attendance records for this activity yet.</p>
            ) : (
              <div className="mt-3 overflow-x-auto">
                <table className="w-full" style={{ minWidth: 680 }}>
                  <thead><tr>
                    <th className="th">Youth</th><th className="th">Youth ID</th><th className="th">Date</th>
                    <th className="th">Time</th><th className="th">Source</th><th className="th">Status</th><th className="th" />
                  </tr></thead>
                  <tbody>
                    {rows.map((r) => (
                      <tr key={r.id} className={r.attendance === 'present' ? 'bg-[#F6FBF8]' : ''}>
                        <td className="td">
                          <Link to={`/sk/youth/${r.youth.id}`} className="font-medium text-navy-900 underline">{fullName(r.youth)}</Link>
                          <span className="block text-xs text-[#8391A4]">{age(r.youth.birthDate)} years old</span>
                        </td>
                        <td className="td font-mono text-xs">{youthCode(r.youth)}</td>
                        <td className="td">{program?.scheduledOn ? new Date(program.scheduledOn).toDateString() : '—'}</td>
                        <td className="td">{r.scannedAt || '—'}</td>
                        <td className="td">{sourceLabel(r.source) || '—'}</td>
                        <td className="td">
                          <Badge value={shownStatus(r) || 'pending'} />
                        </td>
                        <td className="td">
                          {r.source === 'self' ? (
                            <span className="text-xs text-[#5A6C82]">Recorded by the Youth</span>
                          ) : (
                            <select className="field !py-1 !text-xs" value={r.attendance || ''} disabled={locked}
                              onChange={(e) => handleStatus(r.id, e.target.value)}>
                              <option value="">Not marked</option>
                              {['present', 'absent', 'excused'].map((s) => <option key={s} value={s}>{s[0].toUpperCase() + s.slice(1)}</option>)}
                            </select>
                          )}
                          {busy === 'saving' && r.source !== 'self' && <span className="mt-1 block text-xs text-[#5A6C82]">Saving...</span>}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </Section>
        </div>
      </div>

      <Modal open={Boolean(pending)} title="Confirm attendance"
        description={pending ? `${fullName(pending.youth)} — ${pending.program?.name || program?.name || 'this activity'}.` : ''}
        onClose={() => { if (!locked) setPending(null); }}
        footer={<>
          <button type="button" className="btn btn-ghost" disabled={locked} onClick={() => setPending(null)}>Cancel</button>
          <button type="button" className="btn btn-accent" disabled={locked} onClick={handleConfirm}>
            {busy === 'confirming' ? 'Confirming...' : 'Confirm attendance'}
          </button>
        </>}>
        {pending && (
          <dl className="space-y-2 text-sm">
            <div className="flex justify-between"><dt className="text-[#5A6C82]">Youth</dt><dd className="font-semibold text-navy-900">{fullName(pending.youth)}</dd></div>
            <div className="flex justify-between"><dt className="text-[#5A6C82]">Youth ID</dt><dd className="font-mono text-navy-900">{youthCode(pending.youth)}</dd></div>
            <div className="flex justify-between"><dt className="text-[#5A6C82]">Activity</dt><dd className="font-semibold text-navy-900">{pending.program?.name || program?.name}</dd></div>
            <div className="flex justify-between"><dt className="text-[#5A6C82]">Status</dt><dd><Badge value="pending" /></dd></div>
          </dl>
        )}
      </Modal>
    </>
  );
}
