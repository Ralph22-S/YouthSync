import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useStore } from '../../store.jsx';
import { PageHeader } from '../../components/layouts.jsx';
import { Field, Section } from '../../components/ui.jsx';
import { QrScanner } from '../../components/qr.jsx';
import { friendlyApiMessage } from '../../lib/api.js';

/**
 * Youth scans or types the poster registration code.
 * This creates an application. It does not record attendance.
 */
export default function ScanProgram() {
  const { sessionKind, registerByRegistrationCode } = useStore();
  const [mode, setMode] = useState('');
  const [code, setCode] = useState('');
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState(null);
  const navigate = useNavigate();
  const live = sessionKind === 'youth' && typeof registerByRegistrationCode === 'function';

  const submitCode = async (value) => {
    const next = String(value || '').trim();
    if (!next || busy || !live) return;
    setBusy(true);
    setNotice(null);
    try {
      const result = await registerByRegistrationCode(next);
      setNotice({
        ok: true,
        message: result?.message || 'Registration successful.',
        applicationId: result?.application?.id,
      });
      setCode('');
      setMode('');
    } catch (err) {
      const duplicate = err?.code === 'ALREADY_REGISTERED' || err?.status === 409;
      const invalid = err?.code === 'INVALID_REGISTRATION_CODE' || err?.status === 404;
      setNotice({
        ok: false,
        message: duplicate
          ? 'Already registered for this program.'
          : invalid
            ? 'Invalid or unavailable registration code.'
            : friendlyApiMessage(err, 'Registration failed.'),
      });
    } finally {
      setBusy(false);
    }
  };

  return (
    <>
      <PageHeader title="Register for Program/Event"
        subtitle="Scan the registration QR on the poster, or type the registration code. This does not mark attendance."
        actions={<Link to="/youth/events" className="btn btn-ghost">Browse instead</Link>} />

      <Section title="Registration">
        <p className="mt-2 text-sm text-[#4A5B70]">
          Use the Registration QR or Registration Code. The attendance code is a different code and will not register you.
        </p>
        {mode === '' && (
          <div className="mt-3 flex flex-wrap gap-2">
            <button type="button" className="btn btn-primary" disabled={busy || !live} onClick={() => { setNotice(null); setMode('scan'); }}>
              Scan Registration QR
            </button>
            <button type="button" className="btn btn-secondary" disabled={busy || !live} onClick={() => { setNotice(null); setMode('code'); }}>
              Enter Registration Code
            </button>
          </div>
        )}
        {mode === 'scan' && (
          <div className="mt-3">
            <QrScanner
              startOpen
              showManual={false}
              openLabel="Scan Registration QR"
              deniedMessage="Camera access is unavailable. Enter the registration code manually."
              placeholder="REG-XXXXXXXX"
              onScan={submitCode}
            />
            <button type="button" className="btn btn-ghost mt-3" disabled={busy} onClick={() => setMode('code')}>Enter Registration Code</button>
          </div>
        )}
        {mode === 'code' && (
          <form className="mt-3 max-w-md" onSubmit={(e) => { e.preventDefault(); submitCode(code); }}>
            <Field label="Registration Code">
              <input className="field font-mono" value={code} onChange={(e) => setCode(e.target.value)}
                placeholder="REG-XXXXXXXX" disabled={busy} autoComplete="off" />
            </Field>
            <div className="mt-3 flex gap-2">
              <button type="submit" className="btn btn-primary" disabled={busy || !code.trim()}>
                {busy ? 'Registering...' : 'Register'}
              </button>
              <button type="button" className="btn btn-ghost" disabled={busy} onClick={() => setMode('scan')}>Scan Registration QR</button>
            </div>
          </form>
        )}
        {busy && mode === 'scan' && <p className="mt-2 text-sm text-[#5A6C82]">Registering...</p>}
        {notice && (
          <div className={`mt-3 rounded-card border px-4 py-3 text-sm ${
            notice.ok ? 'border-[#BFE3D0] bg-[#F1FAF5] text-[#12664A]' : 'border-[#F0D3D1] bg-[#FCF3F2] text-[#96201A]'
          }`}>
            <p>{notice.message}</p>
            {notice.ok && notice.applicationId && (
              <button type="button" className="btn btn-primary mt-3"
                onClick={() => navigate(`/youth/applications/${notice.applicationId}`)}>
                Open my application
              </button>
            )}
          </div>
        )}
      </Section>
    </>
  );
}
