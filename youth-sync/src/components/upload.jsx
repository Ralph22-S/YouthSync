import { useRef, useState } from 'react';
import { MAX_UPLOAD_BYTES } from '../data/mock.js';

const readable = (bytes) => (bytes > 1024 * 1024 ? `${(bytes / 1024 / 1024).toFixed(1)} MB` : `${Math.round(bytes / 1024)} KB`);

const matches = (type, accepts) => accepts.split(',').some((rule) => {
  const pattern = rule.trim();
  if (pattern.endsWith('/*')) return type.startsWith(pattern.slice(0, -1));
  return type === pattern;
});

/**
 * Upload a photo or document for one requirement.
 * Shows a preview, validates type and size, and allows replace or remove.
 */
export default function Upload({ accepts = 'image/*,application/pdf', value, onChange, onRemove, disabled, label = 'Upload photo' }) {
  const input = useRef(null);
  const [error, setError] = useState('');

  const handle = (event) => {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;

    if (!matches(file.type, accepts)) {
      setError(`That file type is not accepted here. Allowed: ${accepts.replace(/image\/\*/, 'images').replace(/application\/pdf/, 'PDF')}.`);
      return;
    }
    if (file.size > MAX_UPLOAD_BYTES) {
      setError(`That file is ${readable(file.size)}. The limit is ${readable(MAX_UPLOAD_BYTES)} — try a smaller photo.`);
      return;
    }

    const reader = new FileReader();
    reader.onerror = () => setError('The file could not be read. Try again.');
    reader.onload = () => {
      setError('');
      // `file` is the raw File: the API store posts it, the mock store ignores it.
      onChange({ name: file.name, type: file.type, size: file.size, dataUrl: reader.result, file });
    };
    reader.readAsDataURL(file);
  };

  return (
    <div>
      <input ref={input} type="file" accept={accepts} className="hidden" onChange={handle} disabled={disabled} />

      {value ? (
        <div className="flex flex-wrap items-center gap-3 rounded-lg border border-line bg-shell p-3">
          {value.dataUrl && value.type?.startsWith('image/') ? (
            <img src={value.dataUrl} alt={value.name} className="h-16 w-16 rounded-lg border border-line object-cover" />
          ) : (
            <span className="grid h-16 w-16 place-items-center rounded-lg border border-line bg-white text-xs font-semibold text-[#5A6C82]">
              {value.type === 'application/pdf' ? 'PDF' : 'FILE'}
            </span>
          )}
          <div className="min-w-0 flex-1">
            <p className="truncate text-sm font-medium text-navy-900">{value.name}</p>
            <p className="text-xs text-[#8391A4]">{value.size ? readable(value.size) : 'Uploaded'}</p>
          </div>
          {!disabled && (
            <div className="flex gap-2">
              <button type="button" className="btn btn-ghost btn-sm" onClick={() => input.current?.click()}>Replace</button>
              {onRemove && <button type="button" className="btn btn-danger btn-sm" onClick={onRemove}>Remove</button>}
            </div>
          )}
        </div>
      ) : (
        <button type="button" className="btn btn-ghost w-full" onClick={() => input.current?.click()} disabled={disabled}>
          {label}
        </button>
      )}

      {error && <p className="mt-2 text-xs font-medium text-[#96201A]">{error}</p>}
      {!value && !error && (
        <p className="mt-1 text-xs text-[#8391A4]">
          Accepted: {accepts.replace('image/*', 'JPG, PNG, HEIC').replace('application/pdf', 'PDF')} · up to {readable(MAX_UPLOAD_BYTES)}
        </p>
      )}
    </div>
  );
}
