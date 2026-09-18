import { useEffect, useRef, useState } from 'react';
import { MAX_UPLOAD_BYTES } from '../data/mock.js';

const readable = (bytes) => (bytes > 1024 * 1024 ? `${(bytes / 1024 / 1024).toFixed(1)} MB` : `${Math.round(bytes / 1024)} KB`);

/** Shrinks a capture or upload so a data URL fits comfortably in localStorage. */
function downscale(dataUrl, maxSide = 640) {
  return new Promise((resolve) => {
    const image = new Image();
    image.onload = () => {
      const scale = Math.min(1, maxSide / Math.max(image.width, image.height));
      const canvas = document.createElement('canvas');
      canvas.width = Math.round(image.width * scale);
      canvas.height = Math.round(image.height * scale);
      const context = canvas.getContext('2d');
      if (!context) { resolve(dataUrl); return; }
      context.drawImage(image, 0, 0, canvas.width, canvas.height);
      resolve(canvas.toDataURL('image/jpeg', 0.82));
    };
    image.onerror = () => resolve(dataUrl);
    image.src = dataUrl;
  });
}

/**
 * Profile photo: upload a file, or take one with the device camera where the
 * browser allows it. The form keeps working if the camera is unavailable.
 */
export default function PhotoField({ value, onChange, onRemove, label = 'Profile photo' }) {
  const input = useRef(null);
  const video = useRef(null);
  const stream = useRef(null);
  const [camera, setCamera] = useState(false);
  const [error, setError] = useState('');

  const stopCamera = () => {
    stream.current?.getTracks().forEach((track) => track.stop());
    stream.current = null;
    setCamera(false);
  };

  useEffect(() => () => stopCamera(), []);

  const handleFile = (event) => {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;

    if (!file.type.startsWith('image/')) { setError('Choose an image file.'); return; }
    if (file.size > MAX_UPLOAD_BYTES) {
      setError(`That photo is ${readable(file.size)}. The limit is ${readable(MAX_UPLOAD_BYTES)}.`);
      return;
    }

    const reader = new FileReader();
    reader.onerror = () => setError('That photo could not be read. Try another one.');
    reader.onload = async () => {
      setError('');
      const dataUrl = await downscale(reader.result);
      onChange({ name: file.name, type: 'image/jpeg', size: dataUrl.length, dataUrl });
    };
    reader.readAsDataURL(file);
  };

  const startCamera = async () => {
    setError('');
    if (!navigator.mediaDevices?.getUserMedia) {
      setError('This browser has no camera access. Use Upload photo instead.');
      return;
    }
    try {
      stream.current = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' } });
      setCamera(true);
      // The element only exists once `camera` is true.
      setTimeout(() => { if (video.current) video.current.srcObject = stream.current; }, 0);
    } catch {
      setError('The camera could not be opened. Check permissions, or use Upload photo instead.');
    }
  };

  const capture = async () => {
    const element = video.current;
    if (!element) return;

    const canvas = document.createElement('canvas');
    canvas.width = element.videoWidth || 480;
    canvas.height = element.videoHeight || 480;
    canvas.getContext('2d')?.drawImage(element, 0, 0, canvas.width, canvas.height);

    const dataUrl = await downscale(canvas.toDataURL('image/jpeg', 0.85));
    onChange({ name: `photo-${Date.now()}.jpg`, type: 'image/jpeg', size: dataUrl.length, dataUrl });
    stopCamera();
  };

  return (
    <div>
      <p className="label">{label}</p>
      <input ref={input} type="file" accept="image/*" capture="user" className="hidden" onChange={handleFile} />

      {camera ? (
        <div className="rounded-lg border border-line p-3">
          <video ref={video} autoPlay playsInline muted className="w-full rounded-lg bg-black" />
          <div className="mt-3 flex gap-2">
            <button type="button" className="btn btn-primary flex-1" onClick={capture}>Capture</button>
            <button type="button" className="btn btn-ghost" onClick={stopCamera}>Cancel</button>
          </div>
        </div>
      ) : value?.dataUrl ? (
        <div className="flex flex-wrap items-center gap-3 rounded-lg border border-line bg-shell p-3">
          <img src={value.dataUrl} alt="Profile" className="h-20 w-20 rounded-lg border border-line object-cover" />
          <div className="min-w-0 flex-1">
            <p className="truncate text-sm font-medium text-navy-900">{value.name}</p>
            <p className="text-xs text-[#8391A4]">Preview — save the form to keep it.</p>
          </div>
          <div className="flex flex-wrap gap-2">
            <button type="button" className="btn btn-ghost btn-sm" onClick={() => input.current?.click()}>Replace</button>
            <button type="button" className="btn btn-ghost btn-sm" onClick={startCamera}>Retake</button>
            {onRemove && <button type="button" className="btn btn-danger btn-sm" onClick={onRemove}>Remove</button>}
          </div>
        </div>
      ) : (
        <div className="flex flex-wrap gap-2">
          <button type="button" className="btn btn-ghost flex-1" onClick={() => input.current?.click()}>Upload photo</button>
          <button type="button" className="btn btn-ghost flex-1" onClick={startCamera}>Take photo</button>
        </div>
      )}

      {error && <p className="mt-2 text-xs font-medium text-[#96201A]">{error}</p>}
    </div>
  );
}
