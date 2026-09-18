import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useStore } from '../../store.jsx';
import { PageHeader } from '../../components/layouts.jsx';
import { Field, Section } from '../../components/ui.jsx';
import { QrScanner } from '../../components/qr.jsx';
import { makeProgramCode } from '../../data/mock.js';

/**
 * Youth scans the code on an activity poster to register themselves.
 * Registering is not attendance — that still needs the SK to scan the youth's pass.
 */
export default function ScanProgram() {
  const { db, user, registerByProgramCode } = useStore();
  const [result, setResult] = useState(null);
  const navigate = useNavigate();

  const open = db.programs.filter((p) => p.orgId === user.orgId && ['published', 'ongoing'].includes(p.status));

  const handle = (code) => setResult(registerByProgramCode(code));

  return (
    <>
      <PageHeader title="Scan a program QR"
        subtitle="Point your camera at the code on the poster, or type it in."
        actions={<Link to="/youth/events" className="btn btn-ghost">Browse instead</Link>} />

      <div className="grid gap-4 lg:grid-cols-2">
        <Section title="Scan">
          <div className="mt-3"><QrScanner onScan={handle} /></div>

          {result && (
            <div className={`mt-4 rounded-card border px-4 py-3 text-sm ${
              result.ok ? 'border-[#BFE3D0] bg-[#F1FAF5] text-[#12664A]' : 'border-[#F0D3D1] bg-[#FCF3F2] text-[#96201A]'}`}>
              <p className="font-semibold">{result.ok ? 'Registration successful' : 'Not registered'}</p>
              <p className="mt-0.5">{result.message}</p>
              {result.ok && (
                <button type="button" className="btn btn-primary mt-3"
                  onClick={() => navigate(`/youth/applications/${result.applicationId}`)}>
                  Open my application
                </button>
              )}
            </div>
          )}
        </Section>

        <Section title="Codes you can try">
          <p className="mt-1 text-sm text-[#5A6C82]">
            On a desktop the camera may be unavailable. Tap a code to fill the field, or type it in the scanner box.
          </p>
          {open.length === 0 ? (
            <p className="mt-3 text-sm text-[#7A889B]">No activities are open for registration right now.</p>
          ) : (
            <ul className="mt-3 space-y-2">
              {open.map((program) => (
                <li key={program.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-line px-3 py-2">
                  <span className="min-w-0">
                    <span className="block truncate text-sm font-medium text-navy-900">{program.name}</span>
                    <span className="block font-mono text-xs text-[#8391A4]">{makeProgramCode(program.id)}</span>
                  </span>
                  <button type="button" className="btn btn-ghost btn-sm" onClick={() => handle(makeProgramCode(program.id))}>
                    Enter code
                  </button>
                </li>
              ))}
            </ul>
          )}
        </Section>
      </div>
    </>
  );
}
