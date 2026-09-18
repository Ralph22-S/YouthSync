import { useEffect, useId, useRef, useState } from 'react';
import QRCode from 'qrcode';

/** Renders the youth's attendance pass as a scannable QR image. */
export function QrImage({ value, size = 220 }) {
  const [src, setSrc] = useState('');

  useEffect(() => {
    let alive = true;
    QRCode.toDataURL(value, { width: size, margin: 1, color: { dark: '#0B2038', light: '#FFFFFF' } })
      .then((url) => { if (alive) setSrc(url); })
      .catch(() => { if (alive) setSrc(''); });
    return () => { alive = false; };
  }, [value, size]);

  if (!src) return <div className="grid place-items-center rounded-lg border border-line bg-shell text-xs text-[#8391A4]" style={{ width: size, height: size }}>Generating…</div>;
  return <img src={src} alt="Attendance QR code" width={size} height={size} className="rounded-lg border border-line bg-white p-2" />;
}

const cameraMessage = (err) => {
  const blob = `${err?.name || ''} ${err?.message || err || ''}`;
  if (/NotAllowedError|PermissionDeniedError|Permission denied|NotAllowed/i.test(blob)) {
    return 'Camera permission was blocked. Allow the camera for this localhost site in the browser settings, then try Open camera scanner again. You can also type the code below.';
  }
  if (/NotFoundError|DevicesNotFoundError|Requested device not found/i.test(blob)) {
    return 'No camera was found on this device. Use the manual field below.';
  }
  if (/NotReadableError|TrackStartError|Could not start video source|in use/i.test(blob)) {
    return 'The camera is already in use by another app or browser tab. Close that, then try again, or type the code below.';
  }
  if (/OverconstrainedError|ConstraintNotSatisfiedError/i.test(blob)) {
    return 'This camera could not be opened with the requested settings. Try again, or type the code below.';
  }
  if (/TypeError/i.test(blob) && /getUserMedia|mediaDevices/i.test(blob)) {
    return 'This browser cannot access the camera. Use the manual field below.';
  }
  if (/SecurityError|insecure|https/i.test(blob)) {
    return 'Camera access needs a secure context (localhost or HTTPS). This page should work on http://localhost. Use the manual field below if it still fails.';
  }
  if (typeof window !== 'undefined' && window.isSecureContext === false) {
    return 'The camera only works on localhost or HTTPS. Use the manual field below instead.';
  }
  return 'The camera could not be opened. Allow camera access for this site, or type the code below.';
};

const openCameraStream = async () => {
  const attempts = [
    { audio: false, video: { facingMode: { ideal: 'environment' } } },
    { audio: false, video: { facingMode: { ideal: 'user' } } },
    { audio: false, video: true },
  ];
  let lastError;
  for (const constraints of attempts) {
    try {
      return await navigator.mediaDevices.getUserMedia(constraints);
    } catch (err) {
      lastError = err;
      if (err?.name === 'NotAllowedError' || err?.name === 'PermissionDeniedError') throw err;
    }
  }
  throw lastError || new Error('The camera could not be opened.');
};

const releaseStream = (stream) => {
  if (!stream) return;
  stream.getTracks().forEach((track) => {
    try { track.stop(); } catch { /* already stopped */ }
  });
};

const stopScanner = async (scanner) => {
  if (!scanner) return;
  try {
    await scanner.stop();
  } catch {
    /* already stopped */
  }
  try {
    await scanner.clear();
  } catch {
    /* region already cleared */
  }
};

/**
 * Camera scanner with a manual-entry fallback.
 * Camera is requested only after "Open camera scanner". localhost is a secure context.
 */
export function QrScanner({ onScan, placeholder = 'YSYOUTH-YTH-001' }) {
  const readerId = `qr-reader-${useId().replace(/:/g, '')}`;
  const [active, setActive] = useState(false);
  const [error, setError] = useState('');
  const [manual, setManual] = useState('');
  const instance = useRef(null);
  const lastToken = useRef('');
  const onScanRef = useRef(onScan);
  onScanRef.current = onScan;

  useEffect(() => {
    if (!active) return undefined;
    let cancelled = false;

    const run = async () => {
      if (!navigator.mediaDevices?.getUserMedia) {
        setError('This browser cannot access the camera. Use the manual field below.');
        setActive(false);
        return;
      }
      if (window.isSecureContext === false) {
        setError('The camera only works on localhost or HTTPS. Use the manual field below instead.');
        setActive(false);
        return;
      }

      try {
        const { Html5Qrcode } = await import('html5-qrcode');
        if (cancelled) return;

        const region = document.getElementById(readerId);
        if (!region) {
          throw new Error('Scanner preview is not ready.');
        }

        const probe = await openCameraStream();
        const deviceId = probe.getVideoTracks()[0]?.getSettings()?.deviceId;
        releaseStream(probe);
        if (cancelled) return;
        await new Promise((resolve) => setTimeout(resolve, 150));
        if (cancelled) return;

        const scanner = new Html5Qrcode(readerId);
        instance.current = scanner;
        const config = {
          fps: 10,
          qrbox: (width, height) => {
            const size = Math.max(120, Math.min(240, Math.floor(Math.min(width, height) * 0.75)));
            return { width: size, height: size };
          },
        };
        const onDecoded = (decoded) => {
          if (decoded === lastToken.current) return;
          lastToken.current = decoded;
          onScanRef.current(decoded);
          setTimeout(() => { lastToken.current = ''; }, 2500);
        };

        const camera = deviceId ? { deviceId: { exact: deviceId } } : { facingMode: { ideal: 'environment' } };
        await scanner.start(camera, config, onDecoded, () => {});
        if (cancelled) {
          await stopScanner(scanner);
          instance.current = null;
        }
      } catch (err) {
        if (cancelled) return;
        instance.current = null;
        setActive(false);
        setError(cameraMessage(err));
      }
    };

    run();

    return () => {
      cancelled = true;
      const scanner = instance.current;
      instance.current = null;
      stopScanner(scanner);
    };
  }, [active, readerId]);

  return (
    <div>
      <div id={readerId} className={`overflow-hidden rounded-lg border border-line bg-black ${active ? 'block min-h-[240px]' : 'hidden'}`} />

      <div className="mt-3 flex gap-2">
        {active
          ? <button type="button" className="btn btn-ghost" onClick={() => setActive(false)}>Stop camera</button>
          : <button type="button" className="btn btn-primary" onClick={() => { setError(''); setActive(true); }}>Open camera scanner</button>}
      </div>

      {error && <p className="mt-2 rounded-lg border border-[#F2DCA8] bg-sun-100 px-3 py-2 text-xs text-[#7A5A05]">{error}</p>}

      <form className="mt-4 border-t border-line pt-4" onSubmit={(e) => { e.preventDefault(); if (manual.trim()) { onScan(manual.trim()); setManual(''); } }}>
        <label className="label">Or type the code shown under the QR</label>
        <div className="flex gap-2">
          <input className="field font-mono" value={manual} onChange={(e) => setManual(e.target.value)} placeholder={placeholder} />
          <button type="submit" className="btn btn-ghost">Check in</button>
        </div>
      </form>
    </div>
  );
}
