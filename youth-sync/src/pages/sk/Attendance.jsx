import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useStore } from '../../store.jsx';
import { PageHeader } from '../../components/layouts.jsx';
import { Badge, Empty, Field, Modal, Section, Stat } from '../../components/ui.jsx';
import { QrScanner } from '../../components/qr.jsx';
import { age, fullName, youthCode, makeYouthToken } from '../../data/mock.js';

/**
 * Attendance is a two-step action on purpose.
 * Scanning identifies the youth and checks their registration.
 * Only the confirmation step turns that into Present with a timestamp.
 */
export default function Attendance() {
  const {
    scoped, db, scanYouthQr, confirmAttendance, markAttendance,
    qrLimit, qrUses, qrRemaining, plan,
  } = useStore();

  const activities = scoped('programs')
    .filter((p) => ['published', 'ongoing', 'completed'].includes(p.status))
    .sort((a, b) => String(b.scheduledOn || '').localeCompare(String(a.scheduledOn || '')));

  const [programId, setProgramId] = useState(activities[0]?.id ?? '');
  const [result, setResult] = useState(null);
  const [pending, setPending] = useState(null);
  const [manualQuery, setManualQuery] = useState('');

  const program = db.programs.find((p) => p.id === Number(programId));
  const rows = db.registrations
    .filter((r) => r.programId === Number(programId) && r.status !== 'cancelled')
    .map((r) => ({ ...r, youth: db.youth.find((y) => y.id === r.youthId) }))
    .filter((r) => r.youth)
    .sort((a, b) => (b.scannedAt || '').localeCompare(a.scannedAt || '') || a.youth.lastName.localeCompare(b.youth.lastName));

  const present = rows.filter((r) => r.attendance === 'present').length;
  const manualMatches = manualQuery.trim()
    ? rows.filter((r) => fullName(r.youth).toLowerCase().includes(manualQuery.trim().toLowerCase())).slice(0, 8)
    : rows.filter((r) => r.attendance !== 'present').slice(0, 6);

  const handleScan = async (token) => {
    const outcome = await scanYouthQr(token, Number(programId));
    if (outcome?.pending) { setPending(outcome); setResult(null); return; }
    setPending(null);
    setResult(outcome);
  };

  if (activities.length === 0) {
    return (
      <>
        <PageHeader title="Attendance" />
        <Empty title="No activity to take attendance for"
          body="Publish an event or program first. Youth register for it, then you scan their Youth QR here.">
          <Link to="/sk/programs/new" className="btn btn-primary">Create an activity</Link>
        </Empty>
      </>
    );
  }

  return (
    <>
      <PageHeader title="Attendance"
        subtitle="Pick the activity, scan the youth's QR, then confirm. Registered is not the same as present."
        actions={qrLimit !== null && (
          <span className="badge bg-shell text-[#4A5B70]">QR scans: {qrUses} / {qrLimit} on {plan.name}</span>
        )} />

      <div className="card mb-4 p-4">
        <Field label="Activity" hint="A youth must be registered for this activity before attendance can be recorded.">
          <select className="field" value={programId}
            onChange={(e) => { setProgramId(e.target.value); setResult(null); setPending(null); }}>
            {activities.map((p) => (
              <option key={p.id} value={p.id}>{p.name} — {new Date(p.scheduledOn).toDateString()}</option>
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

      <div className="mb-4 grid grid-cols-3 gap-3">
        <Stat label="Registered" value={rows.length} />
        <Stat label="Present" value={present} />
        <Stat label="Not yet attended" value={rows.length - present} />
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        <div>
          <Section title="Scan Youth QR">
            <div className="mt-3"><QrScanner onScan={handleScan} placeholder="YSYOUTH-YTH-001" /></div>

            <div className="mt-5 border-t border-line pt-4">
              <p className="label">Manual attendance</p>
              <p className="mb-2 t-help">
                For registered youth without an account or QR code, or when a camera is unavailable.
              </p>
              <input className="field" value={manualQuery} onChange={(e) => setManualQuery(e.target.value)}
                placeholder="Search registered youth" />
              <div className="mt-2 max-h-56 space-y-1.5 overflow-y-auto">
                {manualMatches.length === 0 ? (
                  <p className="t-help">No registered youth match that search.</p>
                ) : manualMatches.map((r) => (
                  <div key={r.id} className="flex items-center justify-between gap-2 rounded-lg border border-line px-3 py-2">
                    <span className="min-w-0">
                      <span className="block truncate text-sm font-medium">{fullName(r.youth)}</span>
                      <span className="block font-mono t-help">{youthCode(r.youth)}</span>
                    </span>
                    {r.attendance === 'present'
                      ? <Badge value="present" />
                      : <button type="button" className="btn btn-secondary btn-sm"
                          onClick={() => setPending({ youth: r.youth, registration: r, program })}>Confirm</button>}
                  </div>
                ))}
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

        <div className="lg:col-span-2">
          <Section title={`Attendance — ${program?.name || ''}`}>
            {rows.length === 0 ? (
              <p className="mt-3 text-sm text-[#7A889B]">No one has registered for this activity yet.</p>
            ) : (
              <div className="mt-3 overflow-x-auto">
                <table className="w-full" style={{ minWidth: 680 }}>
                  <thead><tr>
                    <th className="th">Youth</th><th className="th">Youth ID</th><th className="th">Date</th>
                    <th className="th">Time scanned</th><th className="th">Status</th><th className="th" />
                  </tr></thead>
                  <tbody>
                    {rows.map((r) => (
                      <tr key={r.id} className={r.attendance === 'present' ? 'bg-[#F6FBF8]' : ''}>
                        <td className="td">
                          <Link to={`/sk/youth/${r.youth.id}`} className="font-medium text-navy-900 underline">{fullName(r.youth)}</Link>
                          <span className="block text-xs text-[#8391A4]">{age(r.youth.birthDate)} years old</span>
                        </td>
                        <td className="td font-mono text-xs">{youthCode(r.youth)}</td>
                        <td className="td">{program && new Date(program.scheduledOn).toDateString()}</td>
                        <td className="td">{r.scannedAt || '—'}</td>
                        <td className="td">
                          <Badge value={r.attendance === 'present' ? 'present' : r.attendance || 'not yet attended'} />
                        </td>
                        <td className="td">
                          <select className="field !py-1 !text-xs" value={r.attendance || ''}
                            onChange={(e) => markAttendance(r.id, e.target.value)}>
                            <option value="">Not marked</option>
                            {['present', 'absent', 'excused'].map((s) => <option key={s} value={s}>{s[0].toUpperCase() + s.slice(1)}</option>)}
                          </select>
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
        description={pending ? `${fullName(pending.youth)} — ${pending.program?.name || 'this activity'}.` : ''}
        onClose={() => setPending(null)}
        footer={<>
          <button type="button" className="btn btn-ghost" onClick={() => setPending(null)}>Cancel</button>
          <button type="button" className="btn btn-accent"
            onClick={async () => {
              const confirmed = await confirmAttendance(pending.registration.id);
              setResult(confirmed);
              setPending(null);
            }}>
            Confirm attendance
          </button>
        </>}>
        {pending && (
          <dl className="space-y-2 text-sm">
            <div className="flex justify-between"><dt className="text-[#5A6C82]">Youth</dt><dd className="font-semibold text-navy-900">{fullName(pending.youth)}</dd></div>
            <div className="flex justify-between"><dt className="text-[#5A6C82]">Youth ID</dt><dd className="font-mono text-navy-900">{youthCode(pending.youth)}</dd></div>
            <div className="flex justify-between"><dt className="text-[#5A6C82]">Activity</dt><dd className="font-semibold text-navy-900">{pending.program.name}</dd></div>
            <div className="flex justify-between"><dt className="text-[#5A6C82]">Registration</dt><dd><Badge value="registered" /></dd></div>
          </dl>
        )}
      </Modal>
    </>
  );
}
